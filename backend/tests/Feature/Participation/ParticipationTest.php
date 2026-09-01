<?php

declare(strict_types=1);

namespace Tests\Feature\Participation;

use App\Enums\MemberStatus;
use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Campagnes de collecte.
 *
 * On touche à l'argent du club : ce qui est vérifié ici n'est pas du confort
 * d'affichage mais l'intégrité comptable.
 *
 * 1. **Les montants sont des entiers de FCFA.** Un décimal est refusé, pas
 *    arrondi en silence.
 * 2. **`paid_amount` et `status` ne sont jamais reçus du client.** Les
 *    accepter laisserait quiconque se déclarer à jour de cotisation.
 * 3. **Le montant est figé à l'affectation.** Relever le tarif ne réécrit pas
 *    les dettes déjà créées.
 * 4. **On ne supprime pas ce qui a reçu de l'argent.** On annule.
 */
final class ParticipationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ouvre une session.
     *
     * `forgetAuthenticatedUser` est indispensable : le garde met en cache
     * l'utilisateur résolu à la première requête du test, et sans cet oubli
     * tous les appels suivants agiraient au nom du premier connecté.
     */
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
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sortie Lac Rose',
            'expected_amount' => 5_000,
            'starts_on' => now()->toDateString(),
            'due_on' => now()->addWeeks(2)->toDateString(),
        ], $overrides);
    }

    /* ----------------------------------------------------------------- accès */

    #[Test]
    public function un_visiteur_ne_voit_pas_les_collectes(): void
    {
        $this->getJson('/api/v1/participations')->assertStatus(401);
    }

    #[Test]
    public function un_membre_ordinaire_ne_voit_pas_les_collectes(): void
    {
        // L'argent du club ne s'affiche pas à qui n'a pas à le collecter.
        // Le membre verra SA dette dans son espace, en phase 12.
        $this->actingAs_($this->user(UserRole::Member))
            ->getJson('/api/v1/participations')
            ->assertStatus(403);
    }

    #[Test]
    public function un_collecteur_voit_les_collectes_mais_n_en_cree_pas(): void
    {
        // Un collecteur encaisse ; il ne décide pas de ce que le club demande
        // à ses membres.
        Participation::factory()->create();

        $collecteur = $this->user(UserRole::Collector);

        $this->actingAs_($collecteur)->getJson('/api/v1/participations')->assertOk();

        $this->actingAs_($collecteur)
            ->postJson('/api/v1/participations', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function un_tresorier_cree_une_collecte(): void
    {
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/participations', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Sortie Lac Rose')
            ->assertJsonPath('data.expected_amount', 5_000)
            // Une collecte naît en brouillon : on relit avant d'engager le club.
            ->assertJsonPath('data.status', 'DRAFT');
    }

    #[Test]
    public function l_auteur_vient_de_la_session_pas_du_corps(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $autre = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs_($tresorier)
            ->postJson('/api/v1/participations', $this->payload(['created_by' => $autre->id]))
            ->assertStatus(201);

        $this->assertSame($tresorier->id, Participation::first()->created_by);
    }

    /* --------------------------------------------------------------- argent */

    #[Test]
    public function un_montant_decimal_est_refuse_pas_arrondi(): void
    {
        // Le franc CFA n'a pas de centime. Un arrondi silencieux sur de
        // l'argent est pire qu'un refus.
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/participations', $this->payload(['expected_amount' => 5000.5]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.expected_amount.0',
                'Le montant doit être un nombre entier de francs CFA, sans décimale.',
            );
    }

    #[Test]
    public function une_collecte_a_zero_franc_est_refusee(): void
    {
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/participations', $this->payload(['expected_amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_amount');
    }

    #[Test]
    public function un_montant_manifestement_total_est_refuse(): void
    {
        // 2 millions par membre : c'est un total pris pour une part unitaire.
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/participations', $this->payload(['expected_amount' => 90_000_000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_amount');
    }

    #[Test]
    public function l_echeance_ne_precede_pas_le_debut(): void
    {
        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson('/api/v1/participations', $this->payload([
                'starts_on' => now()->addWeek()->toDateString(),
                'due_on' => now()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_on');
    }

    /* ----------------------------------------------------------- brouillons */

    #[Test]
    public function un_brouillon_reste_invisible_des_collecteurs(): void
    {
        Participation::factory()->draft()->create(['name' => 'En preparation']);
        Participation::factory()->create(['name' => 'Ouverte']);

        $response = $this->actingAs_($this->user(UserRole::Collector))
            ->getJson('/api/v1/participations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Ouverte', $response->json('data.0.name'));
    }

    #[Test]
    public function la_liste_par_defaut_ecarte_les_collectes_closes(): void
    {
        // Elles n'appellent plus aucune action et encombreraient la liste.
        Participation::factory()->create(['name' => 'En cours']);
        Participation::factory()->closed()->create(['name' => 'Soldee']);

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/participations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/participations?scope=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /* --------------------------------------------------------- transitions */

    #[Test]
    public function une_collecte_s_ouvre_puis_se_cloture(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->draft()->create(['created_by' => $tresorier->id]);

        $this->actingAs_($tresorier)
            ->patchJson("/api/v1/participations/{$collecte->uuid}/status", ['status' => 'OPEN'])
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN');

        $this->actingAs_($tresorier)
            ->patchJson("/api/v1/participations/{$collecte->uuid}/status", ['status' => 'CLOSED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');
    }

    #[Test]
    public function une_collecte_close_ne_se_rouvre_pas(): void
    {
        // Les comptes ont été arrêtés. Y rajouter une recette après coup
        // fausserait un rapport peut-être déjà présenté en assemblée.
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->closed()->create(['created_by' => $tresorier->id]);

        $this->actingAs_($tresorier)
            ->patchJson("/api/v1/participations/{$collecte->uuid}/status", ['status' => 'OPEN'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    #[Test]
    public function une_collecte_close_ne_se_modifie_plus(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->closed()->create(['created_by' => $tresorier->id]);

        $this->actingAs_($tresorier)
            ->patchJson("/api/v1/participations/{$collecte->uuid}", ['name' => 'Reecriture'])
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------- affectation */

    #[Test]
    public function sans_liste_ce_sont_tous_les_membres_actifs(): void
    {
        // Le geste le plus fréquent : la cotisation annuelle. On ne demande
        // pas au bureau de cocher 250 cases pour dire « tout le monde ».
        Member::factory()->count(4)->create();
        Member::factory()->count(2)->suspended()->create();
        Member::factory()->former()->create();

        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);

        $response = $this->actingAs_($tresorier)
            ->postJson("/api/v1/participations/{$collecte->uuid}/members")
            ->assertOk();

        // 4 membres créés ici + la fiche du trésorier, tous actifs.
        $actifs = Member::where('status', MemberStatus::Active)->count();

        $this->assertSame($actifs, $response->json('meta.created'));
        $this->assertSame($actifs, ParticipationMember::count());
    }

    #[Test]
    public function une_seconde_affectation_ne_cree_pas_de_doublon(): void
    {
        // Le bureau doit pouvoir rattraper un oubli sans crainte.
        Member::factory()->count(3)->create();

        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);
        $url = "/api/v1/participations/{$collecte->uuid}/members";

        $this->actingAs_($tresorier)->postJson($url)->assertOk();
        $premier = ParticipationMember::count();

        $response = $this->actingAs_($tresorier)->postJson($url)->assertOk();

        $this->assertSame(0, $response->json('meta.created'));
        $this->assertSame($premier, $response->json('meta.skipped'));
        $this->assertSame($premier, ParticipationMember::count());
    }

    #[Test]
    public function le_montant_est_fige_a_l_affectation(): void
    {
        // Relever le tarif ne doit pas réécrire les dettes déjà créées :
        // sinon un versement de 5 000 apparaîtrait partiel sur une dette
        // rétroactivement portée à 7 500.
        $membre = Member::factory()->create();

        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->amount(5_000)->create(['created_by' => $tresorier->id]);

        $this->actingAs_($tresorier)->postJson(
            "/api/v1/participations/{$collecte->uuid}/members",
            ['members' => [$membre->uuid]],
        )->assertOk();

        $this->actingAs_($tresorier)->patchJson(
            "/api/v1/participations/{$collecte->uuid}",
            ['expected_amount' => 7_500],
        )->assertOk();

        $ligne = ParticipationMember::where('member_id', $membre->id)->firstOrFail();

        $this->assertSame(5_000, $ligne->expected_amount);
    }

    #[Test]
    public function un_montant_individualise_est_possible(): void
    {
        $membre = Member::factory()->create();

        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->amount(5_000)->create(['created_by' => $tresorier->id]);

        $this->actingAs_($tresorier)->postJson(
            "/api/v1/participations/{$collecte->uuid}/members",
            ['members' => [$membre->uuid], 'amount' => 2_500],
        )->assertOk();

        $this->assertSame(
            2_500,
            ParticipationMember::where('member_id', $membre->id)->firstOrFail()->expected_amount,
        );
    }

    #[Test]
    public function on_ne_rattache_plus_personne_a_une_collecte_close(): void
    {
        Member::factory()->create();

        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->closed()->create(['created_by' => $tresorier->id]);

        // La policy refuse d'abord, avant même la règle métier : une collecte
        // close ne se modifie plus du tout.
        $this->actingAs_($tresorier)
            ->postJson("/api/v1/participations/{$collecte->uuid}/members")
            ->assertStatus(403);
    }

    /* ------------------------------------------------------- champs derives */

    #[Test]
    public function le_client_ne_peut_pas_se_declarer_a_jour(): void
    {
        // La falsification la plus simple imaginable sur cette application.
        $membre = Member::factory()->create();

        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);

        $ligne = ParticipationMember::factory()->create([
            'participation_id' => $collecte->id,
            'member_id' => $membre->id,
        ]);

        $this->actingAs_($tresorier)->patchJson(
            "/api/v1/participations/{$collecte->uuid}/members/{$ligne->id}",
            ['paid_amount' => 5_000, 'status' => 'PAYE'],
        )->assertOk();

        $ligne->refresh();

        $this->assertSame(0, $ligne->paid_amount);
        $this->assertSame(ParticipationMemberStatus::Unpaid, $ligne->status);
    }

    #[Test]
    public function le_suivi_exclut_les_lignes_annulees(): void
    {
        // Un membre dispensé ne doit pas gonfler le montant que le club croit
        // avoir à recevoir.
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->amount(5_000)->create(['created_by' => $tresorier->id]);

        ParticipationMember::factory()->count(3)->create([
            'participation_id' => $collecte->id,
            'expected_amount' => 5_000,
        ]);
        ParticipationMember::factory()->cancelled()->create([
            'participation_id' => $collecte->id,
            'expected_amount' => 5_000,
        ]);

        $this->actingAs_($tresorier)
            ->getJson("/api/v1/participations/{$collecte->uuid}")
            ->assertOk()
            ->assertJsonPath('data.tally.expected_amount', 15_000)
            ->assertJsonPath('data.tally.members', 3)
            ->assertJsonPath('data.tally.remaining_amount', 15_000);
    }

    #[Test]
    public function le_suivi_compte_ce_qui_a_ete_encaisse(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->amount(5_000)->create(['created_by' => $tresorier->id]);

        ParticipationMember::factory()->create([
            'participation_id' => $collecte->id, 'expected_amount' => 5_000,
        ]);
        ParticipationMember::factory()->paid(5_000)->create([
            'participation_id' => $collecte->id, 'expected_amount' => 5_000,
        ]);
        ParticipationMember::factory()->paid(2_000)->create([
            'participation_id' => $collecte->id, 'expected_amount' => 5_000,
        ]);

        $this->actingAs_($tresorier)
            ->getJson("/api/v1/participations/{$collecte->uuid}")
            ->assertOk()
            ->assertJsonPath('data.tally.expected_amount', 15_000)
            ->assertJsonPath('data.tally.collected_amount', 7_000)
            ->assertJsonPath('data.tally.remaining_amount', 8_000)
            ->assertJsonPath('data.tally.paid_members', 1);
    }

    /* ------------------------------------------------------------ retraits */

    #[Test]
    public function une_ligne_sans_paiement_est_supprimee(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);
        $ligne = ParticipationMember::factory()->create(['participation_id' => $collecte->id]);

        $this->actingAs_($tresorier)
            ->deleteJson("/api/v1/participations/{$collecte->uuid}/members/{$ligne->id}")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'deleted');

        $this->assertDatabaseMissing('participation_members', ['id' => $ligne->id]);
    }

    #[Test]
    public function une_ligne_deja_payee_est_annulee_et_non_supprimee(): void
    {
        // Supprimer laisserait un paiement orphelin : de l'argent encaissé
        // sans dette correspondante. Un contrôle en assemblée ne pardonne pas.
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);
        $ligne = ParticipationMember::factory()->paid(3_000)->create([
            'participation_id' => $collecte->id,
        ]);

        $this->actingAs_($tresorier)
            ->deleteJson("/api/v1/participations/{$collecte->uuid}/members/{$ligne->id}")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'cancelled');

        $this->assertSame(
            ParticipationMemberStatus::Cancelled,
            $ligne->fresh()->status,
        );
    }

    #[Test]
    public function une_collecte_ayant_recu_des_paiements_ne_se_supprime_pas(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);
        ParticipationMember::factory()->paid(5_000)->create(['participation_id' => $collecte->id]);

        $this->actingAs_($tresorier)
            ->deleteJson("/api/v1/participations/{$collecte->uuid}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'HAS_PAYMENTS');
    }

    #[Test]
    public function une_ligne_d_une_autre_collecte_est_refusee(): void
    {
        // Sans ce contrôle, l'identifiant d'une ligne suffirait à agir sur une
        // collecte qu'on n'a pas le droit de toucher.
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);
        $autre = Participation::factory()->create();
        $ligne = ParticipationMember::factory()->create(['participation_id' => $autre->id]);

        $this->actingAs_($tresorier)
            ->deleteJson("/api/v1/participations/{$collecte->uuid}/members/{$ligne->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'LINE_NOT_FOUND');
    }

    /* ----------------------------------------------------------- terrain --- */

    #[Test]
    public function un_collecteur_voit_ce_qu_il_doit_aller_chercher(): void
    {
        // La vraie question du jour J : « qui dois-je aller voir ? ».
        $collecteur = $this->user(UserRole::Collector);
        $autre = User::factory()->create(['role' => UserRole::Collector]);

        $collecte = Participation::factory()->amount(5_000)->create();

        ParticipationMember::factory()->count(2)->create([
            'participation_id' => $collecte->id,
            'expected_amount' => 5_000,
            'assigned_collector_id' => $collecteur->id,
        ]);
        ParticipationMember::factory()->create([
            'participation_id' => $collecte->id,
            'assigned_collector_id' => $autre->id,
        ]);
        // Déjà soldée : elle n'appelle plus de déplacement.
        ParticipationMember::factory()->paid(5_000)->create([
            'participation_id' => $collecte->id,
            'expected_amount' => 5_000,
            'assigned_collector_id' => $collecteur->id,
        ]);

        $this->actingAs_($collecteur)
            ->getJson('/api/v1/participations/mine')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.lines', 2)
            ->assertJsonPath('meta.remaining_amount', 10_000);
    }

    #[Test]
    public function une_dispense_sort_du_montant_attendu(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->amount(5_000)->create(['created_by' => $tresorier->id]);
        $ligne = ParticipationMember::factory()->create([
            'participation_id' => $collecte->id,
            'expected_amount' => 5_000,
        ]);

        $this->actingAs_($tresorier)->patchJson(
            "/api/v1/participations/{$collecte->uuid}/members/{$ligne->id}",
            ['exempt' => true, 'note' => 'Membre en convalescence'],
        )->assertOk();

        $this->actingAs_($tresorier)
            ->getJson("/api/v1/participations/{$collecte->uuid}")
            ->assertOk()
            ->assertJsonPath('data.tally.expected_amount', 0)
            ->assertJsonPath('data.tally.members', 0);
    }

    #[Test]
    public function un_montant_inferieur_au_deja_paye_est_refuse(): void
    {
        // Sinon la ligne afficherait un trop-perçu inexplicable.
        $tresorier = $this->user(UserRole::Treasurer);
        $collecte = Participation::factory()->create(['created_by' => $tresorier->id]);
        $ligne = ParticipationMember::factory()->paid(4_000)->create([
            'participation_id' => $collecte->id,
            'expected_amount' => 5_000,
        ]);

        $this->actingAs_($tresorier)->patchJson(
            "/api/v1/participations/{$collecte->uuid}/members/{$ligne->id}",
            ['expected_amount' => 1_000],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_AMOUNT');
    }
}
