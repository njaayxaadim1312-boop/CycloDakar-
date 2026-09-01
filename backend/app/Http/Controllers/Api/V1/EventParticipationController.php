<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Event\AttendanceRequest;
use App\Http\Resources\EventParticipantResource;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use App\Services\EventRegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Inscriptions, désistements et pointage des présences.
 *
 * Séparé d'`EventController` parce que ce sont d'autres droits et d'autres
 * acteurs : un membre s'inscrit lui-même, un collecteur pointe les autres.
 * Mélanger les deux dans un seul contrôleur mènerait tôt ou tard à ce qu'une
 * règle de l'un déteigne sur l'autre.
 */
final class EventParticipationController extends Controller
{
    public function __construct(
        private readonly EventRegistrationService $registrations,
    ) {}

    /** Liste nominative des inscrits. */
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewParticipants', $event);

        $participants = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('registration_status', '!=', RegistrationStatus::Cancelled)
            ->with(['member', 'checkedInBy'])
            ->orderBy('registration_status')
            ->orderBy('queue_position')
            ->orderBy('registered_at')
            ->get();

        return ApiResponse::ok(
            EventParticipantResource::collection($participants),
            meta: $this->tally($event),
        );
    }

    /**
     * « Je participe ».
     *
     * Le membre vient de la SESSION : on ne s'inscrit pas à la place d'un
     * autre. Le service place automatiquement en liste d'attente si la sortie
     * est pleine — refuser sèchement ferait perdre au club des participants
     * qui seraient venus si une place s'était libérée.
     */
    public function register(Request $request, Event $event): JsonResponse
    {
        $this->authorize('register', $event);

        $member = $request->user()->member;

        if ($member === null) {
            return ApiResponse::error(
                message: "Aucune fiche membre n'est associée à votre compte.",
                status: 404,
                code: 'NO_MEMBER_PROFILE',
            );
        }

        try {
            $participant = $this->registrations->register($event, $member);
        } catch (RuntimeException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422,
                code: 'REGISTRATIONS_CLOSED',
            );
        }

        return ApiResponse::ok(
            new EventParticipantResource($participant->load('member')),
            meta: $this->tally($event->fresh()),
        );
    }

    /** « Je me désiste » — la place libérée profite au premier de la file. */
    public function cancel(Request $request, Event $event): JsonResponse
    {
        $this->authorize('register', $event);

        $member = $request->user()->member;

        if ($member === null) {
            return ApiResponse::error(
                message: "Aucune fiche membre n'est associée à votre compte.",
                status: 404,
                code: 'NO_MEMBER_PROFILE',
            );
        }

        $exists = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('member_id', $member->id)
            ->exists();

        if (! $exists) {
            return ApiResponse::error(
                message: "Vous n'êtes pas inscrit à cette sortie.",
                status: 404,
                code: 'NOT_REGISTERED',
            );
        }

        $participant = $this->registrations->cancel($event, $member);

        return ApiResponse::ok(
            new EventParticipantResource($participant->load('member')),
            meta: $this->tally($event->fresh()),
        );
    }

    /**
     * Pointage d'un présent ou d'un absent.
     *
     * `checked_in_by` et `checked_in_at` viennent de la session et de
     * l'horloge du serveur. C'est une signature : si le client pouvait la
     * fournir, la liste des présents ne vaudrait plus rien — et ces listes
     * serviront à justifier des participations financières.
     */
    public function attendance(AttendanceRequest $request, Event $event): JsonResponse
    {
        $member = Member::where('uuid', $request->validated('member'))->firstOrFail();
        $status = AttendanceStatus::from($request->validated('status'));

        try {
            $participant = $this->registrations->markAttendance(
                $event,
                $member,
                $status,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422,
                code: 'ATTENDANCE_CLOSED',
            );
        }

        return ApiResponse::ok(
            new EventParticipantResource($participant->load(['member', 'checkedInBy'])),
            meta: $this->tally($event),
        );
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Compteurs de la sortie, renvoyés à chaque mouvement.
     *
     * Le client n'a ainsi jamais besoin de recharger l'événement pour afficher
     * « 24 / 25 » à jour — et surtout, il n'a pas à recalculer ce compte
     * lui-même à partir de ce qu'il a en mémoire, ce qui divergerait dès qu'un
     * autre membre s'inscrit en même temps.
     *
     * @return array<string, mixed>
     */
    private function tally(Event $event): array
    {
        $counts = EventParticipant::query()
            ->where('event_id', $event->id)
            ->selectRaw('registration_status, COUNT(*) as total')
            ->groupBy('registration_status')
            ->pluck('total', 'registration_status');

        $registered = (int) ($counts[RegistrationStatus::Registered->value] ?? 0);

        $present = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('attendance_status', AttendanceStatus::Present)
            ->count();

        return [
            'registered' => $registered,
            'waitlist' => (int) ($counts[RegistrationStatus::Waitlist->value] ?? 0),
            'cancelled' => (int) ($counts[RegistrationStatus::Cancelled->value] ?? 0),
            'present' => $present,
            'max_participants' => $event->max_participants,
            'seats_left' => $event->max_participants === null
                ? null
                : max(0, $event->max_participants - $registered),
        ];
    }
}
