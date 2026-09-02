<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

/**
 * Qui organise, qui s'inscrit, qui pointe.
 *
 * Trois cercles distincts, et la distinction est volontaire :
 *
 *  - **tout membre** voit les événements annoncés et s'y inscrit ;
 *  - **un CHEF DE GROUPE et au-dessus** crée les sorties, en trace
 *    l'itinéraire et pointe les présences. Ce rôle a été introduit pour cela :
 *    tant qu'encadrer exigeait d'être collecteur, il fallait confier la caisse
 *    à quelqu'un qui voulait seulement mener le groupe le dimanche matin ;
 *  - **seuls l'auteur et un administrateur** modifient ou annulent une sortie.
 *    Sans cela, n'importe quel collecteur pourrait déplacer la sortie d'un
 *    autre, et personne ne saurait qui l'a fait.
 */
final class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        if ($event->status->isPublic()) {
            return true;
        }

        // Un brouillon n'appartient qu'à son auteur et à l'administration.
        return $event->created_by === $user->id || $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->canLeadRides();
    }

    public function update(User $user, Event $event): bool
    {
        if ($event->status === EventStatus::Done) {
            // Une sortie terminée est un fait, pas un projet. La retoucher
            // fausserait les présences déjà pointées.
            return false;
        }

        return $event->created_by === $user->id || $user->role->isAdmin();
    }

    public function delete(User $user, Event $event): bool
    {
        return $event->created_by === $user->id || $user->role->isAdmin();
    }

    /**
     * S'inscrire suppose une fiche club — c'est elle qui porte l'inscription —
     * et de pouvoir voir la sortie.
     *
     * La condition de visibilité n'est pas redondante. Sans elle, un membre qui
     * obtiendrait l'uuid d'un brouillon recevrait un 422 nommant son statut,
     * ce qui confirmerait son existence. Un brouillon doit rester introuvable,
     * pas seulement inaccessible.
     */
    public function register(User $user, Event $event): bool
    {
        return $user->member !== null && $this->view($user, $event);
    }

    /**
     * Pointer les présences : les encadrants de la sortie.
     *
     * `canLeadRides()` et non `canCollect()` : celui qui a mené le groupe est
     * précisément celui qui sait qui était là. Exiger le rôle de collecteur
     * obligerait le chef de groupe à faire pointer par quelqu'un d'autre, qui
     * n'y était pas.
     */
    public function manageAttendance(User $user, Event $event): bool
    {
        return $user->role->canLeadRides();
    }

    /**
     * Voir la liste nominative des inscrits.
     *
     * Ouverte à tous les membres : savoir qui vient est précisément ce qui
     * fait venir. La liste ne porte que le nom et le matricule — ni téléphone
     * ni adresse, que `MemberResource` réserve déjà aux collecteurs.
     */
    public function viewParticipants(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }
}
