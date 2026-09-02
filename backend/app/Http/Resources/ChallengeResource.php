<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Challenge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un défi.
 *
 * `target` et `progress` sortent en **unité SI** — mètres, secondes, nombre de
 * sorties. La conversion vers les kilomètres se fait à l'affichage, comme pour
 * les activités : un champ qui contiendrait tantôt des mètres, tantôt des
 * kilomètres finirait par produire un défi mille fois trop court.
 *
 * `my_progress` est calculé pour le lecteur, et absent s'il ne participe pas.
 * Un zéro signifierait « inscrit, rien fait » — ce n'est pas la même chose que
 * « pas inscrit », et l'interface ne doit pas avoir à deviner.
 *
 * @mixin Challenge
 */
final class ChallengeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $membre = $viewer?->member;

        $inscription = $membre === null
            ? null
            : $this->participants->firstWhere('member_id', $membre->id);

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,

            'metric' => $this->metric->value,
            'metric_label' => $this->metric->label(),
            'unit' => $this->metric->unit(),
            'target' => (int) $this->target,

            'sport' => $this->sport?->value,
            'sport_label' => $this->sport?->label(),

            'icon' => $this->icon,

            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'days_left' => $this->daysLeft(),
            'is_running' => $this->isRunning(),
            'accepts_entries' => $this->acceptsEntries(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'participants' => $this->participants->count(),
            'finishers' => $this->participants->whereNotNull('completed_at')->count(),

            // `null` quand le lecteur ne participe pas : c'est différent d'une
            // progression à zéro.
            'my_progress' => $inscription === null ? null : [
                'value' => (int) $inscription->progress,
                'percent' => $inscription->percent((int) $this->target),
                'completed_at' => $inscription->completed_at?->toIso8601String(),
                'joined_at' => $inscription->joined_at?->toIso8601String(),
            ],

            'created_by' => $this->whenLoaded('creator', fn () => [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),

            // Décidées par le serveur : le client s'en sert pour MASQUER,
            // jamais pour autoriser.
            'permissions' => $viewer === null ? null : [
                'update' => $viewer->can('update', $this->resource),
                'delete' => $viewer->can('delete', $this->resource),
                'join' => $viewer->can('join', $this->resource),
            ],
        ];
    }
}
