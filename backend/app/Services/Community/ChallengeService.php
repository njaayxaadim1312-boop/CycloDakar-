<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Models\Activity;
use App\Models\Challenge;
use App\Models\ChallengeMember;
use App\Models\Member;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inscriptions et progression des défis.
 *
 * LA PROGRESSION COMPTE DEPUIS LE DÉBUT DU DÉFI, PAS DEPUIS L'INSCRIPTION.
 *
 * Un membre qui découvre le défi le 15 et roulait déjà depuis le 1er ne doit
 * pas repartir de zéro : on le pénaliserait d'avoir ouvert l'application plus
 * tard, ce qui n'a rien à voir avec l'effort. La fenêtre de calcul est celle du
 * défi, pour tout le monde.
 *
 * UNE SORTIE PRIVÉE NE COMPTE PAS — même pour son auteur.
 *
 * C'est le point qui se discute, et voici pourquoi il est tranché ainsi : un
 * défi est un CLASSEMENT, et un classement est une publication. Faire compter
 * une sortie privée dans la progression permettrait de déduire son existence
 * en regardant le compteur monter — et surtout, cela créerait deux barèmes,
 * l'un pour ceux qui publient et l'autre pour ceux qui masquent. L'interface
 * l'annonce plutôt que de le laisser découvrir.
 *
 * UN BADGE OBTENU NE SE REPREND PAS.
 *
 * `completed_at` est figé à l'instant où l'objectif est atteint. Si la
 * progression retombe ensuite — sortie supprimée, passée en privé, trace
 * corrigée — la date reste. Reprendre une récompense déjà annoncée est le plus
 * sûr moyen de faire quitter un club.
 */
final class ChallengeService
{
    /** Inscrit un membre. Idempotent : se réinscrire ne fait rien de neuf. */
    public function join(Challenge $challenge, Member $member): ChallengeMember
    {
        if (! $challenge->acceptsEntries()) {
            throw new DomainException(
                $challenge->ends_on->isPast()
                    ? 'Ce défi est terminé : on ne peut plus s\'y inscrire.'
                    : "Ce défi n'est pas encore annoncé."
            );
        }

        $inscription = ChallengeMember::firstOrCreate(
            ['challenge_id' => $challenge->id, 'member_id' => $member->id],
            ['joined_at' => now(), 'progress' => 0],
        );

        // La progression est calculée TOUT DE SUITE : un membre qui roulait
        // déjà doit voir sa barre remplie à l'inscription, pas à la prochaine
        // sortie. C'est la différence entre « ce défi me concerne » et « ce
        // défi part de zéro alors que j'ai déjà fait la moitié ».
        return $this->refresh($inscription);
    }

    /**
     * Désinscrit un membre.
     *
     * Sauf s'il a déjà réussi : la ligne porte alors un badge, et un badge fait
     * partie de l'histoire du membre. On refuse plutôt que d'effacer en
     * silence quelque chose qu'il a gagné.
     */
    public function leave(Challenge $challenge, Member $member): void
    {
        $inscription = ChallengeMember::query()
            ->where('challenge_id', $challenge->id)
            ->where('member_id', $member->id)
            ->first();

        if ($inscription === null) {
            return;
        }

        if ($inscription->hasCompleted()) {
            throw new DomainException(
                'Vous avez déjà réussi ce défi : le badge vous reste acquis, '
                .'et la participation ne peut plus être retirée.'
            );
        }

        $inscription->delete();
    }

    /**
     * Recalcule la progression d'une inscription.
     *
     * @return ChallengeMember La ligne à jour.
     */
    public function refresh(ChallengeMember $inscription): ChallengeMember
    {
        $challenge = $inscription->challenge;
        $progression = $this->measure($challenge, $inscription->member_id);

        $attributs = ['progress' => $progression];

        // L'objectif vient d'être atteint : on FIGE la date. Une fois posée,
        // elle ne bouge plus, même si la progression retombe.
        if ($progression >= $challenge->target && ! $inscription->hasCompleted()) {
            $attributs['completed_at'] = now();
        }

        $inscription->update($attributs);

        return $inscription->fresh();
    }

    /**
     * Recalcule tous les participants d'un défi, et renvoie leur nombre.
     *
     * Une seule requête agrégée plutôt qu'une par membre : sur un défi à cent
     * participants, la boucle naïve ferait cent balayages de la table des
     * activités.
     */
    public function refreshAll(Challenge $challenge): int
    {
        $totaux = $this->measureAll($challenge);
        $maintenant = now();
        $touches = 0;

        DB::transaction(function () use ($challenge, $totaux, $maintenant, &$touches): void {
            foreach ($challenge->participants as $inscription) {
                $progression = $totaux[$inscription->member_id] ?? 0;

                $attributs = ['progress' => $progression];

                if ($progression >= $challenge->target && ! $inscription->hasCompleted()) {
                    $attributs['completed_at'] = $maintenant;
                }

                $inscription->update($attributs);
                $touches++;
            }
        });

        return $touches;
    }

    /* ---------------------------------------------------------------------- */

    /** La progression d'UN membre, dans l'unité de la mesure du défi. */
    private function measure(Challenge $challenge, int $memberId): int
    {
        $colonne = $challenge->metric->column();

        $query = $this->scope($challenge)->where('member_id', $memberId);

        return (int) ($colonne === null ? $query->count() : $query->sum($colonne));
    }

    /**
     * La progression de TOUS les participants, en une requête.
     *
     * @return array<int, int> member_id => progression
     */
    private function measureAll(Challenge $challenge): array
    {
        $colonne = $challenge->metric->column();

        $valeur = $colonne === null
            ? DB::raw('COUNT(*) as total')
            : DB::raw("SUM({$colonne}) as total");

        return $this->scope($challenge)
            ->whereIn('member_id', $challenge->participants->pluck('member_id'))
            ->groupBy('member_id')
            ->get(['member_id', $valeur])
            ->mapWithKeys(fn ($ligne) => [(int) $ligne->member_id => (int) $ligne->total])
            ->all();
    }

    /**
     * Les sorties qui comptent pour un défi.
     *
     * Terminées, non privées, dans la fenêtre du défi, et du bon sport. Les
     * quatre conditions sont au même endroit : les disperser garantirait
     * qu'une d'elles finisse par manquer quelque part.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Activity>
     */
    private function scope(Challenge $challenge): \Illuminate\Database\Eloquent\Builder
    {
        $query = Activity::query()
            ->where('status', ActivityStatus::Completed)
            // Une sortie privée ne compte pas, même pour son auteur : voir
            // l'en-tête de la classe.
            ->where('visibility', '!=', ActivityVisibility::Private)
            ->whereBetween('started_at', [
                $challenge->starts_on->copy()->startOfDay(),
                $challenge->ends_on->copy()->endOfDay(),
            ]);

        if ($challenge->sport !== null) {
            $query->where('sport', $challenge->sport);
        }

        return $query;
    }
}
