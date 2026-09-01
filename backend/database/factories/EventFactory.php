<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventDifficulty;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /** Lieux de départ réels du club, pour des jeux d'essai crédibles. */
    private const PLACES = [
        'Place de la Nation',
        'Corniche Ouest',
        'Monument de la Renaissance',
        'Rond-point Ema',
        'Plage de Yoff',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'title' => 'Sortie '.fake()->unique()->numerify('###'),
            'description' => fake('fr_FR')->sentence(12),
            'sport' => Sport::Cycling,

            // À VENIR par défaut : c'est l'état dans lequel la quasi-totalité
            // des tests a besoin d'un événement, puisque les inscriptions y
            // sont ouvertes.
            'starts_at' => now()->addDays(7)->setTime(7, 30),
            'ends_at' => now()->addDays(7)->setTime(11, 0),

            'location_name' => fake()->randomElement(self::PLACES),
            'start_lat' => 14.6928,
            'start_lng' => -17.4467,
            'planned_distance_m' => 35_000,
            'difficulty' => EventDifficulty::Medium,
            'status' => EventStatus::Published,
            'max_participants' => null,
            'created_by' => User::factory(),
        ];
    }

    public function status(EventStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function draft(): static
    {
        return $this->status(EventStatus::Draft);
    }

    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Ongoing,
            'starts_at' => now()->subHour(),
        ]);
    }

    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Done,
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeeks(2)->addHours(3),
        ]);
    }

    /** Sortie à places limitées — le cas qui met la file d'attente à l'épreuve. */
    public function limitedTo(int $seats): static
    {
        return $this->state(fn (array $attributes) => ['max_participants' => $seats]);
    }

    public function sport(Sport $sport): static
    {
        return $this->state(fn (array $attributes) => ['sport' => $sport]);
    }
}
