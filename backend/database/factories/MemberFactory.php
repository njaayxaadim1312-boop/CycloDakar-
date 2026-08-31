<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * Compteur de matricules propre à la fabrique.
     *
     * Les tests créent des membres en masse ; passer par MatriculeGenerator
     * (qui pose un verrou d'écriture à chaque appel) les ralentirait sans rien
     * apporter. La génération réelle est éprouvée par ses propres tests.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        self::$sequence++;

        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => null,
            'matricule' => 'CD-'.str_pad((string) self::$sequence, 6, '0', STR_PAD_LEFT),
            'first_name' => fake('fr_FR')->firstName(),
            'last_name' => fake('fr_FR')->lastName(),
            'phone' => '7'.fake()->numberBetween(0, 8).fake()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'joined_at' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'status' => MemberStatus::Active,
            'qr_token' => Member::generateQrToken(),
        ];
    }

    /** Rattache la fiche à un compte de connexion existant. */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
        ]);
    }

    /** Crée aussi le compte de connexion. */
    public function withAccount(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => User::factory()]);
    }

    /** Membre sans smartphone : fiche complète, aucun compte. */
    public function withoutAccount(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }

    public function status(MemberStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function suspended(): static
    {
        return $this->status(MemberStatus::Suspended);
    }

    public function former(): static
    {
        return $this->status(MemberStatus::Former);
    }

    public function named(string $firstName, string $lastName): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}
