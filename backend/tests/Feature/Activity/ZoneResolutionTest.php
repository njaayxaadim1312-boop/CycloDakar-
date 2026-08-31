<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Jobs\ResolveActivityZones;
use App\Models\Activity;
use App\Models\Member;
use App\Models\User;
use App\Services\Gps\GpsPoint;
use App\Services\Gps\ZoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GpsTraceBuilder;
use Tests\TestCase;

/**
 * Zones traversées.
 *
 * L'enjeu n'est pas l'exactitude du libellé — Nominatim s'en charge — mais le
 * NOMBRE D'APPELS. Résoudre point par point serait à la fois impossible
 * (une seconde par requête) et un abus manifeste d'un service gratuit.
 */
final class ZoneResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel réseau réel : le test doit être rapide et reproductible.
        // Et surtout, on veut COMPTER les appels.
        Http::preventStrayRequests();

        // La temporisation d'une seconde entre requêtes n'a pas de sens ici.
        config(['cyclo.map.nominatim.min_interval_ms' => 0]);
    }

    private function fakeNominatim(string $suburb = 'Ouakam'): void
    {
        Http::fake([
            '*/reverse*' => Http::response([
                'address' => [
                    'suburb' => $suburb,
                    'city' => 'Dakar',
                    'country_code' => 'sn',
                ],
            ]),
        ]);
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_trace_de_dix_mille_points_ne_declenche_que_quelques_appels(): void
    {
        // LE test qui compte. Une sortie de 30 minutes à 6 m/s couvre ~10 km,
        // soit environ 5 cellules de 2,2 km. Sans le regroupement, ce seraient
        // 1 800 appels — et trois quarts d'heure d'attente.
        $this->fakeNominatim();

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 1800)->build();

        app(ZoneResolver::class)->resolve($trace);

        $calls = $this->countHttpCalls();

        $this->assertLessThan(
            15,
            $calls,
            'Une sortie ne doit déclencher qu\'une poignée d\'appels de géocodage.',
        );
        $this->assertGreaterThan(0, $calls);
    }

    #[Test]
    public function les_points_d_une_meme_cellule_sont_regroupes(): void
    {
        // Un cycliste ne change pas de quartier tous les six mètres.
        $resolver = app(ZoneResolver::class);

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();
        $cells = $resolver->distinctCells($trace);

        // 60 points sur 360 m : tous dans la même cellule de 2,2 km.
        $this->assertCount(1, $cells);
    }

    #[Test]
    public function le_cache_evite_de_reinterroger_le_service(): void
    {
        // Dakar est un territoire fini : après quelques semaines de sorties,
        // le cache couvre les parcours habituels du club.
        $this->fakeNominatim();

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 600)->build();
        $resolver = app(ZoneResolver::class);

        $resolver->resolve($trace);
        $premierPassage = $this->countHttpCalls();

        // Deuxième sortie sur le même parcours : plus aucun appel.
        $resolver->resolve($trace);

        $this->assertSame($premierPassage, $this->countHttpCalls());
        $this->assertGreaterThan(0, $premierPassage);
    }

    #[Test]
    public function une_cellule_sans_libelle_est_quand_meme_mise_en_cache(): void
    {
        // Pleine mer, zone non cartographiée. Sans mise en cache, on
        // réinterrogerait Nominatim à chaque sortie qui la traverse.
        Http::fake(['*/reverse*' => Http::response(['address' => []])]);

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();
        $resolver = app(ZoneResolver::class);

        $zones = $resolver->resolve($trace);
        $appels = $this->countHttpCalls();

        $this->assertSame([], $zones);
        $this->assertDatabaseCount('geo_zones_cache', 1);

        $resolver->resolve($trace);
        $this->assertSame($appels, $this->countHttpCalls());
    }

    #[Test]
    public function les_libelles_repetes_sont_dedupliques(): void
    {
        // Deux cellules voisines tombent souvent dans le même quartier :
        // « Ouakam · Ouakam » n'apporte rien.
        $this->fakeNominatim('Ouakam');

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 1800)->build();

        $zones = app(ZoneResolver::class)->resolve($trace);

        $this->assertSame(['Ouakam'], $zones);
    }

    #[Test]
    public function une_panne_du_geocodeur_ne_fait_pas_echouer_la_sortie(): void
    {
        // Les zones sont un enrichissement, pas une donnée vitale.
        Http::fake(['*/reverse*' => Http::response('', 503)]);

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 120)->build();

        $zones = app(ZoneResolver::class)->resolve($trace);

        $this->assertSame([], $zones);
    }

    #[Test]
    public function le_libelle_le_plus_parlant_est_retenu(): void
    {
        // À Dakar, « Ouakam » parle au club ; « Dakar » serait vrai pour
        // toute la sortie et n'apprendrait rien.
        Http::fake([
            '*/reverse*' => Http::response([
                'address' => [
                    'road' => 'Route de Ngor',
                    'suburb' => 'Ouakam',
                    'city' => 'Dakar',
                    'country_code' => 'sn',
                ],
            ]),
        ]);

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();

        $this->assertSame(['Ouakam'], app(ZoneResolver::class)->resolve($trace));
    }


    #[Test]
    public function une_panne_ne_grave_pas_un_libelle_vide_dans_le_cache(): void
    {
        /*
         * Le piège rencontré en conditions réelles.
         *
         * Si l'on met en cache une cellule non résolue parce que le service
         * était injoignable, une panne réseau de dix minutes empoisonne
         * DÉFINITIVEMENT tout le territoire traversé ce jour-là : plus aucune
         * sortie n'y portera jamais de nom de quartier, et rien n'indiquera
         * pourquoi.
         */
        // Une SÉQUENCE et non deux appels à `Http::fake` : les doublures sont
        // fusionnées, pas remplacées, et la première enregistrée continuerait
        // de répondre. La séquence dit exactement ce qu'on veut décrire — le
        // service tombe, puis revient.
        Http::fake([
            '*/reverse*' => Http::sequence()
                ->push('', 503)
                ->push(['address' => ['suburb' => 'Ouakam', 'city' => 'Dakar']]),
        ]);

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();
        $resolver = app(ZoneResolver::class);

        $this->assertSame([], $resolver->resolve($trace));
        $this->assertDatabaseCount('geo_zones_cache', 0);

        // Le service revient : la cellule doit être retentée, pas ignorée.
        $this->assertSame(['Ouakam'], $resolver->resolve($trace));
        $this->assertDatabaseCount('geo_zones_cache', 1);
    }

    #[Test]
    public function le_prefixe_administratif_est_retire_du_libelle(): void
    {
        // Les donnees OpenStreetMap senegalaises nomment les quartiers
        // « Commune de Medina ». Repete quatre fois sur une ligne de liste, le
        // prefixe masque la seule partie qui distingue les zones.
        Http::fake([
            '*/reverse*' => Http::response([
                'address' => ['suburb' => 'Commune de Médina', 'city' => 'Dakar'],
            ]),
        ]);

        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();

        $this->assertSame(['Médina'], app(ZoneResolver::class)->resolve($trace));
    }

    /* ---------------------------------------------------------------------- */
    /* Intégration avec la finalisation                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function la_finalisation_programme_la_resolution_sans_attendre(): void
    {
        // Résoudre pendant la finalisation ferait attendre le membre une
        // douzaine de secondes devant un écran figé, après trois heures de
        // sortie.
        \Illuminate\Support\Facades\Queue::fake();

        $user = User::factory()->create();
        Member::factory()->forUser($user)->create();
        $token = $user->createToken('Test')->plainTextToken;

        $uuid = (string) \Illuminate\Support\Str::uuid();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/activities', [
                'uuid' => $uuid,
                'sport' => 'CYCLING',
                'started_at' => now()->subHour()->toIso8601String(),
            ])->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/activities/{$uuid}/finalize")
            ->assertOk();

        \Illuminate\Support\Facades\Queue::assertPushed(ResolveActivityZones::class);
    }

    #[Test]
    public function le_job_enregistre_les_zones_sur_l_activite(): void
    {
        $this->fakeNominatim('Ngor');

        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->create();

        $activity = Activity::factory()->create([
            'member_id' => $member->id,
            'sport' => 'CYCLING',
        ]);

        $now = CarbonImmutable::parse('2026-08-30 07:30:00');
        $rows = [];

        for ($i = 1; $i <= 100; $i++) {
            $rows[] = (new GpsPoint(
                seq: $i,
                lat: 14.6928 + ($i * 6) / 111_320,
                lng: -17.4467,
                recordedAt: $now->addSeconds($i),
                accuracyM: 5.0,
            ))->toDatabaseRow($activity->id);
        }

        DB::table('activity_points')->insert($rows);

        (new ResolveActivityZones($activity->id))->handle(app(ZoneResolver::class));

        $this->assertSame(['Ngor'], $activity->fresh()->zones);
    }

    /* ---------------------------------------------------------------------- */

    private function countHttpCalls(): int
    {
        return Http::recorded()->count();
    }
}
