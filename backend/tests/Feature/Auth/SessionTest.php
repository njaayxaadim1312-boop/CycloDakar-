<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gestion de session : jetons, déconnexion, comptes désactivés.
 */
final class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function connecte(User $user, string $device = 'Test'): string
    {
        return $user->createToken($device)->plainTextToken;
    }

    #[Test]
    public function une_route_protegee_refuse_les_visiteurs(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function un_jeton_invalide_est_refuse(): void
    {
        $this->withHeader('Authorization', 'Bearer jeton-invente')
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    #[Test]
    public function la_deconnexion_ne_revoque_que_l_appareil_courant(): void
    {
        // Se déconnecter du web ne doit pas couper le téléphone en pleine
        // sortie GPS.
        $user = User::factory()->create();
        $web = $this->connecte($user, 'Navigateur');
        $mobile = $this->connecte($user, 'Telephone');

        $this->withHeader('Authorization', "Bearer {$web}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$web}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$mobile}")
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function on_peut_se_deconnecter_de_tous_les_appareils(): void
    {
        // Le geste à faire quand on perd son téléphone.
        $user = User::factory()->create();
        $web = $this->connecte($user, 'Navigateur');
        $mobile = $this->connecte($user, 'Telephone');

        $this->withHeader('Authorization', "Bearer {$web}")
            ->postJson('/api/v1/auth/logout', ['all_devices' => true])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$mobile}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    #[Test]
    public function un_compte_desactive_perd_l_acces_immediatement(): void
    {
        // Point important : le jeton était valide AVANT la désactivation.
        // Sans le middleware `active`, il resterait utilisable.
        $user = User::factory()->create();
        $token = $this->connecte($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk();

        // `is_active` n'est volontairement pas assignable en masse : une
        // desactivation est un acte d'administration, pas un champ de
        // formulaire. On passe donc par forceFill.
        $user->forceFill(['is_active' => false])->save();

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');

        // Le jeton est révoqué au passage.
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    #[Test]
    public function la_route_me_expose_le_role_et_les_capacites(): void
    {
        $user = User::factory()->role(UserRole::Collector)->create();
        $token = $this->connecte($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'COLLECTOR')
            ->assertJsonPath('data.role_label', 'Collecteur')
            ->assertJsonPath('data.abilities.collect', true)
            ->assertJsonPath('data.abilities.manage_finance', false)
            ->assertJsonPath('data.abilities.administer', false);
    }

    #[Test]
    public function l_identifiant_interne_n_est_jamais_expose(): void
    {
        // On expose l'uuid, jamais l'auto-incrément : sinon on pourrait
        // énumérer les comptes et connaître leur nombre.
        $user = User::factory()->create();
        $token = $this->connecte($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->assertArrayNotHasKey('id', $response->json('data'));
        $this->assertSame($user->uuid, $response->json('data.uuid'));
    }

    #[Test]
    public function chaque_compte_recoit_un_identifiant_public_unique(): void
    {
        $users = User::factory()->count(5)->create();
        $uuids = $users->pluck('uuid')->all();

        $this->assertCount(5, array_filter($uuids));
        $this->assertCount(5, array_unique($uuids));
    }

    #[Test]
    public function le_mot_de_passe_est_hache_et_jamais_stocke_en_clair(): void
    {
        $user = User::factory()->create(['password' => 'cyclo2026']);

        $this->assertNotSame('cyclo2026', $user->password);
        $this->assertTrue(Hash::check('cyclo2026', $user->password));
    }
}
