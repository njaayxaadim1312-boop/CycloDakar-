<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RegistrationStatus;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation d'une sortie officielle.
 *
 * Deux principes hérités du reste du projet :
 *
 *  - **unités SI** : `planned_distance_m` est en mètres, jamais en kilomètres.
 *  - **jamais de chiffre inventé** : `seats_left` vaut `null` quand la sortie
 *    n'est pas limitée. Renvoyer un grand nombre laisserait croire à une
 *    limite haute, et « illimité » ne s'affiche pas comme « il reste 1 975
 *    places ».
 *
 * `my_registration` répond à la seule question que le membre se pose en
 * ouvrant l'écran : « est-ce que je suis inscrit ? ». La calculer ici évite au
 * client un second appel par événement dans une liste.
 *
 * @mixin Event
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $memberId = $viewer?->member?->id;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,

            'sport' => $this->sport->value,
            'sport_label' => $this->sport->label(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'location_name' => $this->location_name,
            'start_lat' => $this->start_lat !== null ? (float) $this->start_lat : null,
            'start_lng' => $this->start_lng !== null ? (float) $this->start_lng : null,

            // Mètres. La conversion en kilomètres appartient à l'affichage.
            'planned_distance_m' => $this->planned_distance_m !== null
                ? (int) $this->planned_distance_m
                : null,
            'route_polyline' => $this->route_polyline,

            'difficulty' => $this->difficulty?->value,
            'difficulty_label' => $this->difficulty?->label(),
            'difficulty_hint' => $this->difficulty?->hint(),

            'max_participants' => $this->max_participants,
            'seats_taken' => $this->seatsTakenValue(),
            'seats_left' => $this->seatsLeftValue(),
            'is_full' => $this->max_participants !== null && $this->seatsLeftValue() === 0,

            'registrations_open' => $this->status->acceptsRegistrations(),

            'my_registration' => $this->myRegistration($memberId),

            'created_by' => $this->whenLoaded('creator', fn () => [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),

            'participants' => EventParticipantResource::collection(
                $this->whenLoaded('participants')
            ),

            'permissions' => $viewer === null ? null : [
                'update' => $viewer->can('update', $this->resource),
                'delete' => $viewer->can('delete', $this->resource),
                'manage_attendance' => $viewer->can('manageAttendance', $this->resource),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Places occupées.
     *
     * Le compte agrégé par `withCount` est privilégié quand le contrôleur l'a
     * chargé : sur une liste de vingt sorties, laisser le modèle compter
     * lui-même déclencherait vingt requêtes de plus.
     */
    private function seatsTakenValue(): int
    {
        return $this->registered_count !== null
            ? (int) $this->registered_count
            : $this->seatsTaken();
    }

    private function seatsLeftValue(): ?int
    {
        if ($this->max_participants === null) {
            return null;
        }

        return max(0, $this->max_participants - $this->seatsTakenValue());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function myRegistration(?int $memberId): ?array
    {
        if ($memberId === null || ! $this->relationLoaded('participants')) {
            return null;
        }

        $mine = $this->participants->firstWhere('member_id', $memberId);

        // Un désistement n'est pas une inscription : on renvoie `null`, comme
        // pour un membre qui ne s'est jamais inscrit. Le bureau, lui, voit la
        // nuance dans la liste des participants.
        if ($mine === null || $mine->registration_status === RegistrationStatus::Cancelled) {
            return null;
        }

        return [
            'status' => $mine->registration_status->value,
            'status_label' => $mine->registration_status->label(),
            'queue_position' => $mine->queue_position,
            'attendance_status' => $mine->attendance_status->value,
            'registered_at' => $mine->registered_at?->toIso8601String(),
        ];
    }
}
