<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TransactionDirection;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\Member;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LES CINQ INVARIANTS FINANCIERS.
 *
 * `docs/finance.md` ouvre par cette phrase : « Ces cinq règles sont vérifiées
 * par des tests automatisés. Si l'une casse, le module est considéré comme hors
 * service. » Ce fichier est ce test-là.
 *
 * POURQUOI LES RASSEMBLER ICI ALORS QU'ILS SONT DÉJÀ ÉPROUVÉS AILLEURS.
 *
 * Les suites de paiement, de dépense et de caisse vérifient chacune ce qui la
 * concerne, et c'est très bien. Mais un invariant éprouvé en morceaux à travers
 * quatre fichiers n'est plus lisible comme un invariant : personne ne peut
 * répondre à « la règle I2 est-elle vérifiée ? » sans relire tout le dossier.
 *
 * Ici, chaque règle porte son nom, sa formulation et sa preuve. Un contributeur
 * qui casse l'une d'elles voit tomber un test qui la NOMME — pas un test
 * d'annulation de paiement dont il faudra deviner qu'il gardait autre chose.
 *
 * DEUX D'ENTRE ELLES SONT VÉRIFIÉES STRUCTURELLEMENT, PAS PAR L'EXEMPLE.
 *
 * I5 (« l'argent est en entiers ») lit le SCHÉMA de la base : un exemple ne
 * peut prouver qu'aucune colonne monétaire n'est un flottant, il ne peut que
 * prouver que celles qu'on a pensé à tester ne le sont pas. I2 lit la liste des
 * routes, pour la même raison.
 */
final class InvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceSeeder::class);
    }

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

    /* ====================================================================== */
    /* I1 — Le solde est dérivé, jamais saisi                                 */
    /* ====================================================================== */

    #[Test]
    public function i1_aucune_route_de_l_api_n_accepte_un_solde(): void
    {
        /*
         | Vérifié sur la LISTE DES ROUTES, pas sur trois URL choisies à la
         | main : c'est le seul moyen de couvrir aussi celles qu'on écrira
         | demain. Une route nommée « balance » qui accepte une écriture serait
         | la porte par laquelle la comptabilité du club devient fausse.
         */
        $coupables = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => array_intersect(
                $route->methods(),
                ['POST', 'PUT', 'PATCH', 'DELETE'],
            ) !== [])
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'balance')
                || str_contains($uri, 'solde'))
            ->values();

        $this->assertCount(
            0,
            $coupables,
            "Une route d'écriture porte « balance » : ".$coupables->implode(', '),
        );
    }

    #[Test]
    public function i1_le_solde_se_recalcule_depuis_le_grand_livre(): void
    {
        $caisse = CashAccount::default();

        $this->ecrire($caisse, TransactionDirection::In, 200_000);
        $this->ecrire($caisse, TransactionDirection::Out, 75_000);

        $caisse = $caisse->fresh();

        // La définition même : ouverture + entrées − sorties.
        $this->assertSame(125_000, $caisse->derivedBalance());
        $this->assertSame($caisse->derivedBalance(), $caisse->current_balance);
    }

    #[Test]
    public function i1_un_cache_fausse_est_detecte_et_signale_bruyamment(): void
    {
        /*
         | Un écart ne se produit pas tout seul : il signifie qu'une écriture est
         | passée hors de `CashLedger`. La commande sort donc en ÉCHEC — un code
         | de retour, pas un avertissement dans un journal que personne ne lit.
         */
        $this->ecrire(CashAccount::default(), TransactionDirection::In, 50_000);

        DB::table('cash_accounts')->update(['current_balance' => 1]);

        $this->artisan('finance:recompute-balance')->assertExitCode(1);
    }

    /* ====================================================================== */
    /* I2 — Les écritures sont immuables                                      */
    /* ====================================================================== */

    #[Test]
    public function i2_le_grand_livre_n_a_ni_suppression_douce_ni_route_de_suppression(): void
    {
        /*
         | L'absence de `deleted_at` est structurelle, pas accidentelle : une
         | suppression douce donnerait l'illusion d'un recours propre, et le
         | premier réflexe en cas d'erreur serait d'effacer.
         */
        $this->assertFalse(Schema::hasColumn('financial_transactions', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('payments', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('expenses', 'deleted_at'));

        // Et aucune route ne propose de les supprimer.
        $suppressions = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'payments')
                || str_contains($uri, 'transactions')
                || (str_contains($uri, 'expenses') && ! str_contains($uri, 'attachments')))
            ->values();

        $this->assertCount(
            0,
            $suppressions,
            'Une route DELETE existe sur une table append-only : '.$suppressions->implode(', '),
        );
    }

    #[Test]
    public function i2_une_ecriture_ne_se_modifie_ni_ne_se_supprime(): void
    {
        // La règle est EXÉCUTABLE, pas seulement documentée : le modèle lève.
        $ecriture = $this->ecrire(CashAccount::default(), TransactionDirection::In, 10_000);

        try {
            $ecriture->update(['amount' => 1]);
            $this->fail('Une écriture du grand livre a pu être modifiée.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $ecriture->delete();
            $this->fail('Une écriture du grand livre a pu être supprimée.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function i2_une_correction_passe_par_une_contre_passation_qui_laisse_deux_lignes(): void
    {
        $ecriture = $this->ecrire(CashAccount::default(), TransactionDirection::In, 40_000);

        DB::transaction(fn () => app(\App\Services\Finance\CashLedger::class)
            ->reverse($ecriture, 'Erreur de saisie constatée au pointage.'));

        // Deux lignes, et le solde revenu à zéro. L'historique reste vrai.
        $this->assertSame(2, FinancialTransaction::count());
        $this->assertSame(0, CashAccount::default()->derivedBalance());

        $contrepassation = FinancialTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame($ecriture->id, $contrepassation->reverses_transaction_id);
        $this->assertNotNull($contrepassation->reason);
    }

    /* ====================================================================== */
    /* I3 — Le client n'est jamais la source de vérité                        */
    /* ====================================================================== */

    #[Test]
    public function i3_les_champs_determines_par_le_serveur_ne_sont_jamais_lus_dans_la_requete(): void
    {
        /*
         | On envoie TOUT ce que le serveur détermine lui-même, en une fois, et
         | on vérifie que rien n'est retenu. Les tester un par un laisserait
         | passer celui qu'on oublierait d'ajouter à la liste.
         */
        $collecteur = $this->user(UserRole::Collector);
        $autre = $this->user(UserRole::Collector);

        $participation = \App\Models\Participation::factory()->create([
            'status' => \App\Enums\ParticipationStatus::Open,
            'expected_amount' => 5_000,
            'created_by' => $this->user(UserRole::Treasurer)->id,
        ]);

        $ligne = \App\Models\ParticipationMember::factory()->create([
            'participation_id' => $participation->id,
            'member_id' => Member::factory()->create()->id,
            'expected_amount' => 5_000,
            'assigned_collector_id' => $collecteur->id,
        ]);

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            [
                'member' => $ligne->member->uuid,
                'amount' => 2_000,
                'method' => 'CASH',
                'idempotency_key' => 'invariant-i3',

                // Tout ce qui suit doit être IGNORÉ.
                'collected_by' => $autre->id,
                'paid_amount' => 5_000,
                'status' => 'PAYE',
                'balance_after' => 999_999,
                'receipt_number' => 'RC-0000-000000',
            ],
        )->assertCreated();

        $paiement = Payment::firstOrFail();

        // Le collecteur vient de la SESSION.
        $this->assertSame($collecteur->id, $paiement->collected_by);
        // Le numéro de reçu est attribué par le serveur, sous verrou.
        $this->assertNotSame('RC-0000-000000', $paiement->receipt_number);
        // Le montant payé et le statut sont DÉRIVÉS des paiements réels.
        $this->assertSame(2_000, $ligne->fresh()->paid_amount);
        $this->assertSame('PARTIELLEMENT_PAYE', $ligne->fresh()->status->value);
        // Et le solde figé n'est pas celui qu'on a proposé.
        $this->assertSame(2_000, FinancialTransaction::firstOrFail()->balance_after);
    }

    /* ====================================================================== */
    /* I4 — Une dépense en attente n'affecte pas le solde                     */
    /* ====================================================================== */

    #[Test]
    public function i4_une_depense_en_attente_n_ecrit_rien_et_se_montre_a_part(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'TRANSPORT',
            // Au-dessus du seuil : elle reste en attente.
            'amount' => 80_000,
            'label' => 'Bus Lac Rose',
        ])->assertCreated();

        // Aucune ligne au grand livre, et le solde ne bouge pas.
        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame(0, CashAccount::default()->current_balance);

        // Mais elle est ANNONCÉE, séparément. La cacher ferait décider le
        // trésorier sur un chiffre faux dans l'autre sens.
        $this->actingAs_($tresorier)->getJson('/api/v1/finance/cash')
            ->assertOk()
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.committed', 80_000)
            ->assertJsonPath('data.balance_after_commitments', -80_000);

        // L'écriture naît à l'approbation, et pas avant.
        $uuid = Expense::firstOrFail()->uuid;
        $this->actingAs_($this->user(UserRole::Admin))
            ->postJson("/api/v1/expenses/{$uuid}/approve")->assertOk();

        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(-80_000, CashAccount::default()->current_balance);
    }

    /* ====================================================================== */
    /* I5 — L'argent est en entiers                                           */
    /* ====================================================================== */

    #[Test]
    public function i5_aucune_colonne_monetaire_n_est_un_flottant(): void
    {
        /*
         | LU DANS LE SCHÉMA, ET C'EST TOUT L'INTÉRÊT.
         |
         | Un test par l'exemple ne peut prouver qu'aucune colonne monétaire
         | n'est un flottant : il prouve seulement que celles auxquelles on a
         | pensé ne le sont pas. Celle qu'on ajoutera dans six mois, en
         | `decimal(10,2)` par réflexe, passerait sans que rien ne crie.
         |
         | Ici, la vérification porte sur TOUTE la base : n'importe quelle
         | colonne dont le nom parle d'argent doit être entière.
         */
        $motsMonetaires = ['amount', 'balance', 'price', 'montant', 'solde', 'fee'];
        $typesEntiers = ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'];

        $fautives = [];

        foreach (Schema::getTables() as $table) {
            $nomTable = $table['name'];

            foreach (Schema::getColumns($nomTable) as $colonne) {
                $nom = $colonne['name'];

                $monetaire = collect($motsMonetaires)
                    ->contains(fn (string $mot) => str_contains($nom, $mot));

                if (! $monetaire) {
                    continue;
                }

                $type = mb_strtolower((string) $colonne['type_name']);

                if (! in_array($type, $typesEntiers, true)) {
                    $fautives[] = "{$nomTable}.{$nom} ({$type})";
                }
            }
        }

        $this->assertSame(
            [],
            $fautives,
            "Des colonnes monétaires ne sont pas entières : \n  ".implode("\n  ", $fautives),
        );
    }

    #[Test]
    public function i5_un_montant_decimal_est_refuse_et_jamais_arrondi(): void
    {
        /*
         | Refusé, et non arrondi en silence. Un arrondi discret sur un
         | encaissement fait perdre des francs que personne ne cherchera —
         | et surtout, il fait mentir le reçu remis au membre.
         */
        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'TRANSPORT',
            'amount' => 40_000.75,
            'label' => 'Transport',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
            'category' => 'DON',
            'amount' => 1_500.5,
            'label' => 'Don',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function i5_les_montants_sortent_en_entiers_dans_l_api(): void
    {
        // Un montant sérialisé en chaîne — ce que fait un pilote SQL sur un
        // agrégat — se concaténerait côté client au lieu de s'additionner.
        $this->ecrire(CashAccount::default(), TransactionDirection::In, 12_345);

        $donnees = $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/finance/cash')
            ->assertOk()
            ->json('data');

        foreach (['balance', 'derived_balance', 'committed', 'balance_after_commitments'] as $champ) {
            $this->assertIsInt($donnees[$champ], "« {$champ} » n'est pas un entier.");
        }
    }

    /* ---------------------------------------------------------------------- */

    /** Écrit au grand livre par le seul chemin autorisé. */
    private function ecrire(
        CashAccount $caisse,
        TransactionDirection $sens,
        int $montant,
    ): FinancialTransaction {
        $auteur = User::factory()->create(['role' => UserRole::Treasurer]);
        $this->be($auteur);

        return DB::transaction(fn () => app(\App\Services\Finance\CashLedger::class)->record(
            account: $caisse,
            direction: $sens,
            amount: $montant,
            label: 'Écriture de vérification',
            sourceType: \App\Enums\TransactionSource::Manual,
        ));
    }
}
