<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegisterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function un_membre_peut_s_inscrire_avec_son_seul_numero(): void
    {
        // Cas le plus courant au club : pas d'adresse email.
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Awa Ndiaye',
            'phone' => '77 123 45 67',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user' => ['uuid', 'name', 'role']]])
            ->assertJsonPath('data.user.role', 'MEMBER')
            ->assertJsonPath('data.user.phone', '771234567')
            ->assertJsonPath('data.user.phone_formatted', '77 123 45 67');

        $this->assertDatabaseHas('users', [
            'name' => 'Awa Ndiaye',
            'phone' => '771234567',
            'role' => 'MEMBER',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function un_membre_peut_s_inscrire_avec_son_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Moussa Diop',
            'email' => 'Moussa.Diop@Example.SN',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])->assertCreated();

        // L'email est mis en minuscules : sinon le même compte pourrait être
        // recréé avec une casse différente.
        $this->assertDatabaseHas('users', ['email' => 'moussa.diop@example.sn']);
    }

    #[Test]
    public function l_inscription_exige_au_moins_un_identifiant(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sans Contact',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    #[Test]
    public function un_numero_deja_pris_est_refuse_quelle_que_soit_son_ecriture(): void
    {
        User::factory()->create(['phone' => '771234567']);

        // Écriture différente, même personne : le doublon doit être bloqué.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Doublon',
            'phone' => '+221 77 123 45 67',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    #[Test]
    public function un_numero_invalide_est_signale_clairement(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mauvais Numero',
            'phone' => '12345',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    #[Test]
    public function un_mot_de_passe_trop_faible_est_refuse(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Faible',
            'phone' => '771234567',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function un_mot_de_passe_sans_chiffre_est_refuse(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sans Chiffre',
            'phone' => '771234567',
            'password' => 'motdepasse',
            'password_confirmation' => 'motdepasse',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function on_ne_peut_pas_s_attribuer_un_role_a_l_inscription(): void
    {
        // Tentative d'élévation de privilège par un champ de formulaire.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Malin',
            'phone' => '771234567',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
            'role' => UserRole::SuperAdmin->value,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'MEMBER');

        $this->assertSame(UserRole::Member, User::firstWhere('phone', '771234567')->role);
    }

    #[Test]
    public function le_jeton_recu_permet_immediatement_d_appeler_l_api(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Awa Ndiaye',
            'phone' => '771234567',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Awa Ndiaye');
    }
}
