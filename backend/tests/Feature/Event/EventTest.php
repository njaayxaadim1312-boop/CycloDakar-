<?php

declare(strict_types=1);

namespace Tests\Feature\Event;

use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sorties officielles : création, visibilité, cycle de vie.
 *
 * Le point sensible de cet écran n'est pas la saisie, c'est **qui voit quoi**.
 * Un brouillon échappé au bureau — une date annoncée puis déplacée — coûte plus
 * de confiance qu'une annonce tardive.
 */
final class EventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ouvre une session pour ce compte.
     *
     * `forgetAuthenticatedUser` est indispensable : dans un test, plusieurs
     * requêtes se succèdent dans le MÊME processus, et le garde garde en
     * mémoire l'utilisateur résolu à la première. Sans cet oubli, tous les
     * appels suivants agiraient au nom du premier membre connecté — et les
     * tests de liste d'attente vérifieraient dix fois la même inscription en
     * croyant en tester dix différentes.
     */
    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function member(): User
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        Member::factory()->for($user)->create();

        return $user;
    }

    private function collector(): User
    {
        $user = User::factory()->create(['role' => UserRole::Collector]);
        Member::factory()->for($user)->create();

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Grand Tour Cyclo Dakar',
            'sport' => Sport::Cycling->value,
            'starts_at' => now()->addWeek()->setTime(7, 30)->toIso8601String(),
            'location_name' => 'Place de la Nation',
            'planned_distance_m' => 35_000,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- accès */

    #[Test]
    public function un_visiteur_ne_voit_pas_les_sorties(): void
    {
        $this->getJson('/api/v1/events')->assertStatus(401);
    }

    #[Test]
    public function un_membre_ordinaire_ne_peut_pas_creer_de_sortie(): void
    {
        $this->actingAs_($this->member())
            ->postJson('/api/v1/events', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function un_collecteur_cree_une_sortie(): void
    {
        $this->actingAs_($this->collector())
            ->postJson('/api/v1/events', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Grand Tour Cyclo Dakar')
            // Une sortie naît en brouillon : le bureau la relit avant d'annoncer.
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.planned_distance_m', 35_000);
    }

    #[Test]
    public function l_auteur_vient_de_la_session_pas_du_corps_de_la_requete(): void
    {
        $collector = $this->collector();
        $autre = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs_($collector)
            ->postJson('/api/v1/events', $this->payload(['created_by' => $autre->id]))
            ->assertStatus(201);

        $this->assertSame($collector->id, Event::first()->created_by);
    }

    /* --------------------------------------------------------- brouillons */

    #[Test]
    public function un_brouillon_reste_invisible_aux_membres(): void
    {
        Event::factory()->draft()->create(['title' => 'Sortie en preparation']);
        Event::factory()->create(['title' => 'Sortie annoncee']);

        $response = $this->actingAs_($this->member())
            ->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Sortie annoncee', $response->json('data.0.title'));
    }

    #[Test]
    public function son_auteur_voit_son_propre_brouillon(): void
    {
        $collector = $this->collector();
        Event::factory()->draft()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function le_brouillon_d_un_autre_est_refuse_en_consultation_directe(): void
    {
        $event = Event::factory()->draft()->create();

        $this->actingAs_($this->member())
            ->getJson("/api/v1/events/{$event->uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function un_administrateur_voit_tous_les_brouillons(): void
    {
        Event::factory()->draft()->count(2)->create();

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs_($admin)
            ->getJson('/api/v1/events?scope=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /* -------------------------------------------------------------- listes */

    #[Test]
    public function la_liste_par_defaut_ne_montre_que_les_sorties_a_venir(): void
    {
        // Un membre qui ouvre l'écran cherche la prochaine sortie, pas
        // celle de mars.
        Event::factory()->create(['title' => 'A venir']);
        Event::factory()->past()->create(['title' => 'Deja passee']);

        $response = $this->actingAs_($this->member())
            ->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('A venir', $response->json('data.0.title'));
    }

    #[Test]
    public function l_historique_reste_accessible(): void
    {
        Event::factory()->create(['title' => 'A venir']);
        Event::factory()->past()->create(['title' => 'Deja passee']);

        $response = $this->actingAs_($this->member())
            ->getJson('/api/v1/events?scope=past')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Deja passee', $response->json('data.0.title'));
    }

    #[Test]
    public function les_sorties_a_venir_sont_triees_de_la_plus_proche_a_la_plus_lointaine(): void
    {
        Event::factory()->create(['title' => 'Dans un mois', 'starts_at' => now()->addMonth()]);
        Event::factory()->create(['title' => 'Demain', 'starts_at' => now()->addDay()]);

        $response = $this->actingAs_($this->member())
            ->getJson('/api/v1/events')
            ->assertOk();

        $this->assertSame('Demain', $response->json('data.0.title'));
        $this->assertSame('Dans un mois', $response->json('data.1.title'));
    }

    #[Test]
    public function la_liste_se_filtre_par_sport(): void
    {
        Event::factory()->sport(Sport::Cycling)->create();
        Event::factory()->sport(Sport::Running)->create(['title' => 'Course du dimanche']);

        $response = $this->actingAs_($this->member())
            ->getJson('/api/v1/events?sport=RUNNING')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Course du dimanche', $response->json('data.0.title'));
    }

    /* ------------------------------------------------------------ création */

    #[Test]
    public function une_sortie_ne_peut_pas_etre_annoncee_dans_le_passe(): void
    {
        // Une annonce datée d'hier ne pourrait recueillir aucune inscription.
        $this->actingAs_($this->collector())
            ->postJson('/api/v1/events', $this->payload([
                'starts_at' => now()->subDay()->toIso8601String(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'La date de départ doit être dans le futur.');
    }

    #[Test]
    public function la_fin_doit_suivre_le_depart(): void
    {
        $this->actingAs_($this->collector())
            ->postJson('/api/v1/events', $this->payload([
                'starts_at' => now()->addWeek()->setTime(9, 0)->toIso8601String(),
                'ends_at' => now()->addWeek()->setTime(7, 0)->toIso8601String(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.ends_at.0', "L'heure de fin doit suivre l'heure de départ.");
    }

    #[Test]
    public function une_sortie_a_zero_place_est_refusee(): void
    {
        // « Aucune place » n'a pas de sens : pour ne pas limiter, on laisse
        // le champ vide.
        $this->actingAs_($this->collector())
            ->postJson('/api/v1/events', $this->payload(['max_participants' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_participants');
    }

    #[Test]
    public function une_sortie_sans_limite_annonce_null_et_non_un_grand_nombre(): void
    {
        $event = Event::factory()->create(['max_participants' => null]);

        $this->actingAs_($this->member())
            ->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.max_participants', null)
            ->assertJsonPath('data.seats_left', null)
            ->assertJsonPath('data.is_full', false);
    }

    /* ------------------------------------------------------- modifications */

    #[Test]
    public function un_collecteur_ne_modifie_pas_la_sortie_d_un_autre(): void
    {
        // Sans cela, n'importe quel collecteur pourrait déplacer la sortie
        // d'un autre, et personne ne saurait qui l'a fait.
        $event = Event::factory()->create();

        $this->actingAs_($this->collector())
            ->patchJson("/api/v1/events/{$event->uuid}", ['title' => 'Detourne'])
            ->assertStatus(403);
    }

    #[Test]
    public function l_auteur_modifie_sa_sortie(): void
    {
        $collector = $this->collector();
        $event = Event::factory()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->patchJson("/api/v1/events/{$event->uuid}", ['title' => 'Nouveau titre'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Nouveau titre');
    }

    #[Test]
    public function une_sortie_terminee_ne_se_modifie_plus(): void
    {
        // C'est un fait, pas un projet : la retoucher fausserait les
        // présences déjà pointées.
        $collector = $this->collector();
        $event = Event::factory()->past()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->patchJson("/api/v1/events/{$event->uuid}", ['title' => 'Reecriture'])
            ->assertStatus(403);
    }

    /* --------------------------------------------------------- transitions */

    #[Test]
    public function un_brouillon_se_publie(): void
    {
        $collector = $this->collector();
        $event = Event::factory()->draft()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->patchJson("/api/v1/events/{$event->uuid}/status", ['status' => 'PUBLISHED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.registrations_open', true);
    }

    #[Test]
    public function une_sortie_annoncee_ne_redevient_pas_un_brouillon(): void
    {
        // Les membres l'ont déjà notée. On l'annule — ce qui les prévient —
        // plutôt que de la faire disparaître sans explication.
        $collector = $this->collector();
        $event = Event::factory()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->patchJson("/api/v1/events/{$event->uuid}/status", ['status' => 'DRAFT'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    #[Test]
    public function une_sortie_annulee_ne_ressuscite_pas(): void
    {
        $collector = $this->collector();
        $event = Event::factory()->status(EventStatus::Cancelled)->create([
            'created_by' => $collector->id,
        ]);

        $this->actingAs_($collector)
            ->patchJson("/api/v1/events/{$event->uuid}/status", ['status' => 'PUBLISHED'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    #[Test]
    public function republier_une_sortie_deja_publiee_ne_change_rien(): void
    {
        // Idempotence : un double appui sur un réseau lent ne doit pas
        // produire d'erreur.
        $collector = $this->collector();
        $event = Event::factory()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->patchJson("/api/v1/events/{$event->uuid}/status", ['status' => 'PUBLISHED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');
    }

    /* -------------------------------------------------------- suppression */

    #[Test]
    public function la_suppression_est_douce(): void
    {
        $collector = $this->collector();
        $event = Event::factory()->create(['created_by' => $collector->id]);

        $this->actingAs_($collector)
            ->deleteJson("/api/v1/events/{$event->uuid}")
            ->assertOk();

        // La sortie disparaît du calendrier mais reste en base : les sorties
        // GPS et les présences des membres ne doivent pas s'effacer avec elle.
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }
}
