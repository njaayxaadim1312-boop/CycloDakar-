<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GpsTraceBuilder;
use Tests\TestCase;

/**
 * Synchronisation d'une activité, de bout en bout.
 *
 * Ce que ces tests protègent : la promesse du cahier des charges selon
 * laquelle « une activité peut fonctionner hors ligne puis être synchronisée ».
 * Sur la Corniche, le réseau tombe et revient — chaque étape doit pouvoir être
 * rejouée sans conséquence.
 */
final class ActivitySyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Member $member;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->member = Member::factory()->forUser($this->user)->create();
        $this->token = $this->user->createToken('Telephone')->plainTextToken;
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    /**
     * Agit au nom d'un AUTRE utilisateur.
     *
     * `forgetAuthenticatedUser` est indispensable : dans un test, plusieurs
     * requêtes se succèdent dans la même instance d'application, et le garde
     * conserve l'utilisateur résolu au premier appel. Sans cela, la seconde
     * requête resterait celle du premier membre — et les tests de permission
     * passeraient au vert sans rien prouver.
     */
    private function authAs(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', 'Bearer '.$user->createToken('T')->plainTextToken);
    }

    /**
     * Envoie une trace par lots de 500, comme le fait le mobile.
     *
     * L'API refuse les lots plus gros : au-delà, la requête devient lourde sur
     * un réseau mobile et un échec coûte cher à rejouer.
     *
     * @param  list<array<string, mixed>>  $payload
     */
    private function sendPoints(string $uuid, array $payload): void
    {
        foreach (array_chunk($payload, 500) as $batch) {
            $this->auth()
                ->postJson("/api/v1/activities/{$uuid}/points", ['points' => $batch])
                ->assertOk();
        }
    }

    private function openActivity(?string $uuid = null): string
    {
        $uuid ??= (string) Str::uuid();

        $this->auth()->postJson('/api/v1/activities', [
            'uuid' => $uuid,
            'sport' => 'CYCLING',
            'started_at' => now()->subHour()->toIso8601String(),
            'device_info' => ['model' => 'Tecno Spark 10', 'os' => 'Android 13'],
        ])->assertCreated();

        return $uuid;
    }

    /* ---------------------------------------------------------------------- */
    /* Ouverture                                                              */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_ouvre_une_activite_avec_son_propre_identifiant(): void
    {
        // L'uuid vient du CLIENT : il est généré au départ de la sortie, hors
        // ligne, avant tout contact avec le serveur.
        $uuid = (string) Str::uuid();

        $this->auth()
            ->postJson('/api/v1/activities', [
                'uuid' => $uuid,
                'sport' => 'CYCLING',
                'started_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.status', 'RECORDING')
            // Visibilité club par défaut, jamais publique : une trace GPS
            // révèle le domicile et les habitudes.
            ->assertJsonPath('data.visibility', 'CLUB');
    }

    #[Test]
    public function rejouer_l_ouverture_ne_cree_pas_de_doublon(): void
    {
        // Le cas réel : la requête part, la réponse se perd, le téléphone
        // réessaie. Sans idempotence, la sortie existerait en double.
        $uuid = (string) Str::uuid();
        $payload = [
            'uuid' => $uuid,
            'sport' => 'CYCLING',
            'started_at' => now()->subHour()->toIso8601String(),
        ];

        $this->auth()->postJson('/api/v1/activities', $payload)->assertCreated();
        // Deuxième appel : 200 et non 201, mais surtout aucun doublon.
        $this->auth()->postJson('/api/v1/activities', $payload)->assertOk();

        $this->assertSame(1, Activity::where('uuid', $uuid)->count());
    }

    #[Test]
    public function on_ne_peut_pas_ecrire_dans_la_sortie_d_un_autre(): void
    {
        $uuid = $this->openActivity();

        $autre = User::factory()->create();
        Member::factory()->forUser($autre)->create();

        $this->authAs($autre)
            ->postJson('/api/v1/activities', [
                'uuid' => $uuid,
                'sport' => 'RUNNING',
                'started_at' => now()->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'UUID_CONFLICT');
    }

    #[Test]
    public function une_sortie_ne_peut_pas_demarrer_dans_le_futur(): void
    {
        $this->auth()
            ->postJson('/api/v1/activities', [
                'uuid' => (string) Str::uuid(),
                'sport' => 'CYCLING',
                'started_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('started_at');
    }

    /* ---------------------------------------------------------------------- */
    /* Envoi des points                                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function les_points_sont_enregistres_et_le_rapport_est_detaille(): void
    {
        $uuid = $this->openActivity();
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 100);

        $this->auth()
            ->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace->toPayload()])
            ->assertOk()
            ->assertJsonPath('data.received', 100)
            ->assertJsonPath('data.accepted', 100)
            ->assertJsonPath('data.rejected', 0)
            ->assertJsonPath('data.total_points', 100);
    }

    #[Test]
    public function rejouer_un_lot_ne_double_pas_la_trace(): void
    {
        // LE test qui protège la distance. Sans la contrainte
        // UNIQUE(activity_id, seq), une réémission après coupure ferait
        // 60 km au lieu de 30.
        $uuid = $this->openActivity();
        $payload = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 100)->toPayload();

        $this->auth()->postJson("/api/v1/activities/{$uuid}/points", ['points' => $payload])->assertOk();
        $this->auth()->postJson("/api/v1/activities/{$uuid}/points", ['points' => $payload])->assertOk();
        $this->auth()->postJson("/api/v1/activities/{$uuid}/points", ['points' => $payload])->assertOk();

        $activity = Activity::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(100, $activity->points()->count());
    }

    #[Test]
    public function les_points_aberrants_sont_rejetes_sans_faire_echouer_le_lot(): void
    {
        // Un point imprécis n'est pas une erreur de requête : rejeter tout le
        // lot en 422 ferait perdre les points valides qui l'accompagnent.
        $uuid = $this->openActivity();

        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 30)
            ->poorAccuracy(90.0)
            ->multipathJump(200.0)
            ->straight(speedMps: 6.0, seconds: 30)
            ->toPayload();

        $response = $this->auth()
            ->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace])
            ->assertOk();

        $this->assertSame(2, $response->json('data.rejected'));
        $this->assertArrayHasKey('poor_accuracy', $response->json('data.rejection_reasons'));
        $this->assertArrayHasKey('impossible_speed', $response->json('data.rejection_reasons'));
    }

    #[Test]
    public function chaque_lot_laisse_une_trace_dans_le_journal_de_synchronisation(): void
    {
        // Sans ce journal, une trace anormalement courte serait inexplicable :
        // on ne saurait pas si le GPS était mauvais ou si des lots ont été
        // perdus.
        $uuid = $this->openActivity();
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 50)->toPayload();

        $this->auth()
            ->withHeader('X-Device-Id', 'tecno-spark-10-abc')
            ->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace])
            ->assertOk();

        $this->assertDatabaseHas('sync_logs', [
            'device_id' => 'tecno-spark-10-abc',
            'points_received' => 50,
            'points_accepted' => 50,
        ]);
    }

    #[Test]
    public function une_activite_terminee_n_accepte_plus_de_points(): void
    {
        $uuid = $this->openActivity();
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 50);

        $this->auth()->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace->toPayload()]);
        $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize")->assertOk();

        $this->auth()
            ->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace->toPayload()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ACTIVITY_CLOSED');
    }

    /* ---------------------------------------------------------------------- */
    /* Finalisation                                                           */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function la_finalisation_recalcule_toutes_les_statistiques(): void
    {
        $uuid = $this->openActivity();
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 600);

        $this->sendPoints($uuid, $trace->toPayload());

        $response = $this->auth()
            ->postJson("/api/v1/activities/{$uuid}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        // 6 m/s pendant 600 s = 3 600 m.
        $this->assertEqualsWithDelta(3600, $response->json('data.distance_m'), 60);
        $this->assertEqualsWithDelta(6.0, $response->json('data.avg_speed_mps'), 0.5);
        $this->assertNotNull($response->json('data.polyline'));
    }

    #[Test]
    public function le_client_ne_peut_pas_dicter_ses_statistiques(): void
    {
        // La protection des classements : un client modifié qui annoncerait
        // 200 km doit se voir opposer la distance réellement parcourue.
        $uuid = (string) Str::uuid();

        $this->auth()->postJson('/api/v1/activities', [
            'uuid' => $uuid,
            'sport' => 'CYCLING',
            'started_at' => now()->subHour()->toIso8601String(),
            // Champs volontairement injectés par un client malveillant.
            'distance_m' => 200000,
            'avg_speed_mps' => 30.0,
            'elevation_gain_m' => 5000,
        ])->assertCreated();

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 300);
        $this->sendPoints($uuid, $trace->toPayload());

        $response = $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize")->assertOk();

        $this->assertLessThan(2500, $response->json('data.distance_m'));
        $this->assertLessThan(10, $response->json('data.avg_speed_mps'));
    }

    #[Test]
    public function une_synchronisation_incomplete_est_refusee(): void
    {
        // Finaliser une trace incomplète produirait une distance fausse, et
        // le membre n'aurait aucun moyen de s'en apercevoir.
        $uuid = $this->openActivity();
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 100);

        $this->auth()->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace->toPayload()]);

        $this->auth()
            ->postJson("/api/v1/activities/{$uuid}/finalize", ['expected_points_count' => 250])
            ->assertStatus(409)
            ->assertJsonPath('code', 'INCOMPLETE_SYNC');

        $this->assertSame(
            ActivityStatus::Recording,
            Activity::where('uuid', $uuid)->firstOrFail()->status,
        );
    }

    #[Test]
    public function rejouer_la_finalisation_donne_le_meme_resultat(): void
    {
        // La réponse peut se perdre : le téléphone réessaie.
        $uuid = $this->openActivity();
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 300);

        $this->sendPoints($uuid, $trace->toPayload());

        $first = $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize")->assertOk();
        $second = $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize")->assertOk();

        $this->assertSame(
            $first->json('data.distance_m'),
            $second->json('data.distance_m'),
        );
    }

    #[Test]
    public function la_fin_est_deduite_du_dernier_point_et_non_de_l_heure_de_synchro(): void
    {
        // Le membre rentre chez lui et synchronise trois heures plus tard.
        // Prendre « maintenant » ajouterait trois heures fantômes.
        $start = now()->subHours(4);

        $uuid = (string) Str::uuid();
        $this->auth()->postJson('/api/v1/activities', [
            'uuid' => $uuid,
            'sport' => 'CYCLING',
            'started_at' => $start->toIso8601String(),
        ])->assertCreated();

        $trace = GpsTraceBuilder::make(\Carbon\CarbonImmutable::parse($start))
            ->straight(speedMps: 6.0, seconds: 600);

        $this->sendPoints($uuid, $trace->toPayload());

        $response = $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize")->assertOk();

        // 600 s et non 4 heures.
        $this->assertEqualsWithDelta(600, $response->json('data.duration_s'), 5);
    }

    #[Test]
    public function le_taux_de_filtrage_est_visible_par_le_proprietaire(): void
    {
        // C'est SA mesure de la qualité du signal, et le premier élément à
        // regarder quand une trace lui paraît fausse.
        $uuid = $this->openActivity();

        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 50)
            ->poorAccuracy(95.0)
            ->straight(speedMps: 6.0, seconds: 50)
            ->toPayload();

        $this->auth()->postJson("/api/v1/activities/{$uuid}/points", ['points' => $trace]);
        $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize");

        $response = $this->auth()->getJson("/api/v1/activities/{$uuid}")->assertOk();

        $this->assertSame(101, $response->json('data.signal.raw_points_count'));
        $this->assertSame(1, $response->json('data.signal.filtered_out'));
        $this->assertSame(99, $response->json('data.signal.quality_percent'));
    }

    /* ---------------------------------------------------------------------- */
    /* Accès                                                                  */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_activite_privee_reste_invisible_meme_pour_un_administrateur(): void
    {
        // Écart assumé avec le reste du projet : un administrateur gère le
        // club, pas la vie privée de ses membres. Une trace GPS révèle où
        // quelqu'un habite.
        $uuid = $this->openActivity();
        $this->auth()->patchJson("/api/v1/activities/{$uuid}", ['visibility' => 'PRIVATE'])->assertOk();

        $admin = User::factory()->admin()->create();
        Member::factory()->forUser($admin)->create();

        $this->authAs($admin)
            ->getJson("/api/v1/activities/{$uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function une_activite_du_club_est_visible_des_autres_membres(): void
    {
        $uuid = $this->openActivity();
        $this->auth()->postJson("/api/v1/activities/{$uuid}/finalize")->assertOk();

        $autre = User::factory()->create();
        Member::factory()->forUser($autre)->create();

        $this->authAs($autre)
            ->getJson("/api/v1/activities/{$uuid}")
            ->assertOk();
    }

    #[Test]
    public function un_membre_ne_modifie_que_ses_propres_sorties(): void
    {
        $uuid = $this->openActivity();

        $autre = User::factory()->create();
        Member::factory()->forUser($autre)->create();

        $this->authAs($autre)
            ->patchJson("/api/v1/activities/{$uuid}", ['title' => 'Ma sortie'])
            ->assertStatus(403);
    }

    #[Test]
    public function l_historique_ne_montre_que_les_sorties_terminees(): void
    {
        // Une activité encore en cours n'a pas de statistiques fiables.
        $this->openActivity();

        $termine = $this->openActivity();
        $this->auth()->postJson("/api/v1/activities/{$termine}/finalize")->assertOk();

        $this->auth()
            ->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $termine);
    }

    #[Test]
    public function l_historique_se_filtre_par_sport_et_par_periode(): void
    {
        $velo = (string) Str::uuid();
        $this->auth()->postJson('/api/v1/activities', [
            'uuid' => $velo,
            'sport' => 'CYCLING',
            'started_at' => now()->subDays(2)->toIso8601String(),
        ]);
        $this->auth()->postJson("/api/v1/activities/{$velo}/finalize");

        $course = (string) Str::uuid();
        $this->auth()->postJson('/api/v1/activities', [
            'uuid' => $course,
            'sport' => 'RUNNING',
            'started_at' => now()->subDays(30)->toIso8601String(),
        ]);
        $this->auth()->postJson("/api/v1/activities/{$course}/finalize");

        $this->auth()->getJson('/api/v1/activities?sport=CYCLING')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->auth()->getJson('/api/v1/activities?from='.now()->subDays(7)->toDateString())
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function la_suppression_est_douce(): void
    {
        // On ne refait pas une sortie : une suppression définitive détruirait
        // un enregistrement irremplaçable.
        $uuid = $this->openActivity();

        $this->auth()->deleteJson("/api/v1/activities/{$uuid}")->assertOk();

        $this->assertSoftDeleted('activities', ['uuid' => $uuid]);
    }
}
