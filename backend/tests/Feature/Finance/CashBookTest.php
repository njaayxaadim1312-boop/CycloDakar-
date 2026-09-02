<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\FinancialTransaction;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Journal de caisse, tableau de bord et recettes manuelles.
 *
 * Ce qui est éprouvé ici tient en une phrase : **aucun total n'est stocké.**
 * Recettes, dépenses, engagé, reste à percevoir — tout se recalcule depuis le
 * grand livre à chaque appel. Deux chiffres qui finiraient par se contredire,
 * c'est la confiance du bureau perdue, et elle ne revient pas.
 *
 * Le journal, lui, est la pièce que l'on imprime en assemblée générale. Sa
 * colonne « Solde » est LUE, jamais recalculée à l'affichage : c'est ce qui
 * garantit qu'un journal se réimprime identique six mois plus tard.
 */
final class CashBookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceSeeder::class);
    }

    /* ---------------------------------------------------------------------- */

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Member::factory()->for($user)->create();

        return $user;
    }

    /* ---------------------------------------------------------------------- */
    /* Recettes manuelles                                                     */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_recette_manuelle_entre_directement_au_grand_livre(): void
    {
        /*
         | Pas de circuit de validation, contrairement aux dépenses.
         | L'asymétrie est voulue : de l'argent qui entre ne peut pas appauvrir
         | le club, et exiger un double regard pour enregistrer un don ferait
         | perdre la trace du don.
         */
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/finance/income', [
                'category' => 'DON',
                'amount' => 150_000,
                'label' => 'Don de la mairie de Dakar',
            ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'IN')
            ->assertJsonPath('data.amount', 150_000)
            ->assertJsonPath('data.balance_after', 150_000)
            ->assertJsonPath('data.source_type', 'manual');

        $this->assertSame(150_000, CashAccount::default()->current_balance);
    }

    #[Test]
    public function une_recette_ne_peut_pas_se_ranger_sous_un_poste_de_depense(): void
    {
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/finance/income', [
                'category' => 'TRANSPORT',
                'amount' => 10_000,
                'label' => 'Erreur de poste',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CATEGORY');

        $this->assertSame(0, FinancialTransaction::count());
    }

    #[Test]
    public function un_collecteur_n_enregistre_pas_de_don(): void
    {
        // Un collecteur encaisse des cotisations, pas des dons : ces deux
        // gestes n'ont ni le même contrôle ni la même pièce.
        $this->actingAs_($this->user(UserRole::Collector))
            ->postJson('/api/v1/finance/income', [
                'category' => 'DON',
                'amount' => 10_000,
                'label' => 'Don',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function une_recette_sans_libelle_est_refusee(): void
    {
        // Un don anonyme et sans libellé n'est pas auditable : dans six mois,
        // personne ne saura d'où venaient ces 150 000 FCFA.
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/finance/income', ['category' => 'DON', 'amount' => 150_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('label');
    }

    /* ---------------------------------------------------------------------- */
    /* Journal de caisse                                                      */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function le_journal_montre_les_deux_sens_et_le_solde_fige(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $admin = $this->user(UserRole::Admin);

        $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
            'category' => 'DON', 'amount' => 100_000, 'label' => 'Don Wave',
        ])->assertCreated();

        $depense = $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'TRANSPORT', 'amount' => 40_000, 'label' => 'Bus Lac Rose',
        ])->json('data.uuid');

        $this->actingAs_($admin)->postJson("/api/v1/expenses/{$depense}/approve")->assertOk();

        $reponse = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/transactions')
            ->assertOk();

        $lignes = $reponse->json('data');
        $this->assertCount(2, $lignes);

        // La colonne « Solde » est LUE, pas recalculée : 100 000 puis 60 000.
        $soldes = array_column($lignes, 'balance_after');
        sort($soldes);
        $this->assertSame([60_000, 100_000], $soldes);

        $this->assertSame(60_000, CashAccount::default()->current_balance);
    }

    #[Test]
    public function le_journal_se_filtre_par_sens_et_par_poste(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);

        foreach ([['DON', 100_000], ['SPONSORING', 50_000]] as [$poste, $montant]) {
            $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
                'category' => $poste, 'amount' => $montant, 'label' => "Recette {$poste}",
            ])->assertCreated();
        }

        $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/transactions?category=DON')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 100_000);

        $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/transactions?direction=OUT')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function le_journal_n_est_pas_ouvert_a_un_simple_membre(): void
    {
        config(['cyclo.finance.public_balance' => false]);

        $this->actingAs_($this->user(UserRole::Member))
            ->getJson('/api/v1/finance/transactions')
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------- */
    /* Tableau de bord                                                        */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function le_tableau_de_bord_calcule_tout_depuis_le_grand_livre(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $admin = $this->user(UserRole::Admin);

        $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
            'category' => 'DON', 'amount' => 200_000, 'label' => 'Don annuel',
        ])->assertCreated();

        $approuvee = $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'TRANSPORT', 'amount' => 80_000, 'label' => 'Transport',
        ])->json('data.uuid');

        $this->actingAs_($admin)->postJson("/api/v1/expenses/{$approuvee}/approve")->assertOk();

        // Une dépense en attente : elle N'ENTRE PAS dans les dépenses de la
        // période, seulement dans l'engagé.
        $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'MEDICAL', 'amount' => 30_000, 'label' => 'Assistance médicale',
        ])->assertCreated();

        $this->actingAs_($admin)
            ->getJson('/api/v1/finance/dashboard')
            ->assertOk()
            ->assertJsonPath('data.income', 200_000)
            ->assertJsonPath('data.expenses', 80_000)
            ->assertJsonPath('data.net', 120_000)
            ->assertJsonPath('data.balance', 120_000)
            // L'engagé est montré À PART, jamais déduit du solde en base.
            ->assertJsonPath('data.committed', 30_000)
            ->assertJsonPath('data.balance_after_commitments', 90_000);
    }

    #[Test]
    public function le_tableau_de_bord_ventile_par_poste(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);

        foreach ([['DON', 100_000], ['SPONSORING', 60_000]] as [$poste, $montant]) {
            $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
                'category' => $poste, 'amount' => $montant, 'label' => "Recette {$poste}",
            ])->assertCreated();
        }

        $ventilation = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/dashboard')
            ->assertOk()
            ->json('data.by_category');

        $postes = collect($ventilation)->keyBy('code');

        $this->assertSame(100_000, $postes['DON']['amount']);
        $this->assertSame(60_000, $postes['SPONSORING']['amount']);
        $this->assertSame('IN', $postes['DON']['direction']);
    }

    #[Test]
    public function le_reste_a_percevoir_n_est_pas_compte_comme_de_l_argent_en_caisse(): void
    {
        /*
         | Une créance n'est pas de la trésorerie. Les additionner ferait croire
         | au bureau qu'il peut engager une dépense sur de l'argent qui n'est
         | pas arrivé — l'erreur classique, et celle qui coule un club.
         */
        $tresorier = $this->user(UserRole::Treasurer);

        $participation = \App\Models\Participation::factory()->create([
            'status' => \App\Enums\ParticipationStatus::Open,
            'expected_amount' => 5_000,
            'created_by' => $tresorier->id,
        ]);

        \App\Models\ParticipationMember::factory()->count(3)->create([
            'participation_id' => $participation->id,
            'expected_amount' => 5_000,
        ]);

        $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balance', 0)
            // 15 000 FCFA attendus, et ils sont annoncés SÉPARÉMENT.
            ->assertJsonPath('data.receivable', 15_000);
    }

    #[Test]
    public function les_postes_du_grand_livre_sont_exposes_avec_leur_sens(): void
    {
        $postes = $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/finance/categories')
            ->assertOk()
            ->json('data');

        $codes = array_column($postes, 'code');

        $this->assertContains('PARTICIPATION', $codes);
        $this->assertContains('TRANSPORT', $codes);

        // Le sens fait partie du poste : sans lui, un client proposerait
        // « Transport » dans un formulaire de recette.
        $parCode = collect($postes)->keyBy('code');
        $this->assertSame('IN', $parCode['PARTICIPATION']['direction']);
        $this->assertSame('OUT', $parCode['TRANSPORT']['direction']);
    }
}
