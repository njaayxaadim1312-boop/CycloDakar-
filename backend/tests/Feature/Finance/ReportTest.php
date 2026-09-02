<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rapports financiers.
 *
 * Un rapport se juge à une seule chose : **est-il vrai, et le restera-t-il ?**
 * Un rapport de septembre ressorti en décembre doit donner le même chiffre.
 *
 * D'où ce qui est éprouvé ici :
 *
 * 1. **Le solde d'ouverture est celui de la DATE MÉTIER**, pas de la saisie.
 *    Une opération de septembre saisie en octobre appartient à septembre.
 * 2. **Les totaux se recomposent** : ouverture + recettes − dépenses = clôture.
 *    Quelqu'un vérifiera l'addition à la main en assemblée.
 * 3. **L'engagé n'entre dans aucun total** : une dépense en attente n'a aucune
 *    ligne au grand livre (règle I4).
 * 4. **Les exports sortent des fichiers réellement ouvrables** — et le CSV
 *    porte sa BOM, sans quoi Excel affiche du charabia à la place des accents.
 */
final class ReportTest extends TestCase
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
     * Une recette et une dépense approuvée, à deux dates métier distinctes.
     *
     * Les dates sont relatives à AUJOURD'HUI et toujours dans le passé. Les
     * ancrer sur le début du mois rendait le test dépendant du jour où on le
     * lance : le 2 du mois, « début du mois + 5 jours » est dans le futur, et
     * le serveur refuse à juste titre d'enregistrer une opération qui n'a pas
     * encore eu lieu.
     */
    private function mouvements(User $tresorier, User $admin): void
    {
        $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
            'category' => 'DON',
            'amount' => 200_000,
            'label' => 'Don de la mairie',
            'occurred_on' => now()->subDays(3)->toDateString(),
        ])->assertCreated();

        $depense = $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'TRANSPORT',
            'amount' => 80_000,
            'label' => 'Bus Lac Rose',
            'spent_on' => now()->subDays(2)->toDateString(),
        ])->json('data.uuid');

        $this->actingAs_($admin)->postJson("/api/v1/expenses/{$depense}/approve")->assertOk();
    }

    /** La période qui encadre les mouvements ci-dessus, quel que soit le jour. */
    private function periode(): string
    {
        return 'period=custom&from='.now()->subDays(5)->toDateString()
            .'&to='.now()->toDateString();
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function les_totaux_du_rapport_se_recomposent(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $this->mouvements($tresorier, $this->user(UserRole::Admin));

        $rapport = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/reports?'.$this->periode())
            ->assertOk()
            ->json('data');

        $synthese = $rapport['summary'];

        $this->assertSame(0, $synthese['opening_balance']);
        $this->assertSame(200_000, $synthese['income']);
        $this->assertSame(80_000, $synthese['expenses']);
        $this->assertSame(120_000, $synthese['net']);

        // L'addition qu'un trésorier refera à la main en assemblée.
        $this->assertSame(
            $synthese['opening_balance'] + $synthese['income'] - $synthese['expenses'],
            $synthese['closing_balance'],
        );
    }

    #[Test]
    public function le_solde_d_ouverture_suit_la_date_metier_pas_la_saisie(): void
    {
        /*
         | C'est la propriété qui rend un rapport STABLE. Une opération du mois
         | d'avant la période, saisie aujourd'hui, appartient à cette période
         | d'avant : elle doit donc apparaître dans le solde d'OUVERTURE, pas
         | dans les recettes. Sans cela, un rapport de septembre ressorti en
         | décembre donnerait un autre chiffre.
         */
        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)->postJson('/api/v1/finance/income', [
            'category' => 'DON',
            'amount' => 500_000,
            'label' => 'Don du mois dernier, saisi en retard',
            'occurred_on' => now()->subDays(30)->toDateString(),
        ])->assertCreated();

        $synthese = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/reports?'.$this->periode())
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(500_000, $synthese['opening_balance']);
        // Et surtout : elle n'est PAS comptée dans les recettes du mois.
        $this->assertSame(0, $synthese['income']);
        $this->assertSame(500_000, $synthese['closing_balance']);
    }

    #[Test]
    public function une_depense_en_attente_n_entre_dans_aucun_total(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'MEDICAL',
            'amount' => 60_000,
            'label' => 'Assistance médicale',
        ])->assertCreated();

        $synthese = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/reports?'.$this->periode())
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(0, $synthese['expenses']);
        $this->assertSame(0, $synthese['closing_balance']);
        // Annoncé À PART, et daté du jour de l'édition : une dépense en attente
        // n'a pas encore de date de sortie.
        $this->assertSame(60_000, $synthese['committed_today']);
    }

    #[Test]
    public function le_rapport_ventile_par_poste_et_liste_les_operations(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $this->mouvements($tresorier, $this->user(UserRole::Admin));

        $rapport = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/reports?'.$this->periode())
            ->assertOk()
            ->json('data');

        $this->assertSame('Dons', $rapport['by_category']['income'][0]['name']);
        $this->assertSame(200_000, $rapport['by_category']['income'][0]['amount']);
        $this->assertSame('Transport', $rapport['by_category']['expenses'][0]['name']);

        $this->assertCount(2, $rapport['entries']);
        // Les opérations sont dans l'ordre de lecture : par date métier.
        $this->assertSame(200_000, $rapport['entries'][0]['income']);
        $this->assertSame(80_000, $rapport['entries'][1]['expense']);

        // La courbe ne garde que les jours où il s'est passé quelque chose.
        $this->assertCount(2, $rapport['daily']);
        $this->assertSame(200_000, $rapport['daily'][0]['balance']);
        $this->assertSame(120_000, $rapport['daily'][1]['balance']);
    }

    #[Test]
    public function les_periodes_nommees_se_resolvent(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);

        foreach (['day', 'week', 'month', 'year'] as $periode) {
            $this->actingAs_($tresorier)
                ->getJson("/api/v1/finance/reports?period={$periode}")
                ->assertOk()
                ->assertJsonStructure(['data' => ['period' => ['from', 'to', 'label']]]);
        }

        // La semaine commence le LUNDI : un rapport hebdomadaire qui
        // commencerait le dimanche couperait la sortie du dimanche matin.
        $semaine = $this->actingAs_($tresorier)
            ->getJson('/api/v1/finance/reports?period=week')
            ->json('data.period');

        $this->assertSame(
            'Monday',
            \Illuminate\Support\Carbon::parse($semaine['from'])->format('l'),
        );
    }

    #[Test]
    public function une_periode_trop_large_est_refusee_plutot_que_de_tomber(): void
    {
        // Un rapport « depuis toujours » finirait par faire tomber la requête
        // au moment où l'on en a le plus besoin — la veille d'une assemblée.
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/finance/reports?period=custom&from=2010-01-01&to=2026-12-31')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_TOO_WIDE');
    }

    /* ---------------------------------------------------------------------- */
    /* Exports                                                                */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function le_csv_porte_sa_bom_et_son_point_virgule(): void
    {
        /*
         | Trois octets décident de tout. Sans la BOM UTF-8, Excel lit le
         | fichier en Windows-1252 et « Ravitaillement » devient illisible.
         | Et sur un Windows français, un CSV à virgules s'ouvre en UNE seule
         | colonne, parce que la virgule y est le séparateur décimal.
         */
        $tresorier = $this->user(UserRole::Treasurer);
        $this->mouvements($tresorier, $this->user(UserRole::Admin));

        $reponse = $this->actingAs_($tresorier)
            ->get('/api/v1/finance/reports?'.$this->periode().'&format=csv')
            ->assertOk();

        $contenu = $reponse->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        $this->assertStringContainsString('Date;Opération;Poste', $contenu);
        $this->assertStringContainsString('Don de la mairie', $contenu);
        $this->assertStringContainsString('attachment;', $reponse->headers->get('content-disposition'));
    }

    #[Test]
    public function l_excel_sort_un_classeur_reellement_ouvrable(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $this->mouvements($tresorier, $this->user(UserRole::Admin));

        $reponse = $this->actingAs_($tresorier)
            ->get('/api/v1/finance/reports?'.$this->periode().'&format=xlsx')
            ->assertOk();

        $contenu = $reponse->streamedContent();

        // Un .xlsx est une archive ZIP : les deux premiers octets le disent.
        $this->assertStringStartsWith('PK', $contenu);
        $this->assertGreaterThan(3_000, strlen($contenu));

        // Et on le relit vraiment, pour vérifier que les montants sont des
        // NOMBRES — du texte donnerait zéro à la première somme du trésorier.
        $fichier = tempnam(sys_get_temp_dir(), 'cyclo').'.xlsx';
        file_put_contents($fichier, $contenu);

        $classeur = \PhpOffice\PhpSpreadsheet\IOFactory::load($fichier);

        $this->assertSame('Synthèse', $classeur->getSheet(0)->getTitle());
        $this->assertSame('Opérations', $classeur->getSheet(1)->getTitle());

        $recettes = $classeur->getSheet(0)->getCell('B6')->getValue();
        $this->assertIsNumeric($recettes);
        $this->assertSame(200_000, (int) $recettes);

        unlink($fichier);
    }

    #[Test]
    public function le_pdf_sort_un_document_valide(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $this->mouvements($tresorier, $this->user(UserRole::Admin));

        $reponse = $this->actingAs_($tresorier)
            ->get('/api/v1/finance/reports?'.$this->periode().'&format=pdf')
            ->assertOk();

        $contenu = $reponse->getContent();

        $this->assertStringStartsWith('%PDF-', $contenu);
        $this->assertGreaterThan(2_000, strlen($contenu));
        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
    }

    #[Test]
    public function les_rapports_ne_sont_pas_ouverts_a_un_simple_membre(): void
    {
        config(['cyclo.finance.public_balance' => false]);

        $this->actingAs_($this->user(UserRole::Member))
            ->getJson('/api/v1/finance/reports?period=month')
            ->assertForbidden();

        $this->actingAs_($this->user(UserRole::Collector))
            ->getJson('/api/v1/finance/reports?period=month')
            ->assertForbidden();
    }
}
