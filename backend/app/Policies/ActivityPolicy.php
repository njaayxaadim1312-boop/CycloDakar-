<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ActivityVisibility;
use App\Models\Activity;
use App\Models\User;

/**
 * Qui peut voir et modifier une activité.
 *
 * Une trace GPS n'est pas une donnée anodine : elle révèle où quelqu'un
 * habite, à quelle heure il part, et par où il passe tous les mardis. Les
 * règles ci-dessous sont donc plus strictes que pour l'annuaire.
 *
 * Point important : **un administrateur ne voit pas les activités privées**.
 * Il administre le club, pas la vie privée de ses membres. C'est un écart
 * assumé avec le reste du projet, où l'administration voit tout.
 */
final class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Activity $activity): bool
    {
        if ($this->owns($user, $activity)) {
            return true;
        }

        // Une activité PRIVÉE reste privée, y compris pour un administrateur.
        return $activity->visibility !== ActivityVisibility::Private;
    }

    public function create(User $user): bool
    {
        // Enregistrer ses propres sorties suppose d'avoir une fiche club :
        // c'est elle qui porte le rattachement au membre.
        return $user->member !== null;
    }

    /**
     * Seul le propriétaire modifie sa sortie.
     *
     * Et il ne peut changer que le titre, les notes et la visibilité : les
     * statistiques sont recalculées depuis les points bruts, jamais saisies.
     */
    public function update(User $user, Activity $activity): bool
    {
        return $this->owns($user, $activity);
    }

    /** Envoyer des points et finaliser : le propriétaire, exclusivement. */
    public function sync(User $user, Activity $activity): bool
    {
        return $this->owns($user, $activity);
    }

    /**
     * Suppression.
     *
     * Un administrateur peut supprimer une activité manifestement erronée
     * (trace aberrante, doublon), mais c'est une suppression douce : elle
     * disparaît des listes et des classements sans être effacée.
     */
    public function delete(User $user, Activity $activity): bool
    {
        return $this->owns($user, $activity) || $user->role->isAdmin();
    }

    /* ---------------------------------------------------------------------- */

    private function owns(User $user, Activity $activity): bool
    {
        $member = $user->member;

        return $member !== null && $member->id === $activity->member_id;
    }
}
