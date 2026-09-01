<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberStatus;
use App\Enums\ParticipationMemberStatus;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Affectation des membres à une collecte.
 *
 * Une affectation crée une **dette**. Trois règles en découlent, et aucune
 * n'est cosmétique.
 *
 * 1. **Le montant est figé à l'affectation.** Relever le tarif d'une collecte
 *    en cours ne réécrit pas les lignes déjà créées. Sans cela, un
 *    encaissement de 5 000 FCFA apparaîtrait comme partiel sur une dette
 *    rétroactivement portée à 7 500, et personne ne saurait expliquer l'écart.
 *
 * 2. **On ne supprime pas une ligne qui a reçu de l'argent.** On l'annule.
 *    Supprimer laisserait des paiements orphelins, c'est-à-dire de l'argent
 *    encaissé sans dette correspondante — exactement ce qu'un contrôle en
 *    assemblée générale ne pardonne pas.
 *
 * 3. **L'affectation est idempotente.** Relancer « ajouter tous les membres
 *    actifs » ne crée pas de doublon et ne réinitialise aucune ligne
 *    existante. Le bureau doit pouvoir rattraper un oubli sans crainte.
 */
final class ParticipationAssignmentService
{
    /**
     * Rattache des membres à la collecte.
     *
     * @param  list<string>|null  $memberUuids  `null` = tous les membres actifs
     * @param  int|null  $amount  montant individualisé, sinon celui de la campagne
     * @return array{created: int, skipped: int}
     */
    public function assign(
        Participation $participation,
        ?array $memberUuids = null,
        ?int $amount = null,
        ?User $collector = null,
    ): array {
        if (! $participation->status->acceptsAssignments()) {
            throw new RuntimeException(
                "Cette collecte est {$participation->status->label()} : on n'y rattache plus de membre."
            );
        }

        $members = $this->resolveMembers($memberUuids);

        if ($members->isEmpty()) {
            return ['created' => 0, 'skipped' => 0];
        }

        return DB::transaction(function () use ($participation, $members, $amount, $collector): array {
            // Les lignes déjà présentes ne sont pas touchées : réaffecter ne
            // doit ni dupliquer, ni remettre à zéro un encaissement.
            $existing = ParticipationMember::query()
                ->where('participation_id', $participation->id)
                ->pluck('member_id')
                ->all();

            $existing = array_flip($existing);
            $created = 0;
            $skipped = 0;

            foreach ($members as $member) {
                if (isset($existing[$member->id])) {
                    $skipped++;

                    continue;
                }

                ParticipationMember::create([
                    'participation_id' => $participation->id,
                    'member_id' => $member->id,
                    // Copie figée. Voir le commentaire de classe.
                    'expected_amount' => $amount ?? $participation->expected_amount,
                    'paid_amount' => 0,
                    'status' => ParticipationMemberStatus::Unpaid,
                    'assigned_collector_id' => $collector?->id,
                ]);

                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Retire un membre de la collecte.
     *
     * Tant que rien n'a été encaissé, la ligne disparaît : c'est une erreur de
     * saisie, pas un fait comptable. Dès qu'un franc a été reçu, elle est
     * ANNULÉE et conservée — l'argent encaissé doit rester rattaché à quelque
     * chose.
     */
    public function remove(ParticipationMember $line): string
    {
        if ($line->paid_amount > 0) {
            $line->update(['status' => ParticipationMemberStatus::Cancelled]);

            return 'cancelled';
        }

        $line->delete();

        return 'deleted';
    }

    /**
     * Dispense un membre sans le retirer.
     *
     * Distinct du retrait : le bureau veut pouvoir montrer QUI a été dispensé
     * et ne pas se voir demander chaque année pourquoi cette personne manque
     * de la liste.
     */
    public function exempt(ParticipationMember $line, ?string $note = null): void
    {
        $line->update([
            'status' => ParticipationMemberStatus::Cancelled,
            'note' => $note ?? $line->note,
        ]);
    }

    /** Change le montant attendu d'UNE ligne, sans toucher aux autres. */
    public function setAmount(ParticipationMember $line, int $amount): void
    {
        if ($amount < $line->paid_amount) {
            throw new RuntimeException(
                'Le montant attendu ne peut pas être inférieur à ce qui a déjà été encaissé.'
            );
        }

        $line->update(['expected_amount' => $amount]);

        // Le statut suit : passer l'attendu sous le montant déjà versé doit
        // faire basculer la ligne en « payé », pas la laisser « partielle ».
        $line->recalculate();
    }

    public function setCollector(ParticipationMember $line, ?User $collector): void
    {
        $line->update(['assigned_collector_id' => $collector?->id]);
    }

    /* ---------------------------------------------------------------------- */

    /**
     * @param  list<string>|null  $memberUuids
     * @return Collection<int, Member>
     */
    private function resolveMembers(?array $memberUuids): Collection
    {
        if ($memberUuids === null) {
            // Seuls les membres ACTIFS : appeler un ancien membre à cotiser
            // gonflerait l'attendu d'une somme que personne ne versera, et
            // fausserait le « reste à collecter » du tableau de bord.
            return Member::query()->where('status', MemberStatus::Active)->get();
        }

        return Member::query()->whereIn('uuid', $memberUuids)->get();
    }
}
