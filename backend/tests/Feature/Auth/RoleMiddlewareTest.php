<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contrôle d'accès par rôle.
 *
 * Le middleware raisonne en rôle MINIMUM : `role:TREASURER` laisse passer le
 * trésorier, l'administrateur et le super administrateur. Sans cela, il
 * faudrait énumérer les rôles supérieurs sur chaque route financière — et
 * l'oubli finirait par arriver.
 */
final class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Routes de test : on éprouve le middleware lui-même, indépendamment
        // des modules métier qui n'existent pas encore.
        Route::middleware(['api', 'auth:sanctum', 'active', 'role:TREASURER'])
            ->get('/api/test/finance', fn () => response()->json(['ok' => true]));

        Route::middleware(['api', 'auth:sanctum', 'active', 'role:ADMIN'])
            ->get('/api/test/admin', fn () => response()->json(['ok' => true]));
    }

    private function tokenPour(UserRole $role): string
    {
        return User::factory()->role($role)->create()->createToken('Test')->plainTextToken;
    }

    /** @return array<string, array{UserRole, int}> */
    public static function accesFinance(): array
    {
        return [
            'membre refuse' => [UserRole::Member, 403],
            'collecteur refuse' => [UserRole::Collector, 403],
            'tresorier autorise' => [UserRole::Treasurer, 200],
            'administrateur autorise' => [UserRole::Admin, 200],
            'super administrateur autorise' => [UserRole::SuperAdmin, 200],
        ];
    }

    #[Test]
    #[DataProvider('accesFinance')]
    public function le_module_financier_est_reserve_au_tresorier_et_au_dessus(
        UserRole $role,
        int $attendu,
    ): void {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenPour($role))
            ->getJson('/api/test/finance')
            ->assertStatus($attendu);
    }

    #[Test]
    public function un_tresorier_n_accede_pas_a_l_administration(): void
    {
        // La hiérarchie fonctionne dans un seul sens.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenPour(UserRole::Treasurer))
            ->getJson('/api/test/admin')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    #[Test]
    public function un_visiteur_est_refuse_avant_meme_le_controle_de_role(): void
    {
        $this->getJson('/api/test/finance')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function un_compte_desactive_est_refuse_meme_avec_le_bon_role(): void
    {
        $user = User::factory()->role(UserRole::Admin)->inactive()->create();
        $token = $user->createToken('Test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/test/admin')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    #[Test]
    public function le_message_de_refus_indique_le_role_attendu(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenPour(UserRole::Member))
            ->getJson('/api/test/finance')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Cette action est réservée aux comptes « Trésorier » et au-delà.']);
    }

    /* ---------------------------------------------------------------------- */
    /* Hiérarchie des rôles                                                   */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function la_hierarchie_des_roles_est_coherente(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->atLeast(UserRole::Member));
        $this->assertTrue(UserRole::Admin->atLeast(UserRole::Treasurer));
        $this->assertTrue(UserRole::Treasurer->atLeast(UserRole::Collector));
        $this->assertTrue(UserRole::Collector->atLeast(UserRole::Member));

        $this->assertFalse(UserRole::Member->atLeast(UserRole::Collector));
        $this->assertFalse(UserRole::Collector->atLeast(UserRole::Treasurer));
        $this->assertFalse(UserRole::Treasurer->atLeast(UserRole::Admin));
    }

    #[Test]
    public function les_capacites_derivent_du_role(): void
    {
        $this->assertFalse(UserRole::Member->canCollect());
        $this->assertTrue(UserRole::Collector->canCollect());

        $this->assertFalse(UserRole::Collector->canManageFinance());
        $this->assertTrue(UserRole::Treasurer->canManageFinance());

        $this->assertFalse(UserRole::Treasurer->isAdmin());
        $this->assertTrue(UserRole::Admin->isAdmin());
    }
}
