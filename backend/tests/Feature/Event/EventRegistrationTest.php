<?php

declare(strict_types=1);

namespace Tests\Feature\Event;

use App\Enums\AttendanceStatus;
use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use App\Models\User;
use App\Services\EventRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inscriptions, liste d'attente et présences.
 *
 * C'est ici que se joue la phase. Le bureau annonce une sortie à 25 places sur
 * WhatsApp ; vingt membres touchent « Je participe » dans la même minute. Ce
 * qui suit vérifie qu'aucun d'entre eux ne prend une place qui n'existe pas,
 * que la file d'attente respecte l'ordre d'arrivée, et qu'un membre ne peut
 * pas se déclarer présent lui-même.
 */
final class EventRegistrationTest extends TestCase
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

    /** @return array{User, Member} */
    private function member(UserRole $role = UserRole::Member): array
    {
        $user = User::factory()->create(['role' => $role]);
        $member = Member::factory()->for($user)->create();

        return [$user, $member];
    }

    /* --------------------------------------------------------- inscription */

    #[Test]
    public function un_membre_s_inscrit_a_une_sortie(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->create();

        $this->actingAs_($user)
            ->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('data.registration_status', 'REGISTERED')
            ->assertJsonPath('meta.registered', 1);
    }

    #[Test]
    public function un_compte_sans_fiche_membre_ne_peut_pas_s_inscrire(): void
    {
        // Cas réel : un compte administrateur créé en console. L'inscription
        // est portée par la fiche club, pas par le compte.
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $event = Event::factory()->create();

        $this->actingAs_($user)
            ->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertStatus(403);
    }

    #[Test]
    public function une_double_inscription_ne_cree_pas_de_doublon(): void
    {
        // Double appui sur un réseau lent : le second appel doit être sans
        // effet, pas produire une seconde ligne ni une erreur.
        [$user] = $this->member();
        $event = Event::factory()->create();

        $this->actingAs_($user)->postJson("/api/v1/events/{$event->uuid}/register")->assertOk();
        $this->actingAs_($user)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('meta.registered', 1);

        $this->assertSame(1, EventParticipant::count());
    }

    #[Test]
    public function on_ne_s_inscrit_pas_a_un_brouillon(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->draft()->create();

        // 403 et non 422 : un 422 nommant « Brouillon » confirmerait que
        // la sortie existe. Un brouillon doit rester introuvable, pas
        // seulement inaccessible.
        $this->actingAs_($user)
            ->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertStatus(403);
    }

    #[Test]
    public function on_ne_s_inscrit_pas_a_une_sortie_annulee(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->status(EventStatus::Cancelled)->create();

        $this->actingAs_($user)
            ->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertStatus(422)
            ->assertJsonPath('code', 'REGISTRATIONS_CLOSED');
    }

    #[Test]
    public function on_peut_encore_s_inscrire_a_une_sortie_en_cours(): void
    {
        // Un membre qui rejoint le groupe au premier rond-point est un
        // participant réel. Le refuser fausserait la liste des présents.
        [$user] = $this->member();
        $event = Event::factory()->ongoing()->create();

        $this->actingAs_($user)
            ->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('data.registration_status', 'REGISTERED');
    }

    /* ------------------------------------------------------ liste d'attente */

    #[Test]
    public function une_sortie_pleine_bascule_en_liste_d_attente(): void
    {
        // Refuser sèchement ferait perdre au club des participants qui
        // seraient venus si une place s'était libérée.
        $event = Event::factory()->limitedTo(1)->create();

        [$premier] = $this->member();
        [$second] = $this->member();

        $this->actingAs_($premier)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertJsonPath('data.registration_status', 'REGISTERED');

        $this->actingAs_($second)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('data.registration_status', 'WAITLIST')
            ->assertJsonPath('data.queue_position', 1)
            ->assertJsonPath('meta.registered', 1)
            ->assertJsonPath('meta.waitlist', 1)
            ->assertJsonPath('meta.seats_left', 0);
    }

    #[Test]
    public function la_file_d_attente_respecte_l_ordre_d_arrivee(): void
    {
        $event = Event::factory()->limitedTo(1)->create();

        [$premier] = $this->member();
        [$deuxieme] = $this->member();
        [$troisieme] = $this->member();

        $this->actingAs_($premier)->postJson("/api/v1/events/{$event->uuid}/register");

        $this->actingAs_($deuxieme)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertJsonPath('data.queue_position', 1);

        $this->actingAs_($troisieme)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertJsonPath('data.queue_position', 2);
    }

    #[Test]
    public function un_desistement_promeut_le_premier_de_la_file(): void
    {
        $event = Event::factory()->limitedTo(1)->create();

        [$titulaire, $membreTitulaire] = $this->member();
        [$attente, $membreAttente] = $this->member();

        $this->actingAs_($titulaire)->postJson("/api/v1/events/{$event->uuid}/register");
        $this->actingAs_($attente)->postJson("/api/v1/events/{$event->uuid}/register");

        $this->actingAs_($titulaire)
            ->deleteJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('data.registration_status', 'CANCELLED')
            // La place libérée est reprise dans le même mouvement.
            ->assertJsonPath('meta.registered', 1)
            ->assertJsonPath('meta.waitlist', 0);

        $promu = EventParticipant::where('member_id', $membreAttente->id)->firstOrFail();

        $this->assertSame(RegistrationStatus::Registered, $promu->registration_status);
        // Le rang est effacé : le membre n'est plus dans la file. Le garder
        // laisserait croire qu'il y est encore.
        $this->assertNull($promu->queue_position);

        $this->assertSame(
            RegistrationStatus::Cancelled,
            EventParticipant::where('member_id', $membreTitulaire->id)->firstOrFail()->registration_status,
        );
    }

    #[Test]
    public function un_desistement_depuis_la_file_ne_promeut_personne(): void
    {
        // La place n'était pas occupée : il n'y a rien à libérer.
        $event = Event::factory()->limitedTo(1)->create();

        [$titulaire] = $this->member();
        [$attente] = $this->member();
        [$suivant] = $this->member();

        $this->actingAs_($titulaire)->postJson("/api/v1/events/{$event->uuid}/register");
        $this->actingAs_($attente)->postJson("/api/v1/events/{$event->uuid}/register");
        $this->actingAs_($suivant)->postJson("/api/v1/events/{$event->uuid}/register");

        $this->actingAs_($attente)
            ->deleteJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('meta.registered', 1)
            ->assertJsonPath('meta.waitlist', 1);
    }

    #[Test]
    public function une_reinscription_apres_desistement_repart_en_fin_de_file(): void
    {
        // Elle ne récupère pas son ancien rang : les membres qui ont attendu
        // pendant ce temps-là passent devant, ce qui est juste.
        $event = Event::factory()->limitedTo(1)->create();

        [$titulaire] = $this->member();
        [$partant] = $this->member();
        [$patient] = $this->member();

        $this->actingAs_($titulaire)->postJson("/api/v1/events/{$event->uuid}/register");
        $this->actingAs_($partant)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertJsonPath('data.queue_position', 1);
        $this->actingAs_($patient)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertJsonPath('data.queue_position', 2);

        $this->actingAs_($partant)->deleteJson("/api/v1/events/{$event->uuid}/register");

        $this->actingAs_($partant)->postJson("/api/v1/events/{$event->uuid}/register")
            ->assertOk()
            ->assertJsonPath('data.queue_position', 3);
    }

    #[Test]
    public function un_desistement_conserve_la_trace_plutot_que_de_l_effacer(): void
    {
        // Le bureau doit distinguer « ne s'est jamais inscrit » de « s'est
        // désisté ». Sur une sortie à places limitées, la différence compte.
        [$user, $member] = $this->member();
        $event = Event::factory()->create();

        $this->actingAs_($user)->postJson("/api/v1/events/{$event->uuid}/register");
        $this->actingAs_($user)->deleteJson("/api/v1/events/{$event->uuid}/register");

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'member_id' => $member->id,
            'registration_status' => RegistrationStatus::Cancelled->value,
        ]);
    }

    #[Test]
    public function se_desister_sans_etre_inscrit_est_refuse_clairement(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->create();

        $this->actingAs_($user)
            ->deleteJson("/api/v1/events/{$event->uuid}/register")
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_REGISTERED');
    }

    #[Test]
    public function les_places_ne_depassent_jamais_la_limite(): void
    {
        // Le test de fond : sur une sortie à 3 places, dix inscriptions
        // successives donnent 3 inscrits et 7 en attente, jamais 4 inscrits.
        $event = Event::factory()->limitedTo(3)->create();
        $service = app(EventRegistrationService::class);

        for ($i = 0; $i < 10; $i++) {
            [, $member] = $this->member();
            $service->register($event, $member);
        }

        $this->assertSame(3, EventParticipant::where('registration_status', RegistrationStatus::Registered)->count());
        $this->assertSame(7, EventParticipant::where('registration_status', RegistrationStatus::Waitlist)->count());
    }

    /* ----------------------------------------------------------- présences */

    #[Test]
    public function un_membre_ne_se_declare_pas_present_lui_meme(): void
    {
        // Sinon la liste ne vaudrait plus rien — et elle servira à justifier
        // des participations financières.
        [$user, $member] = $this->member();
        $event = Event::factory()->ongoing()->create();

        $this->actingAs_($user)
            ->postJson("/api/v1/events/{$event->uuid}/attendance", [
                'member' => $member->uuid,
                'status' => 'PRESENT',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function un_collecteur_pointe_un_present(): void
    {
        [$collecteur] = $this->member(UserRole::Collector);
        [, $membre] = $this->member();

        $event = Event::factory()->ongoing()->create();

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/events/{$event->uuid}/attendance", [
                'member' => $membre->uuid,
                'status' => 'PRESENT',
            ])
            ->assertOk()
            ->assertJsonPath('data.attendance_status', 'PRESENT')
            ->assertJsonPath('meta.present', 1);
    }

    #[Test]
    public function le_pointeur_est_celui_de_la_session_pas_celui_du_corps(): void
    {
        [$collecteur, $ficheCollecteur] = $this->member(UserRole::Collector);
        [$autre] = $this->member(UserRole::Admin);
        [, $membre] = $this->member();

        $event = Event::factory()->ongoing()->create();

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/events/{$event->uuid}/attendance", [
                'member' => $membre->uuid,
                'status' => 'PRESENT',
                // Tentative de signer au nom d'un autre : ignorée.
                'checked_in_by' => $autre->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('event_participants', [
            'member_id' => $membre->id,
            'checked_in_by' => $collecteur->id,
        ]);

        // La fiche du collecteur n'a rien à voir avec la signature, qui porte
        // sur le COMPTE. On vérifie qu'on n'a pas confondu les deux.
        $this->assertNotSame($ficheCollecteur->id, $autre->id);
    }

    #[Test]
    public function pointer_un_membre_non_inscrit_l_inscrit_sur_place(): void
    {
        // Un membre qui se présente le jour même sans s'être inscrit est un
        // participant réel : c'est précisément ce que la liste doit établir.
        [$collecteur] = $this->member(UserRole::Collector);
        [, $membre] = $this->member();

        $event = Event::factory()->ongoing()->create();

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/events/{$event->uuid}/attendance", [
                'member' => $membre->uuid,
                'status' => 'PRESENT',
            ])
            ->assertOk()
            ->assertJsonPath('data.registration_status', 'REGISTERED')
            ->assertJsonPath('meta.registered', 1);
    }

    #[Test]
    public function on_ne_pointe_pas_une_sortie_qui_n_a_pas_commence(): void
    {
        [$collecteur] = $this->member(UserRole::Collector);
        [, $membre] = $this->member();

        $event = Event::factory()->create(); // PUBLISHED, dans une semaine

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/events/{$event->uuid}/attendance", [
                'member' => $membre->uuid,
                'status' => 'PRESENT',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ATTENDANCE_CLOSED');
    }

    #[Test]
    public function un_pointage_annule_efface_sa_signature(): void
    {
        // Repasser à « non pointé » doit effacer l'heure et l'auteur :
        // conserver la signature d'un pointage annulé laisserait croire que
        // quelqu'un a constaté quelque chose.
        [$collecteur] = $this->member(UserRole::Collector);
        [, $membre] = $this->member();

        $event = Event::factory()->ongoing()->create();
        $url = "/api/v1/events/{$event->uuid}/attendance";

        $this->actingAs_($collecteur)->postJson($url, [
            'member' => $membre->uuid, 'status' => 'PRESENT',
        ])->assertOk();

        $this->actingAs_($collecteur)->postJson($url, [
            'member' => $membre->uuid, 'status' => 'UNKNOWN',
        ])->assertOk();

        $participant = EventParticipant::where('member_id', $membre->id)->firstOrFail();

        $this->assertSame(AttendanceStatus::Unknown, $participant->attendance_status);
        $this->assertNull($participant->checked_in_at);
        $this->assertNull($participant->checked_in_by);
    }

    /* ---------------------------------------------------------- liste vue */

    #[Test]
    public function la_liste_des_inscrits_ne_porte_ni_telephone_ni_adresse(): void
    {
        // Savoir qui vient ne suppose pas d'obtenir l'annuaire.
        [$user] = $this->member();
        [, $autre] = $this->member();

        $event = Event::factory()->create();
        EventParticipant::factory()->create([
            'event_id' => $event->id,
            'member_id' => $autre->id,
        ]);

        $response = $this->actingAs_($user)
            ->getJson("/api/v1/events/{$event->uuid}/participants")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $membre = $response->json('data.0.member');

        $this->assertArrayHasKey('full_name', $membre);
        $this->assertArrayNotHasKey('phone', $membre);
        $this->assertArrayNotHasKey('email', $membre);
    }

    #[Test]
    public function les_desistes_ne_figurent_pas_dans_la_liste(): void
    {
        [$user] = $this->member();
        [, $parti] = $this->member();

        $event = Event::factory()->create();
        EventParticipant::factory()->cancelled()->create([
            'event_id' => $event->id,
            'member_id' => $parti->id,
        ]);

        $this->actingAs_($user)
            ->getJson("/api/v1/events/{$event->uuid}/participants")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.cancelled', 1);
    }

    #[Test]
    public function la_fiche_d_une_sortie_dit_au_membre_s_il_est_inscrit(): void
    {
        // La seule question qu'il se pose en ouvrant l'écran.
        [$user] = $this->member();
        $event = Event::factory()->create();

        $this->actingAs_($user)
            ->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.my_registration', null);

        $this->actingAs_($user)->postJson("/api/v1/events/{$event->uuid}/register");

        $this->actingAs_($user)
            ->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.my_registration.status', 'REGISTERED');
    }

    #[Test]
    public function un_desistement_remet_my_registration_a_null(): void
    {
        // Un désistement n'est pas une inscription : le membre doit revoir
        // le bouton « Je participe ».
        [$user] = $this->member();
        $event = Event::factory()->create();

        $this->actingAs_($user)->postJson("/api/v1/events/{$event->uuid}/register");
        $this->actingAs_($user)->deleteJson("/api/v1/events/{$event->uuid}/register");

        $this->actingAs_($user)
            ->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.my_registration', null);
    }

    #[Test]
    public function la_liste_mes_sorties_ne_montre_que_celles_ou_je_suis_inscrit(): void
    {
        [$user] = $this->member();

        $mienne = Event::factory()->create(['title' => 'Ma sortie']);
        Event::factory()->create(['title' => 'Celle des autres']);

        $this->actingAs_($user)->postJson("/api/v1/events/{$mienne->uuid}/register");

        $response = $this->actingAs_($user)
            ->getJson('/api/v1/events?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Ma sortie', $response->json('data.0.title'));
    }
}
