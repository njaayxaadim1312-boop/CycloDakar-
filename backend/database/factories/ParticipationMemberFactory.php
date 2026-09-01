<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ParticipationMemberStatus;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParticipationMember>
 */
class ParticipationMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participation_id' => Participation::factory(),
            'member_id' => Member::factory(),
            'expected_amount' => 5_000,
            'paid_amount' => 0,
            'status' => ParticipationMemberStatus::Unpaid,
        ];
    }

    /**
     * Ligne partiellement reglee.
     *
     * `paid_amount` est normalement DERIVE des paiements. La fabrique l'ecrit
     * directement parce qu'il n'existe pas encore de paiements (phase 12) et
     * qu'il faut bien pouvoir eprouver les cumuls et les statuts. C'est le
     * seul endroit du projet ou ce raccourci est acceptable.
     */
    public function paid(int $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'paid_amount' => $amount,
            'status' => ParticipationMemberStatus::derive(
                (int) ($attributes['expected_amount'] ?? 5_000),
                $amount,
            ),
            'last_payment_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ParticipationMemberStatus::Cancelled,
        ]);
    }
}
