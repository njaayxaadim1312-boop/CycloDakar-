<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Participation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation d'une campagne de collecte.
 *
 * **Tous les montants sont des entiers de FCFA.** Le client les met en forme
 * (`formatFcfa`), il ne les convertit jamais : il n'y a rien à convertir.
 *
 * Le suivi attendu / encaissé / reste est calculé côté serveur et non déduit
 * par le client. Deux clients qui additionneraient différemment afficheraient
 * deux « restes à collecter » — sur de l'argent, c'est inacceptable.
 *
 * @mixin Participation
 */
final class ParticipationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $tally = $this->tally();

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Montant unitaire attendu de chaque membre.
            'expected_amount' => (int) $this->expected_amount,

            'starts_on' => $this->starts_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            // Un simple « en retard » évite à chaque client de recalculer une
            // comparaison de dates, et donc d'en donner deux réponses selon
            // le fuseau du navigateur.
            'is_overdue' => $this->due_on !== null
                && $this->due_on->isPast()
                && $this->status->acceptsPayments(),

            /*
             | Le suivi du bureau, en trois chiffres.
             |
             | `expected` exclut les lignes ANNULÉES : un membre dispensé ne
             | doit pas gonfler le montant que le club croit avoir à recevoir.
             */
            'tally' => [
                'expected_amount' => $tally['expected'],
                'collected_amount' => $tally['collected'],
                'remaining_amount' => $tally['remaining'],
                'members' => $tally['members'],
                'paid_members' => $tally['paid_members'],
                // Calculé ici, une fois : un pourcentage recalculé dans chaque
                // client finit par diverger d'un arrondi.
                'progress_percent' => $tally['expected'] > 0
                    ? round(($tally['collected'] / $tally['expected']) * 100, 1)
                    : 0.0,
            ],

            'event' => $this->whenLoaded('event', fn () => [
                'uuid' => $this->event->uuid,
                'title' => $this->event->title,
                'starts_at' => $this->event->starts_at?->toIso8601String(),
            ]),

            'created_by' => $this->whenLoaded('creator', fn () => [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),

            'lines' => ParticipationLineResource::collection($this->whenLoaded('lines')),

            'permissions' => $viewer === null ? null : [
                'update' => $viewer->can('update', $this->resource),
                'delete' => $viewer->can('delete', $this->resource),
                'assign' => $viewer->can('assign', $this->resource),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
