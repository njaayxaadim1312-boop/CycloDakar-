<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Haché une seule fois : bcrypt coût 12 sur chaque enregistrement rendrait les tests très lents. */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake('fr_FR')->name(),
            'email' => fake()->unique()->safeEmail(),
            // Numéro sénégalais plausible : 7X suivi de 7 chiffres.
            'phone' => '7'.fake()->numberBetween(0, 8).fake()->numerify('#######'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Member,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function collector(): static
    {
        return $this->role(UserRole::Collector);
    }

    public function treasurer(): static
    {
        return $this->role(UserRole::Treasurer);
    }

    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /** Membre inscrit avec son seul numéro, sans adresse email. */
    public function phoneOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
        ]);
    }
}
