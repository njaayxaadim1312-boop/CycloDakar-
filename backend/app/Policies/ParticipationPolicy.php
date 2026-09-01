<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ParticipationStatus;
use App\Models\Participation;
use App\Models\User;

/**
 * Qui organise une collecte, qui la voit, qui encaisse.
 *
 * L'argent du club appelle des droits plus stricts que le sport :
 *
 *  - **créer et modifier** une collecte : trésorier et au-dessus. Un
 *    collecteur encaisse, il ne décide pas de ce que le club demande à ses
 *    membres ;
 *  - **voir** : tout collecteur, puisqu'il devra passer sur le terrain ;
 *  - **clôturer** : l'auteur ou un administrateur.
 *
 * Un simple membre ne voit pas les collectes ici. Il verra SA propre dette
 * dans son espace personnel — PHASE 12, avec les encaissements.
 */
final class ParticipationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canCollect();
    }

    public function view(User $user, Participation $participation): bool
    {
        if (! $user->role->canCollect()) {
            return false;
        }

        if ($participation->status->isPublic()) {
            return true;
        }

        // Un brouillon n'appartient qu'à son auteur et à l'administration.
        return $participation->created_by === $user->id || $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->canManageFinance();
    }

    public function update(User $user, Participation $participation): bool
    {
        if ($participation->status === ParticipationStatus::Closed) {
            // Une collecte clôturée est un fait comptable. La retoucher
            // fausserait un rapport peut-être déjà présenté en assemblée.
            return false;
        }

        return $user->role->canManageFinance();
    }

    public function delete(User $user, Participation $participation): bool
    {
        return $participation->created_by === $user->id || $user->role->isAdmin();
    }

    /**
     * Changer l'etat : ouvrir, cloturer, annuler.
     *
     * Volontairement PLUS PERMISSIVE que `update` : ce qui est possible ou non
     * est decide par `ParticipationStatus::allowedTransitions()`, et par lui
     * seul. Refuser ici en plus donnerait deux reponses differentes pour deux
     * transitions egalement impossibles — 403 pour une collecte close, 422
     * pour une annulee — alors que la cause est la meme.
     */
    public function transition(User $user, Participation $participation): bool
    {
        return $user->role->canManageFinance();
    }

    /** Rattacher ou retirer des membres. */
    public function assign(User $user, Participation $participation): bool
    {
        return $this->update($user, $participation);
    }
}
