<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\User;

/**
 * Qui crée un défi, qui y participe.
 *
 * **Créer un défi relève du CHEF DE GROUPE**, pas du trésorier.
 *
 * Un défi est un acte d'animation sportive : « 500 km en septembre » engage le
 * club auprès de ses membres, comme une sortie annoncée. Exiger le rôle de
 * collecteur reviendrait à demander l'accès à la caisse pour proposer un défi
 * de course à pied — exactement ce que la séparation des rôles évite.
 *
 * **Participer est ouvert à tout membre**, sans validation. Un défi qu'il faut
 * demander à rejoindre n'est plus un défi, c'est une inscription.
 */
final class ChallengePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Challenge $challenge): bool
    {
        if ($challenge->status->isPublic()) {
            return true;
        }

        // Un brouillon n'appartient qu'à son auteur et à l'administration.
        return $challenge->created_by === $user->id || $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->canLeadRides();
    }

    public function update(User $user, Challenge $challenge): bool
    {
        if ($challenge->ends_on->isPast()) {
            // Un défi terminé est un fait : des membres ont gagné des badges
            // sur ces règles-là. En changer l'objectif après coup les
            // invaliderait rétroactivement.
            return false;
        }

        return $challenge->created_by === $user->id || $user->role->isAdmin();
    }

    public function delete(User $user, Challenge $challenge): bool
    {
        return $challenge->created_by === $user->id || $user->role->isAdmin();
    }

    /**
     * Participer.
     *
     * Suppose une fiche club — c'est elle qui porte les sorties — et de pouvoir
     * voir le défi. La condition de visibilité n'est pas redondante : sans
     * elle, un membre qui obtiendrait l'uuid d'un brouillon recevrait un 422
     * nommant son statut, ce qui en confirmerait l'existence.
     */
    public function join(User $user, Challenge $challenge): bool
    {
        return $user->member !== null
            && $challenge->status === ChallengeStatus::Published
            && $this->view($user, $challenge);
    }
}
