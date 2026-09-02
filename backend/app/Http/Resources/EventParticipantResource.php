<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EventParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un inscrit à une sortie.
 *
 * La ligne ne porte que l'identité MINIMALE : nom, initiales, matricule,
 * photo. Ni téléphone ni adresse — savoir qui vient ne suppose pas d'obtenir
 * l'annuaire, que `MemberResource` réserve déjà aux collecteurs.
 *
 * `checked_in_by` n'est renvoyé qu'aux personnes habilitées à pointer : c'est
 * une signature, et elle n'a d'intérêt que pour celui qui doit répondre d'un
 * pointage contesté.
 *
 * @mixin EventParticipant
 */
final class EventParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        // Le chef de groupe, pas le collecteur : c'est lui qui a mene la
        // sortie, donc lui qui sait qui etait la.
        $canManage = $viewer !== null && $viewer->role->canLeadRides();

        return [
            'member' => $this->whenLoaded('member', fn () => [
                'uuid' => $this->member->uuid,
                'matricule' => $this->member->matricule,
                'full_name' => $this->member->fullName(),
                'initials' => $this->member->initials(),
                'photo_url' => $this->member->photoUrl(),
            ]),

            'registration_status' => $this->registration_status->value,
            'registration_status_label' => $this->registration_status->label(),
            'queue_position' => $this->queue_position,
            'registered_at' => $this->registered_at?->toIso8601String(),

            'attendance_status' => $this->attendance_status->value,
            'attendance_status_label' => $this->attendance_status->label(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),

            'checked_in_by' => $this->when(
                $canManage && $this->relationLoaded('checkedInBy') && $this->checkedInBy !== null,
                fn () => [
                    'uuid' => $this->checkedInBy->uuid,
                    'name' => $this->checkedInBy->name,
                ],
            ),

            // La sortie GPS effectivement enregistrée, s'il y en a une.
            'activity_uuid' => $this->whenLoaded('activity', fn () => $this->activity?->uuid),
        ];
    }
}
