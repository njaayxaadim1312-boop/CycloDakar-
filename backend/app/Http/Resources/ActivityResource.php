<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation d'une activité.
 *
 * Toutes les grandeurs sont en **unités SI** : mètres, secondes, m/s. La
 * conversion en km, km/h et min/km appartient au client, qui connaît la
 * langue et les préférences de l'utilisateur.
 *
 * La `polyline` (trace simplifiée, ~1 Ko) est toujours présente ; les points
 * bruts ne sont jamais renvoyés par cette ressource — ils pèsent 500 fois plus
 * lourd et ne servent qu'à l'export GPX et au rendu vidéo.
 *
 * @mixin Activity
 */
final class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer?->member?->id === $this->member_id;

        return [
            'uuid' => $this->uuid,
            'title' => $this->displayTitle(),
            'custom_title' => $this->title,
            'notes' => $this->when($isOwner, $this->notes),

            'sport' => $this->sport->value,
            'sport_label' => $this->sport->label(),
            'uses_pace' => $this->sport->usesPace(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'visibility' => $this->visibility->value,
            'visibility_label' => $this->visibility->label(),

            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            // Unités SI, sans exception. Voir docs/api.md §1.
            'distance_m' => (int) $this->distance_m,
            'duration_s' => (int) $this->duration_s,
            'moving_time_s' => (int) $this->moving_time_s,
            'paused_time_s' => (int) $this->paused_time_s,
            'avg_speed_mps' => (float) $this->avg_speed_mps,
            'max_speed_mps' => (float) $this->max_speed_mps,
            'elevation_gain_m' => (int) $this->elevation_gain_m,
            'elevation_loss_m' => (int) $this->elevation_loss_m,
            'min_altitude_m' => $this->min_altitude_m,
            'max_altitude_m' => $this->max_altitude_m,
            'avg_pace_s_per_km' => $this->avg_pace_s_per_km,
            'best_pace_s_per_km' => $this->best_pace_s_per_km,
            'calories_kcal' => $this->calories_kcal,

            'polyline' => $this->polyline,
            'bounds' => $this->bounds,
            'start' => $this->coordinate($this->start_lat, $this->start_lng),
            'end' => $this->coordinate($this->end_lat, $this->end_lng),
            'zones' => $this->zones ?? [],

            'points_count' => (int) $this->points_count,

            /*
             * Nombre de points reçus avant filtrage, et taux de rejet.
             *
             * Réservé au propriétaire de la sortie : c'est SA mesure de la
             * qualité du signal, et le premier élément à regarder quand une
             * trace lui paraît fausse. Le montrer aux autres n'apporterait
             * rien et exposerait la qualité de son matériel.
             */
            'signal' => $this->when($isOwner, fn () => [
                'raw_points_count' => (int) $this->raw_points_count,
                'filtered_out' => max(0, (int) $this->raw_points_count - (int) $this->points_count),
                'quality_percent' => $this->raw_points_count > 0
                    ? (int) round(($this->points_count / $this->raw_points_count) * 100)
                    : null,
            ]),

            'member' => $this->whenLoaded('member', fn () => [
                'uuid' => $this->member->uuid,
                'full_name' => $this->member->fullName(),
                'initials' => $this->member->initials(),
                'photo_url' => $this->member->photoUrl(),
            ]),

            'stats' => $this->whenLoaded('stats', fn () => [
                'splits' => $this->stats->splits ?? [],
                'elevation_profile' => $this->stats->elevation_profile ?? [],
                'speed_histogram' => $this->stats->speed_histogram ?? [],
            ]),

            'synced_at' => $this->synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'permissions' => $this->when($viewer !== null, fn () => [
                'update' => $viewer->can('update', $this->resource),
                'delete' => $viewer->can('delete', $this->resource),
            ]),
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function coordinate(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}
