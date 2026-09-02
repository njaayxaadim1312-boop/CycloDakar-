<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ParticipationMemberStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\CashAccount;
use App\Models\ParticipationMember;
use App\Models\Payment;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Encaisser, et annuler un encaissement.
 *
 * TROIS PROTECTIONS, ET ELLES NE SE NÉGOCIENT PAS
 *
 * 1. **L'IDEMPOTENCE.** Un collecteur encaisse au bord du Lac Rose, sans
 *    réseau : la requête part, la réponse se perd, le téléphone réessaie.
 *    Sans clé d'idempotence, le membre paie deux fois et la caisse enfle. La
 *    clé est vérifiée d'abord ; si elle est déjà connue, le paiement existant
 *    est renvoyé tel quel, sans rien écrire. Et parce que deux requêtes
 *    vraiment simultanées passeraient toutes les deux cette vérification,
 *    c'est l'index UNIQUE de la base qui tranche en dernier ressort.
 *
 * 2. **LE MONTANT NE PEUT PAS DÉPASSER LE RESTE DÛ.** Un membre qui doit
 *    5 000 FCFA ne peut pas en verser 50 000 par une faute de frappe sur un
 *    téléphone tenu d'une main. Le reste dû est relu SOUS VERROU, sinon deux
 *    collecteurs encaissant en même temps le solderaient chacun intégralement.
 *
 * 3. **LE STATUT EST DÉRIVÉ.** `paid_amount` et `status` se recalculent depuis
 *    la somme réelle des paiements non annulés. Ils ne sont jamais reçus du
 *    client : les accepter en entrée reviendrait à laisser quiconque se
 *    déclarer à jour de cotisation.
 *
 * L'ORDRE DU VERROU compte. La caisse est verrouillée AVANT la ligne de dette,
 * toujours dans ce sens. Deux chemins qui verrouilleraient dans l'ordre
 * inverse finiraient par se bloquer mutuellement un jour de forte collecte.
 */
final class PaymentService
{
    public function __construct(
        private readonly CashLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Enregistre un encaissement.
     *
     * @return array{payment: Payment, replayed: bool}
     */
    public function collect(
        ParticipationMember $line,
        int $amount,
        PaymentMethod $method,
        string $idempotencyKey,
        User $collector,
        ?string $reference = null,
        ?string $note = null,
        ?string $paidOn = null,
    ): array {
        // Hors transaction : un rejeu doit répondre sans même en ouvrir une,
        // et c'est le cas le plus fréquent d'une reprise réseau.
        $existant = Payment::where('idempotency_key', $idempotencyKey)->first();

        if ($existant !== null) {
            return ['payment' => $existant, 'replayed' => true];
        }

        $participation = $line->participation;

        if (! $participation->status->acceptsPayments()) {
            throw new DomainException(
                "La collecte « {$participation->name} » est « {$participation->status->label()} » : "
                .'aucun encaissement ne peut y être enregistré.'
            );
        }

        if ($line->status === ParticipationMemberStatus::Cancelled) {
            throw new DomainException(
                'Ce membre a été dispensé de cette collecte : il ne doit plus rien.'
            );
        }

        return DB::transaction(function () use (
            $line, $amount, $method, $idempotencyKey, $collector, $reference, $note, $paidOn
        ): array {
            // 1. La caisse d'abord. Ce verrou sérialise TOUS les
            //    encaissements : il rend juste le solde figé sur l'écriture,
            //    et il rend sûre l'attribution du numéro de reçu.
            $caisse = CashAccount::query()
                ->whereKey(CashAccount::default()->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. La ligne de dette ensuite, relue sous verrou : le reste dû
            //    calculé avant le verrou serait périmé.
            $ligne = ParticipationMember::query()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $reste = $ligne->remaining();

            if ($reste <= 0) {
                throw new DomainException('Ce membre est déjà à jour sur cette collecte.');
            }

            if ($amount > $reste && ! config('cyclo.finance.allow_overpayment')) {
                throw new DomainException(
                    "Le montant dépasse le reste dû ({$reste} FCFA). "
                    .'Un trop-perçu ne se corrige pas discrètement : saisissez le juste montant.'
                );
            }

            $membre = $ligne->member;
            $participation = $ligne->participation;

            $paiement = Payment::create([
                'receipt_number' => $this->nextReceiptNumber(),
                'participation_member_id' => $ligne->id,
                // Déduits de la ligne, jamais reçus du client.
                'participation_id' => $ligne->participation_id,
                'member_id' => $ligne->member_id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                // Règle I3 : le collecteur vient de la session.
                'collected_by' => $collector->id,
                'paid_on' => $paidOn ?? now()->toDateString(),
            ]);

            // 3. Le grand livre. La caisse est déjà verrouillée : le second
            //    verrou posé par CashLedger est gratuit, la transaction
            //    courante le détient déjà.
            $ecriture = $this->ledger->record(
                account: $caisse,
                direction: TransactionDirection::In,
                amount: $amount,
                label: $participation->name.' — '.$membre->fullName(),
                sourceType: TransactionSource::Payment,
                source: $paiement,
                category: TransactionCategory::byCode(TransactionCategory::PARTICIPATION),
                eventId: $participation->event_id,
                occurredOn: $paiement->paid_on->toDateString(),
            );

            $paiement->financial_transaction_id = $ecriture->id;
            $paiement->save();

            // 4. Le montant encaissé et le statut se recalculent depuis la
            //    somme réelle. Un seul chemin d'écriture, depuis la phase 10.
            $ligne->recalculate();

            $this->audit->log(
                action: 'payment.created',
                entity: $paiement,
                new: [
                    'amount' => $amount,
                    'method' => $method->value,
                    'receipt_number' => $paiement->receipt_number,
                    'member' => $membre->matricule,
                    'participation' => $participation->name,
                ],
            );

            // PHASE 17 — notification « Paiement de X FCFA enregistré » au
            // membre. Le canal (push, SMS) n'existe pas encore ; le reçu, lui,
            // est réel dès maintenant et consultable par le membre dans son
            // espace. On ne simule pas un envoi qui n'a pas lieu.

            return ['payment' => $paiement->fresh(), 'replayed' => false];
        }, attempts: 3);
    }

    /**
     * Annule un encaissement : contre-passation au grand livre, tampon sur le
     * paiement, statut de la dette recalculé.
     *
     * Rien n'est effacé. Le reçu remis au membre reste retrouvable, marqué
     * annulé — ce qui est exactement ce dont on a besoin quand quelqu'un se
     * présente avec son papier.
     */
    public function cancel(Payment $payment, User $actor, string $reason): Payment
    {
        if ($payment->isCancelled()) {
            throw new DomainException('Ce paiement est déjà annulé.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            $ecriture = $payment->transaction;

            if ($ecriture === null) {
                // Ne peut pas arriver : l'écriture naît dans la même
                // transaction que le paiement. Si cela arrivait malgré tout,
                // le silence serait la pire des réponses.
                throw new DomainException(
                    "Ce paiement n'a pas d'écriture au grand livre : annulation impossible "
                    .'sans intervention du trésorier.'
                );
            }

            $contrepassation = $this->ledger->reverse($ecriture, $reason);

            $payment->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'reversal_transaction_id' => $contrepassation->id,
            ])->save();

            $payment->line->recalculate();

            $this->audit->log(
                action: 'payment.reversed',
                entity: $payment,
                old: ['amount' => $payment->amount, 'cancelled' => false],
                new: ['cancelled' => true, 'reversal' => $contrepassation->uuid],
                reason: $reason,
            );

            return $payment->fresh();
        }, attempts: 3);
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Le numéro de reçu suivant : `RC-2026-000042`.
     *
     * Attribué **sous le verrou de la caisse**, déjà posé par l'appelant. Sans
     * lui, deux encaissements simultanés liraient le même maximum et l'un des
     * deux échouerait sur l'index unique — un collecteur verrait son
     * encaissement refusé sans raison compréhensible.
     *
     * La séquence repart à 1 chaque année : c'est la convention des carnets à
     * souches que le club utilise déjà, et elle garde les numéros courts.
     *
     * Le tri porte sur le NUMÉRO et non sur `id` : trier par identifiant
     * marcherait aujourd'hui et casserait le jour où un import rétroactif
     * insérerait des reçus dans le désordre. Le remplissage à six chiffres
     * rend d'ailleurs les deux tris équivalents tant qu'on reste sous le
     * million de reçus dans l'année.
     */
    private function nextReceiptNumber(): string
    {
        $prefixe = 'RC-'.now()->year.'-';

        $dernier = Payment::query()
            ->where('receipt_number', 'like', $prefixe.'%')
            ->orderByDesc('receipt_number')
            ->value('receipt_number');

        $suivant = $dernier === null
            ? 1
            : ((int) substr((string) $dernier, strlen($prefixe))) + 1;

        return $prefixe.str_pad((string) $suivant, 6, '0', STR_PAD_LEFT);
    }
}
