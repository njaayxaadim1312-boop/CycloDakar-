<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\ExpenseStatus;
use App\Enums\TransactionDirection;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dépenses.
 *
 * Quatre propriétés doivent tenir, et chacune correspond à une manière
 * concrète de perdre de l'argent ou la confiance du bureau.
 *
 * 1. **Une dépense en attente ne bouge PAS le solde** (règle I4). Elle est une
 *    intention, pas un mouvement.
 * 2. **Le double regard** : on n'approuve pas sa propre dépense. C'est la
 *    protection de celui qui tient la caisse, qui doit pouvoir montrer qu'il
 *    n'a jamais décidé seul.
 * 3. **Le seuil ne protège que s'il ne s'ouvre pas** : sous 25 000 FCFA, un
 *    trésorier passe seul — un collecteur, jamais.
 * 4. **Un justificatif n'est pas public.** Une facture porte un fournisseur,
 *    un montant, parfois un numéro de compte.
 */
final class ExpenseTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'TRANSPORT',
            // Au-dessus du seuil de 25 000 : la dépense reste en attente.
            'amount' => 80_000,
            'label' => 'Transport Lac Rose',
            'spent_on' => now()->toDateString(),
        ], $overrides);
    }

    private function balance(): int
    {
        return CashAccount::default()->current_balance;
    }

    /* ---------------------------------------------------------------------- */
    /* I4 — une dépense en attente ne bouge pas le solde                      */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_depense_en_attente_n_ecrit_rien_au_grand_livre(): void
    {
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/expenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', ExpenseStatus::Pending->value)
            ->assertJsonPath('data.is_commitment', true)
            ->assertJsonPath('data.moved_money', false);

        // C'est TOUT le point de la règle I4.
        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function l_approbation_ecrit_au_grand_livre_et_sort_l_argent(): void
    {
        $demandeur = $this->user(UserRole::Treasurer);
        $approbateur = $this->user(UserRole::Admin);

        $uuid = $this->actingAs_($demandeur)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($approbateur)
            ->postJson("/api/v1/expenses/{$uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ExpenseStatus::Approved->value)
            ->assertJsonPath('data.moved_money', true);

        $ecriture = FinancialTransaction::query()->firstOrFail();
        $this->assertSame(TransactionDirection::Out, $ecriture->direction);
        $this->assertSame(80_000, $ecriture->amount);
        // Le solde d'ouverture est nul : la caisse passe en négatif, et c'est
        // la vérité — le club a dépensé de l'argent qu'il n'a pas encaissé ici.
        $this->assertSame(-80_000, $ecriture->balance_after);
        $this->assertSame(-80_000, $this->balance());
    }

    #[Test]
    public function le_solde_distingue_le_disponible_de_l_engage(): void
    {
        // Deux dépenses : une approuvée, une en attente. Les confondre ferait
        // décider le trésorier sur un chiffre faux.
        $demandeur = $this->user(UserRole::Treasurer);
        $admin = $this->user(UserRole::Admin);

        $approuvee = $this->actingAs_($demandeur)
            ->postJson('/api/v1/expenses', $this->payload(['amount' => 30_000]))
            ->json('data.uuid');

        $this->actingAs_($admin)->postJson("/api/v1/expenses/{$approuvee}/approve")->assertOk();

        $this->actingAs_($demandeur)
            ->postJson('/api/v1/expenses', $this->payload(['amount' => 45_000]))
            ->assertCreated();

        $this->actingAs_($admin)->getJson('/api/v1/finance/cash')
            ->assertOk()
            ->assertJsonPath('data.balance', -30_000)
            ->assertJsonPath('data.committed', 45_000)
            ->assertJsonPath('data.balance_after_commitments', -75_000)
            ->assertJsonPath('data.pending_expenses', 1)
            // Le module est complet : recettes et dépenses passent au grand livre.
            ->assertJsonPath('data.complete', true);
    }

    /* ---------------------------------------------------------------------- */
    /* Le double regard                                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function on_n_approuve_pas_sa_propre_depense(): void
    {
        /*
         | Sans cette règle, un trésorier sortirait n'importe quelle somme de
         | la caisse en deux clics, seul, sans que personne n'ait rien vu.
         |
         | Ce n'est pas une supposition de malhonnêteté : c'est la protection
         | de celui qui tient la caisse, qui doit pouvoir montrer qu'il n'a
         | jamais décidé seul.
         */
        $tresorier = $this->user(UserRole::Treasurer);

        $uuid = $this->actingAs_($tresorier)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($tresorier)
            ->postJson("/api/v1/expenses/{$uuid}/approve")
            ->assertForbidden();

        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame(ExpenseStatus::Pending, Expense::firstOrFail()->status);
    }

    #[Test]
    public function on_ne_refuse_pas_non_plus_sa_propre_depense(): void
    {
        /*
         | Symétrique et volontaire. Si l'on pouvait refuser sans pouvoir
         | approuver, il suffirait de saisir puis de refuser pour faire
         | disparaître une demande gênante sans laisser de décideur au journal.
         */
        $tresorier = $this->user(UserRole::Treasurer);

        $uuid = $this->actingAs_($tresorier)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($tresorier)
            ->postJson("/api/v1/expenses/{$uuid}/reject", ['reason' => 'Je retire ma demande.'])
            ->assertForbidden();
    }

    #[Test]
    public function une_depense_refusee_reste_avec_son_motif(): void
    {
        $demandeur = $this->user(UserRole::Treasurer);
        $admin = $this->user(UserRole::Admin);

        $uuid = $this->actingAs_($demandeur)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($admin)
            ->postJson("/api/v1/expenses/{$uuid}/reject", [
                'reason' => 'Le transport est déjà couvert par le sponsor Wave.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ExpenseStatus::Rejected->value)
            ->assertJsonPath('data.decision_reason', 'Le transport est déjà couvert par le sponsor Wave.');

        // Aucune écriture, et la ligne existe toujours : le bureau doit
        // pouvoir expliquer pourquoi 80 000 FCFA n'ont pas été engagés.
        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame(1, Expense::count());
    }

    #[Test]
    public function le_motif_de_refus_est_obligatoire(): void
    {
        $uuid = $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($this->user(UserRole::Admin))
            ->postJson("/api/v1/expenses/{$uuid}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function une_depense_deja_decidee_ne_se_redecide_pas(): void
    {
        $demandeur = $this->user(UserRole::Treasurer);
        $admin = $this->user(UserRole::Admin);

        $uuid = $this->actingAs_($demandeur)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($admin)->postJson("/api/v1/expenses/{$uuid}/approve")->assertOk();

        // Sans ce garde-fou, une seconde approbation sortirait deux fois la
        // même somme de la caisse.
        $this->actingAs_($admin)->postJson("/api/v1/expenses/{$uuid}/approve")->assertForbidden();

        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(-80_000, $this->balance());
    }

    /* ---------------------------------------------------------------------- */
    /* Le seuil d'auto-approbation                                            */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_petite_depense_de_tresorier_passe_seule(): void
    {
        /*
         | Un circuit de validation pour 3 000 FCFA d'eau minérale ne serait
         | pas suivi, et une règle qu'on contourne protège moins qu'une règle
         | proportionnée.
         */
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/expenses', $this->payload([
                'category' => 'RAVITAILLEMENT',
                'amount' => 3_000,
                'label' => 'Eau minérale',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', ExpenseStatus::Approved->value);

        $this->assertSame(-3_000, $this->balance());
    }

    #[Test]
    public function le_seuil_ne_s_ouvre_pas_a_qui_n_a_pas_la_caisse(): void
    {
        // Un collecteur ne saisit pas de dépense du tout : l'argent qui SORT
        // relève du bureau, celui qui ENTRE du terrain.
        $this->actingAs_($this->user(UserRole::Collector))
            ->postJson('/api/v1/expenses', $this->payload(['amount' => 1_000]))
            ->assertForbidden();

        $this->assertSame(0, Expense::count());
    }

    #[Test]
    public function un_chef_de_groupe_ne_touche_pas_aux_depenses(): void
    {
        $chef = $this->user(UserRole::RideLeader);

        $this->actingAs_($chef)->getJson('/api/v1/expenses')->assertForbidden();
        $this->actingAs_($chef)->postJson('/api/v1/expenses', $this->payload())->assertForbidden();
        $this->actingAs_($chef)->getJson('/api/v1/finance/dashboard')->assertForbidden();
    }

    /* ---------------------------------------------------------------------- */
    /* Cohérence comptable                                                    */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_depense_ne_peut_pas_se_ranger_sous_un_poste_de_recette(): void
    {
        // Classer une dépense en recette produirait un rapport annuel faux
        // sans qu'aucune règle ne s'en aperçoive.
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/expenses', $this->payload(['category' => 'DON']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EXPENSE_REFUSED');
    }

    #[Test]
    public function un_montant_decimal_est_refuse(): void
    {
        // Règle I5 : aucun flottant ne touche l'argent, à aucun étage.
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/expenses', $this->payload(['amount' => 40_000.75]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function le_montant_d_une_depense_approuvee_est_fige(): void
    {
        $demandeur = $this->user(UserRole::Treasurer);
        $uuid = $this->actingAs_($demandeur)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($this->user(UserRole::Admin))
            ->postJson("/api/v1/expenses/{$uuid}/approve")->assertOk();

        // Le changer laisserait au grand livre une écriture qui ne
        // correspondrait plus à sa pièce.
        $this->expectException(\LogicException::class);

        Expense::firstOrFail()->update(['amount' => 10]);
    }

    #[Test]
    public function la_verification_du_solde_reste_coherente_apres_une_depense(): void
    {
        $uuid = $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($this->user(UserRole::Admin))
            ->postJson("/api/v1/expenses/{$uuid}/approve")->assertOk();

        $this->artisan('finance:recompute-balance')->assertExitCode(0);
    }

    /* ---------------------------------------------------------------------- */
    /* Justificatifs                                                          */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_justificatif_va_sur_le_disque_prive_et_non_dans_public(): void
    {
        /*
         | Une facture porte un fournisseur, un montant, parfois un numéro de
         | compte. Dans `public/storage`, elle serait lisible par quiconque
         | devine l'URL — sans authentification et sans trace.
         */
        Storage::fake('local');
        Storage::fake('public');

        $tresorier = $this->user(UserRole::Treasurer);

        $uuid = $this->actingAs_($tresorier)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $reponse = $this->actingAs_($tresorier)
            ->post("/api/v1/expenses/{$uuid}/attachments", [
                'file' => UploadedFile::fake()->create('facture-transport.pdf', 120, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'facture-transport.pdf');

        $chemin = \App\Models\ExpenseAttachment::firstOrFail()->path;

        Storage::disk('local')->assertExists($chemin);
        Storage::disk('public')->assertMissing($chemin);

        // Et l'URL renvoyée passe par une route contrôlée, pas par /storage.
        $this->assertStringContainsString("/expenses/{$uuid}/attachments/", $reponse->json('data.url'));
    }

    #[Test]
    public function un_simple_membre_ne_telecharge_pas_un_justificatif(): void
    {
        Storage::fake('local');

        $tresorier = $this->user(UserRole::Treasurer);

        $uuid = $this->actingAs_($tresorier)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $piece = $this->actingAs_($tresorier)
            ->post("/api/v1/expenses/{$uuid}/attachments", [
                'file' => UploadedFile::fake()->create('facture.pdf', 50, 'application/pdf'),
            ])
            ->json('data.uuid');

        $this->actingAs_($this->user(UserRole::Member))
            ->getJson("/api/v1/expenses/{$uuid}/attachments/{$piece}")
            ->assertForbidden();

        $this->actingAs_($tresorier)
            ->get("/api/v1/expenses/{$uuid}/attachments/{$piece}")
            ->assertOk();
    }

    #[Test]
    public function un_fichier_qui_n_est_ni_image_ni_pdf_est_refuse(): void
    {
        Storage::fake('local');

        $tresorier = $this->user(UserRole::Treasurer);

        $uuid = $this->actingAs_($tresorier)
            ->postJson('/api/v1/expenses', $this->payload())
            ->json('data.uuid');

        $this->actingAs_($tresorier)
            ->post("/api/v1/expenses/{$uuid}/attachments", [
                'file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }
}
