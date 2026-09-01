<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ParticipationStatus;
use App\Models\Participation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Participation>
 */
class ParticipationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => 'Collecte '.fake()->unique()->numerify('###'),
            'description' => fake('fr_FR')->sentence(10),
            // Un montant realiste pour le club : 5 000 FCFA la sortie.
            'expected_amount' => 5_000,
            'starts_on' => now()->toDateString(),
            'due_on' => now()->addWeeks(2)->toDateString(),
            'status' => ParticipationStatus::Open,
            'created_by' => User::factory(),
        ];
    }

    public function status(ParticipationStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function draft(): static
    {
        return $this->status(ParticipationStatus::Draft);
    }

    public function closed(): static
    {
        return $this->status(ParticipationStatus::Closed);
    }

    public function amount(int $fcfa): static
    {
        return $this->state(fn (array $attributes) => ['expected_amount' => $fcfa]);
    }

    /** Echeance depassee : le cas qui declenche les relances. */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => now()->subMonth()->toDateString(),
            'due_on' => now()->subWeek()->toDateString(),
        ]);
    }
}
