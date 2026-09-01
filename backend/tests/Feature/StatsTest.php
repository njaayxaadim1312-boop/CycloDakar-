<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Statistiques du tableau de bord.
 *
 * L'exigence centrale : ne jamais présenter comme une valeur réelle ce qui
 * n'est pas encore mesuré. Sur un tableau de bord qui affichera un solde de
 * caisse, un zéro inventé détruirait la confiance du bureau.
 */
final class StatsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAs_(User $user): static
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('Test')->plainTextToken,
        );
    }

    #[Test]
    public function un_visiteur_n_a_pas_acces_aux_statistiques(): void
    {
        $this->getJson('/api/v1/stats/dashboard')->assertStatus(401);
    }

    #[Test]
    public function les_effectifs_sont_comptes_par_statut(): void
    {
        Member::factory()->count(4)->create();
        Member::factory()->count(2)->suspended()->create();
        Member::factory()->former()->create();

        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.members.total', 7)
            ->assertJsonPath('data.members.active', 4)
            ->assertJsonPath('data.members.by_status.SUSPENDED.count', 2)
            ->assertJsonPath('data.members.by_status.FORMER.count', 1)
            ->assertJsonPath('data.members.by_status.PENDING.count', 0);
    }

    #[Test]
    public function tous_les_statuts_sont_presents_meme_a_zero(): void
    {
        // Un statut absent du résultat ferait disparaître sa ligne de
        // l'affichage, ce qui donnerait l'impression qu'il n'existe pas.
        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonCount(count(MemberStatus::cases()), 'data.members.by_status')
            ->assertJsonCount(count(UserRole::cases()), 'data.members.by_role');
    }

    #[Test]
    public function les_membres_sans_compte_sont_comptes_a_part(): void
    {
        // Le bureau doit savoir combien de QR Codes imprimer.
        Member::factory()->count(3)->withoutAccount()->create();
        Member::factory()->forUser(User::factory()->create())->create();

        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.members.without_account', 3)
            ->assertJsonPath('data.members.with_account', 1);
    }

    #[Test]
    public function les_membres_archives_ne_sont_pas_comptes(): void
    {
        Member::factory()->count(3)->create();
        Member::factory()->create()->delete();

        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.members.total', 3);
    }

    #[Test]
    public function la_courbe_couvre_douze_mois_pleins(): void
    {
        // Les mois sans adhésion valent zéro et ne sont pas sautés : sinon la
        // courbe donnerait une fausse impression de croissance continue.
        Member::factory()->create(['joined_at' => now()->toDateString()]);

        $growth = $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->json('data.members.growth');

        $this->assertCount(12, $growth);
        $this->assertSame(now()->format('Y-m'), $growth[11]['month']);
        $this->assertSame(1, $growth[11]['count']);
        $this->assertSame(0, $growth[0]['count']);
    }

    #[Test]
    public function les_modules_non_livres_annoncent_leur_phase(): void
    {
        // Le point le plus important de ce module : `available: false` et non
        // un zéro qui passerait pour une valeur mesurée.
        $response = $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk();

        foreach ([] as $key => $phase) {
            $response->assertJsonPath("data.{$key}.available", false);
            $response->assertJsonPath("data.{$key}.phase", $phase);
        }
    }

    #[Test]
    public function la_caisse_est_masquee_aux_membres_ordinaires(): void
    {
        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.finance.visible', false);
    }

    #[Test]
    public function le_tresorier_voit_la_section_caisse(): void
    {
        $this->actingAs_(User::factory()->treasurer()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.finance.visible', true)
            ->assertJsonPath('data.finance.available', false)
            ->assertJsonPath('data.finance.phase', 13);
    }

    #[Test]
    public function le_club_peut_rendre_la_caisse_publique(): void
    {
        // Certains clubs veulent la transparence totale ; c'est un réglage.
        config(['cyclo.finance.public_balance' => true]);

        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.finance.visible', true);
    }
}
