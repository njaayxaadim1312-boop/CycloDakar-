<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recherche de membres.
 *
 * C'est LE besoin exprimé par le club : « ne plus obliger le collecteur à
 * écrire les noms à la main ». La recherche doit donc trouver quelqu'un à
 * partir de n'importe quoi — trois lettres du prénom, le nom, le numéro de
 * matricule, ou le téléphone quelle que soit sa mise en forme.
 */
final class MemberSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $collector = User::factory()->collector()->create();
        $this->token = $collector->createToken('Test')->plainTextToken;

        Member::factory()->named('Khadim', 'Ndiaye')->create([
            'matricule' => 'CD-000042',
            'phone' => '771234567',
            'email' => 'khadim@cyclodakar.sn',
        ]);
        Member::factory()->named('Khadim', 'Fall')->create([
            'matricule' => 'CD-000043',
            'phone' => '772222222',
        ]);
        Member::factory()->named('Awa', 'Sow')->create([
            'matricule' => 'CD-000044',
            'phone' => '773333333',
        ]);
    }

    private function search(string $term): array
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/members/search?q='.urlencode($term))
            ->assertOk()
            ->json('data');
    }

    #[Test]
    public function trois_lettres_du_prenom_suffisent(): void
    {
        // C'est exactement l'exemple du cahier des charges : « Kha » doit
        // ramener Khadim Ndiaye et Khadim Fall.
        $results = $this->search('Kha');

        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            ['Khadim Ndiaye', 'Khadim Fall'],
            array_column($results, 'full_name'),
        );
    }

    #[Test]
    public function on_peut_chercher_par_nom_de_famille(): void
    {
        $results = $this->search('Ndiaye');

        $this->assertCount(1, $results);
        $this->assertSame('Khadim Ndiaye', $results[0]['full_name']);
    }

    #[Test]
    public function on_peut_chercher_le_nom_complet_dans_les_deux_sens(): void
    {
        $this->assertCount(1, $this->search('Khadim Ndiaye'));
        $this->assertCount(1, $this->search('Ndiaye Khadim'));
    }

    #[Test]
    public function on_peut_chercher_par_matricule_complet(): void
    {
        $results = $this->search('CD-000042');

        $this->assertCount(1, $results);
        $this->assertSame('CD-000042', $results[0]['matricule']);
    }

    #[Test]
    public function le_numero_seul_du_matricule_suffit(): void
    {
        // Sur le terrain, on lit « 42 » sur une liste papier, pas « CD-000042 ».
        $results = $this->search('42');

        $this->assertNotEmpty($results);
        $this->assertContains('CD-000042', array_column($results, 'matricule'));
    }

    #[Test]
    public function on_peut_chercher_par_telephone_quelle_que_soit_sa_forme(): void
    {
        foreach (['771234567', '77 123 45 67', '+221771234567', '00221 77 123 45 67'] as $variant) {
            $results = $this->search($variant);

            $this->assertCount(1, $results, "Échec pour la saisie « {$variant} »");
            $this->assertSame('Khadim Ndiaye', $results[0]['full_name']);
        }
    }

    #[Test]
    public function on_peut_chercher_par_email(): void
    {
        $results = $this->search('khadim@cyclodakar.sn');

        $this->assertCount(1, $results);
        $this->assertSame('Khadim Ndiaye', $results[0]['full_name']);
    }

    #[Test]
    public function la_recherche_terrain_ecarte_les_anciens_membres(): void
    {
        // Un ancien membre n'a pas à polluer une collecte en cours. Il reste
        // consultable dans l'annuaire complet.
        Member::factory()->named('Khadim', 'Diallo')->former()->create();

        $results = $this->search('Kha');

        $this->assertCount(2, $results);
        $this->assertNotContains('Khadim Diallo', array_column($results, 'full_name'));
    }

    #[Test]
    public function la_recherche_terrain_ne_renvoie_que_l_essentiel(): void
    {
        // Charge utile réduite : le collecteur est sur un réseau mobile
        // parfois médiocre, et n'a besoin que de reconnaître la personne.
        $results = $this->search('Kha');

        $this->assertEqualsCanonicalizing(
            ['uuid', 'matricule', 'full_name', 'initials', 'phone_formatted', 'photo_url', 'status'],
            array_keys($results[0]),
        );
    }

    #[Test]
    public function une_recherche_sans_resultat_renvoie_une_liste_vide(): void
    {
        $this->assertSame([], $this->search('Zzzzzz'));
    }

    /* ---------------------------------------------------------------------- */
    /* Annuaire complet                                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function l_annuaire_est_pagine_et_trie_par_nom(): void
    {
        // La pagination accepte au minimum 5 par page : des pages plus
        // petites multiplieraient les allers-retours reseau pour rien.
        Member::factory()->count(4)->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/members?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.has_more', true);

        $names = array_column($response->json('data'), 'last_name');
        $sorted = $names;
        sort($sorted);

        $this->assertSame($sorted, $names);
    }

    #[Test]
    public function l_annuaire_se_filtre_par_statut(): void
    {
        Member::factory()->count(2)->suspended()->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/members?status='.MemberStatus::Suspended->value)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function l_annuaire_se_filtre_par_role(): void
    {
        $treasurer = User::factory()->treasurer()->create();
        Member::factory()->forUser($treasurer)->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/members?role='.UserRole::Treasurer->value)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame('TREASURER', $response->json('data.0.account.role'));
    }

    #[Test]
    public function on_peut_isoler_les_membres_sans_compte(): void
    {
        // Les adhérents sans smartphone : ils existent, et le bureau doit
        // pouvoir les lister pour leur remettre leur QR Code imprimé.
        $user = User::factory()->create();
        Member::factory()->forUser($user)->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/members?has_account=0')
            ->assertOk()
            // Les 3 du setUp n'ont pas de compte.
            ->assertJsonPath('meta.total', 3);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/members?has_account=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function un_visiteur_ne_peut_pas_consulter_l_annuaire(): void
    {
        $this->getJson('/api/v1/members')->assertStatus(401);
        $this->getJson('/api/v1/members/search?q=Kha')->assertStatus(401);
    }
}
