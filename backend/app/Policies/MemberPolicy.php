<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;

/**
 * Qui peut faire quoi sur une fiche membre.
 *
 * Deux idées structurent ces règles :
 *
 *  1. **l'annuaire est un bien commun du club** : tout membre connecté peut
 *     consulter la liste et les fiches. C'est un club sportif, pas un fichier
 *     confidentiel — et le collecteur a besoin de retrouver n'importe qui ;
 *
 *  2. **modifier la fiche d'autrui est un acte d'administration**. Un membre
 *     ne modifie que la sienne, et jamais son propre statut ni son rôle.
 */
final class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Member $member): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        // Le collecteur peut inscrire quelqu'un sur le terrain : c'est
        // précisément le cas d'usage « un nouveau se présente au départ ».
        return $user->role->canCollect();
    }

    public function update(User $user, Member $member): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        // Chacun gère sa propre fiche.
        return $member->user_id === $user->id;
    }

    /**
     * Changer le statut (actif, suspendu, ancien membre) est une décision du
     * bureau, jamais du membre concerné : sinon un membre suspendu se
     * réactiverait lui-même.
     */
    public function updateStatus(User $user, Member $member): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Changer le RÔLE d'un compte est réservé à l'administration.
     *
     * Deux garde-fous supplémentaires :
     *  - on ne modifie pas son propre rôle (on ne se dégrade pas par erreur,
     *    et on ne se promeut pas non plus) ;
     *  - seul un SUPER_ADMIN peut toucher à un autre administrateur, pour
     *    qu'un ADMIN ne puisse pas écarter ses pairs.
     */
    public function updateRole(User $user, Member $member): bool
    {
        if (! $user->role->isAdmin()) {
            return false;
        }

        if ($member->user_id === $user->id) {
            return false;
        }

        $target = $member->user;

        if ($target !== null && $target->role->isAdmin()) {
            return $user->role === UserRole::SuperAdmin;
        }

        return true;
    }

    /** Voir et faire tourner le QR Code. */
    public function manageQrCode(User $user, Member $member): bool
    {
        return $user->role->isAdmin() || $member->user_id === $user->id;
    }

    /**
     * Archivage (suppression douce).
     *
     * On n'efface jamais un membre : ses activités et ses paiements y font
     * référence. « Supprimer » signifie ici archiver, et reste réservé à
     * l'administration. Passer le statut à « Ancien membre » est presque
     * toujours le geste juste.
     */
    public function delete(User $user, Member $member): bool
    {
        return $user->role->isAdmin() && $member->user_id !== $user->id;
    }

    public function restore(User $user, Member $member): bool
    {
        return $user->role->isAdmin();
    }
}
