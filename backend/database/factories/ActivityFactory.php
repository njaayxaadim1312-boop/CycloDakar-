<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\Sport;
use App\Models\Activity;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-6 months', '-1 hour');

        return [
            // Rappel : en production, cet uuid vient du CLIENT. Ici la fabrique
            // le génère, mais la colonne garde le même rôle de clé
            // d'idempotence.
            'uuid' => (string) Str::uuid(),
            'member_id' => Member::factory(),
            'sport' => Sport::Cycling,
            'status' => ActivityStatus::Completed,
            'visibility' => ActivityVisibility::Club,
            'started_at' => $startedAt,
            'ended_at' => (clone $startedAt)->modify('+1 hour'),
        ];
    }

    public function sport(Sport $sport): static
    {
        return $this->state(fn (array $attributes) => ['sport' => $sport]);
    }

    public function recording(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Recording,
            'ended_at' => null,
        ]);
    }

    public function visibility(ActivityVisibility $visibility): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => $visibility]);
    }

    /**
     * Sortie avec des statistiques réalistes.
     *
     * Utile pour les écrans et les classements, où l'on a besoin de chiffres
     * plausibles sans vouloir fabriquer une trace complète.
     */
    public function withStats(int $distanceM = 30000, int $movingTimeS = 5400): static
    {
        return $this->state(fn (array $attributes) => [
            'distance_m' => $distanceM,
            'duration_s' => $movingTimeS + 600,
            'moving_time_s' => $movingTimeS,
            'paused_time_s' => 600,
            'avg_speed_mps' => round($distanceM / $movingTimeS, 3),
            'max_speed_mps' => round(($distanceM / $movingTimeS) * 1.6, 3),
            'elevation_gain_m' => fake()->numberBetween(0, 120),
            'points_count' => (int) ($movingTimeS * 0.9),
            'raw_points_count' => $movingTimeS,
        ]);
    }

    /**
     * Allures renseignees.
     *
     * Volontairement separe de `withStats()` : en production, l'allure n'est
     * calculee que pour la course et la marche. Une sortie velo garde ces
     * colonnes a NULL, et les records doivent savoir l'ignorer.
     */
    public function paces(int $avgSPerKm, ?int $bestSPerKm = null): static
    {
        return $this->state(fn (array $attributes) => [
            'avg_pace_s_per_km' => $avgSPerKm,
            'best_pace_s_per_km' => $bestSPerKm ?? $avgSPerKm,
        ]);
    }
}
