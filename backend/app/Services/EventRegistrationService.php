<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Inscriptions, désistements et liste d'attente.
 *
 * Le problème réel de ce service est la **concurrence**. Le bureau annonce une
 * sortie à 25 places sur WhatsApp ; vingt membres touchent « Je participe »
 * dans la même minute. Deux inscriptions qui liraient toutes les deux
 * « 24 places occupées » prendraient la vingt-cinquième — et le club se
 * retrouverait avec 26 inscrits sur 25 places, sans que personne comprenne.
 *
 * La protection est la même que pour les matricules : la ligne de l'événement
 * est **verrouillée en écriture** (`lockForUpdate`) le temps de compter les
 * places et d'écrire l'inscription. Les demandes simultanées défilent alors
 * une par une. C'est aussi la raison pour laquelle les tests tournent sur
 * MySQL : SQLite ignore purement `SELECT ... FOR UPDATE` et laisserait passer
 * exactement ce bug.
 *
 * Deuxième règle, moins visible mais tout aussi importante : **la position
 * dans la file ne se recalcule jamais**. Elle est attribuée à l'inscription et
 * ne bouge plus. Renuméroter à chaque désistement ferait remonter et descendre
 * des membres sans qu'ils comprennent pourquoi, et le premier arrivé perdrait
 * son rang au profit du dernier à avoir rafraîchi l'écran.
 */
final class EventRegistrationService
{
    /**
     * Inscrit un membre, ou le place en liste d'attente si la sortie est pleine.
     *
     * Idempotent : réinscrire un membre déjà inscrit ne crée pas de doublon et
     * ne le renvoie pas en fin de file. Un double appui sur un réseau lent ne
     * doit rien casser.
     */
    public function register(Event $event, Member $member): EventParticipant
    {
        if (! $event->status->acceptsRegistrations()) {
            throw new RuntimeException(
                "Les inscriptions sont fermées pour cette sortie ({$event->status->label()})."
            );
        }

        return DB::transaction(function () use ($event, $member): EventParticipant {
            // Verrou sur l'événement : tout le comptage de places qui suit se
            // fait à l'abri des inscriptions concurrentes.
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $existing = EventParticipant::query()
                ->where('event_id', $locked->id)
                ->where('member_id', $member->id)
                ->first();

            // Déjà inscrit ou déjà en file : on ne touche à rien. Renvoyer la
            // ligne telle quelle rend l'appel idempotent.
            if ($existing !== null && $existing->registration_status !== RegistrationStatus::Cancelled) {
                return $existing;
            }

            $status = $this->hasSeatAvailable($locked)
                ? RegistrationStatus::Registered
                : RegistrationStatus::Waitlist;

            $attributes = [
                'registration_status' => $status,
                'registered_at' => now(),
                // Une réinscription après désistement repart d'une présence
                // inconnue : le pointage précédent ne la concerne plus.
                'attendance_status' => AttendanceStatus::Unknown,
                'checked_in_at' => null,
                'checked_in_by' => null,
            ];

            if ($status === RegistrationStatus::Waitlist) {
                $attributes['queue_position'] = $this->nextQueuePosition($locked);
            }

            if ($existing !== null) {
                $existing->update($attributes);

                return $existing->refresh();
            }

            return EventParticipant::create($attributes + [
                'event_id' => $locked->id,
                'member_id' => $member->id,
            ]);
        });
    }

    /**
     * Désistement.
     *
     * La ligne est conservée en `CANCELLED` plutôt que supprimée : le bureau
     * doit pouvoir distinguer « ne s'est jamais inscrit » de « s'est désisté ».
     * Sur une sortie à places limitées, la différence compte.
     *
     * Libérer une place promeut immédiatement le premier de la file d'attente.
     */
    public function cancel(Event $event, Member $member): EventParticipant
    {
        return DB::transaction(function () use ($event, $member): EventParticipant {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $participant = EventParticipant::query()
                ->where('event_id', $locked->id)
                ->where('member_id', $member->id)
                ->firstOrFail();

            $heldSeat = $participant->registration_status->holdsSeat();

            $participant->update([
                'registration_status' => RegistrationStatus::Cancelled,
                'queue_position' => null,
            ]);

            if ($heldSeat) {
                $this->promoteFromWaitlist($locked);
            }

            return $participant->refresh();
        });
    }

    /**
     * Pointe la présence d'un membre.
     *
     * `checked_in_by` vient de la SESSION, jamais du client : c'est une
     * signature. Un membre ne se pointe pas lui-même présent — sinon la liste
     * ne vaudrait plus rien, et ces listes serviront à justifier des
     * participations financières.
     */
    public function markAttendance(
        Event $event,
        Member $member,
        AttendanceStatus $status,
        User $by,
    ): EventParticipant {
        if (! $event->status->acceptsAttendance()) {
            throw new RuntimeException(
                'On ne pointe les présences que sur une sortie en cours ou terminée.'
            );
        }

        return DB::transaction(function () use ($event, $member, $status, $by): EventParticipant {
            $participant = EventParticipant::query()
                ->where('event_id', $event->id)
                ->where('member_id', $member->id)
                ->lockForUpdate()
                ->first();

            // Un membre non inscrit qui se présente le jour même est un
            // participant réel. Le refuser fausserait la liste des présents,
            // qui est justement ce que l'on cherche à établir.
            if ($participant === null) {
                $participant = EventParticipant::create([
                    'event_id' => $event->id,
                    'member_id' => $member->id,
                    'registration_status' => RegistrationStatus::Registered,
                    'registered_at' => now(),
                ]);
            }

            $participant->update([
                'attendance_status' => $status,
                'checked_in_at' => $status === AttendanceStatus::Unknown ? null : now(),
                'checked_in_by' => $status === AttendanceStatus::Unknown ? null : $by->id,
            ]);

            return $participant->refresh();
        });
    }

    /* ---------------------------------------------------------------------- */

    /** À appeler sous verrou, sinon le compte est déjà périmé au moment de l'écrire. */
    private function hasSeatAvailable(Event $event): bool
    {
        if ($event->max_participants === null) {
            return true;
        }

        $taken = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('registration_status', RegistrationStatus::Registered)
            ->count();

        return $taken < $event->max_participants;
    }

    /**
     * Rang suivant dans la file.
     *
     * On repart du plus grand rang jamais attribué, y compris ceux des
     * membres promus ou désistés depuis : c'est ce qui garantit qu'un rang
     * n'est jamais réattribué et que l'ordre d'arrivée reste vrai.
     */
    private function nextQueuePosition(Event $event): int
    {
        $highest = (int) EventParticipant::query()
            ->where('event_id', $event->id)
            ->max('queue_position');

        return $highest + 1;
    }

    /** Promeut le premier de la file d'attente sur la place libérée. */
    private function promoteFromWaitlist(Event $event): ?EventParticipant
    {
        if (! $this->hasSeatAvailable($event)) {
            return null;
        }

        $next = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('registration_status', RegistrationStatus::Waitlist)
            ->orderBy('queue_position')
            ->first();

        if ($next === null) {
            return null;
        }

        $next->update([
            'registration_status' => RegistrationStatus::Registered,
            // Le rang est effacé : le membre n'est plus dans la file. Le
            // conserver laisserait croire qu'il y est encore.
            'queue_position' => null,
        ]);

        // PHASE 17 — notifier le membre promu. Sans notification, il ne sait
        // pas qu'une place s'est libérée, et l'intérêt de la file d'attente
        // reste théorique. L'architecture est prête : c'est ici que l'envoi
        // se branchera.

        return $next->refresh();
    }
}
