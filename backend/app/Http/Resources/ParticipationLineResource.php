<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ParticipationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une dette : ce qu'un membre doit sur une collecte.
 *
 * `paid_amount` et `status` sont **dérivés** des paiements réels. Ils sont
 * exposés en lecture seule ; aucune route ne les accepte en entrée.
 *
 * La fiche du membre est réduite au strict nécessaire pour la collecte sur le
 * terrain — nom, matricule, téléphone. Le téléphone est justifié ici : un
 * collecteur doit pouvoir appeler avant de se déplacer. Il n'est pas exposé à
 * qui n'a pas le droit de collecter, puisque cette ressource elle-même l'est.
 *
 * @mixin ParticipationMember
 */
final class ParticipationLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'member' => $this->whenLoaded('member', fn () => [
                'uuid' => $this->member->uuid,
                'matricule' => $this->member->matricule,
                'full_name' => $this->member->fullName(),
                'initials' => $this->member->initials(),
                'photo_url' => $this->member->photoUrl(),
                'phone_formatted' => $this->member->formattedPhone(),
            ]),

            // Entiers de FCFA, sans exception.
            'expected_amount' => (int) $this->expected_amount,
            'paid_amount' => (int) $this->paid_amount,
            'remaining_amount' => $this->remaining(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'collector' => $this->whenLoaded('collector', fn () => $this->collector === null
                ? null
                : ['uuid' => $this->collector->uuid, 'name' => $this->collector->name]),

            'last_payment_at' => $this->last_payment_at?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
