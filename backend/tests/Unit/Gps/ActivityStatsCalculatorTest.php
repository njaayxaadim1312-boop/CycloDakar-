<?php

declare(strict_types=1);

namespace Tests\Unit\Gps;

use App\Enums\Sport;
use App\Services\Gps\ActivityStatsCalculator;
use App\Services\Gps\GpsFilter;
use App\Services\Gps\PolylineEncoder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GpsTraceBuilder;
use Tests\TestCase;

/**
 * Calcul des statistiques d'une activité.
 *
 * Deux exigences du cahier des charges sont vérifiées ici :
 * « les statistiques sont cohérentes et résistantes aux erreurs GPS », et
 * « le temps de pause ne doit pas être comptabilisé comme temps actif ».
 */
final class ActivityStatsCalculatorTest extends TestCase
{
    private function calculator(): ActivityStatsCalculator
    {
        return new ActivityStatsCalculator;
    }

    #[Test]
    public function la_distance_correspond_au_trajet_reel(): void
    {
        // 6 m/s pendant 600 s = 3 600 m exactement.
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 600)->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        // Tolérance de 1 % : Haversine sur des pas de 6 m accumule un écart
        // d'arrondi négligeable mais non nul.
        $this->assertEqualsWithDelta(3600, $stats['distance_m'], 36);
    }

    #[Test]
    public function la_duree_totale_et_le_temps_actif_sont_distincts(): void
    {
        // 300 s de roulage, 120 s de pause, 300 s de roulage.
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 300)
            ->pause(120)
            ->straight(speedMps: 6.0, seconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertEqualsWithDelta(720, $stats['duration_s'], 3);
        // Le temps actif exclut la pause.
        $this->assertEqualsWithDelta(600, $stats['moving_time_s'], 15);
        $this->assertEqualsWithDelta(120, $stats['paused_time_s'], 15);
    }

    #[Test]
    public function une_pause_n_ajoute_pas_de_distance(): void
    {
        // Le GPS tremble à l'arrêt. Sans filtrage, un quart d'heure de pause
        // ajouterait plusieurs centaines de mètres à la sortie.
        $sansPause = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 600)->build();

        $avecPause = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 300)
            ->pause(900)
            ->straight(speedMps: 6.0, seconds: 300)
            ->build();

        $a = $this->calculator()->calculate($sansPause, Sport::Cycling);
        $b = $this->calculator()->calculate($avecPause, Sport::Cycling);

        $this->assertEqualsWithDelta($a['distance_m'], $b['distance_m'], 50);
    }

    #[Test]
    public function un_arret_non_declare_ne_compte_pas_dans_le_temps_actif(): void
    {
        // Feu rouge, ravitaillement : le membre n'a pas appuyé sur pause,
        // mais il ne bouge pas. Sans cette règle, la vitesse moyenne d'une
        // sortie urbaine serait grossièrement sous-évaluée.
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 300)
            ->idle(180)
            ->straight(speedMps: 6.0, seconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertEqualsWithDelta(780, $stats['duration_s'], 5);
        $this->assertLessThan(700, $stats['moving_time_s']);
    }

    #[Test]
    public function la_vitesse_moyenne_se_calcule_sur_le_temps_actif(): void
    {
        // Sinon une pause déjeuner de 40 min ferait passer une sortie à
        // 12 km/h de moyenne alors qu'on roulait à 21,6.
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 600)
            ->pause(600)
            ->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertEqualsWithDelta(6.0, $stats['avg_speed_mps'], 0.5);
    }

    #[Test]
    public function un_parcours_plat_ne_fabrique_pas_de_denivele(): void
    {
        // LE piège du dénivelé. L'altitude GPS oscille de ±8 m. Un calcul
        // naïf trouverait ici plusieurs centaines de mètres de D+ sur un
        // parcours parfaitement plat — un « +2 000 m » sur la Corniche
        // décrédibiliserait l'application en une seule sortie.
        $trace = GpsTraceBuilder::make()
            ->flatWithNoise(seconds: 1200, noiseM: 8.0)
            ->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertLessThan(
            60,
            $stats['elevation_gain_m'],
            'Le bruit barométrique ne doit pas être compté comme du dénivelé.',
        );
    }

    #[Test]
    public function une_vraie_montee_est_bien_mesuree(): void
    {
        // Le revers du test précédent : à force de filtrer, on pourrait ne
        // plus rien mesurer du tout.
        $trace = GpsTraceBuilder::make()
            ->climb(meters: 120.0, overSeconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertEqualsWithDelta(120, $stats['elevation_gain_m'], 15);
        $this->assertLessThan(10, $stats['elevation_loss_m']);
    }

    #[Test]
    public function une_montee_puis_une_descente_sont_comptees_separement(): void
    {
        $trace = GpsTraceBuilder::make()
            ->climb(meters: 100.0, overSeconds: 200)
            ->climb(meters: -100.0, overSeconds: 200)
            ->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertEqualsWithDelta(100, $stats['elevation_gain_m'], 15);
        $this->assertEqualsWithDelta(100, $stats['elevation_loss_m'], 15);
    }

    #[Test]
    public function la_vitesse_maximale_resiste_a_un_point_aberrant(): void
    {
        // Un seul point aberrant ne doit pas fixer un record personnel à
        // 87 km/h. Le filtre l'écarte, et la vitesse maximale est prise sur
        // la valeur lissée.
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 60)
            ->multipathJump(200.0)
            ->straight(speedMps: 6.0, seconds: 60)
            ->build();

        $filtered = (new GpsFilter(Sport::Cycling))->apply($trace);
        $stats = $this->calculator()->calculate($filtered, Sport::Cycling);

        // 6 m/s = 21,6 km/h. Un pic à 200 m/s serait grotesque.
        $this->assertLessThan(10.0, $stats['max_speed_mps']);
    }

    #[Test]
    public function l_allure_est_calculee_pour_la_course(): void
    {
        // 3 m/s = 5:33 au kilomètre.
        $trace = GpsTraceBuilder::make()->straight(speedMps: 3.0, seconds: 600)->build();

        $stats = $this->calculator()->calculate($trace, Sport::Running);

        $this->assertEqualsWithDelta(333, $stats['avg_pace_s_per_km'], 10);
    }

    #[Test]
    public function les_splits_kilometriques_sont_produits(): void
    {
        // 6 m/s pendant 900 s = 5 400 m, soit 5 kilomètres complets.
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 900)->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertCount(5, $stats['splits']);
        $this->assertSame(1, $stats['splits'][0]['km']);
        // 1 000 m à 6 m/s = 167 s.
        $this->assertEqualsWithDelta(167, $stats['splits'][0]['duration_s'], 10);
    }

    #[Test]
    public function la_trace_est_simplifiee_et_encodee(): void
    {
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 1800)->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertNotNull($stats['polyline']);

        // La ligne droite se réduit à ses deux extrémités : c'est justement
        // ce que Douglas-Peucker doit faire.
        $decoded = (new PolylineEncoder)->decode($stats['polyline']);
        $this->assertLessThan(count($trace) / 10, count($decoded));

        // Le départ reste le départ : la simplification ne déplace jamais les
        // extrémités.
        $this->assertEqualsWithDelta($trace[0]->lat, $decoded[0]['lat'], 0.0001);
    }

    #[Test]
    public function les_limites_de_la_trace_sont_fournies(): void
    {
        // Le client cadre la carte sans avoir à décoder la polyline.
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 300)->build();

        $stats = $this->calculator()->calculate($trace, Sport::Cycling);

        $this->assertArrayHasKey('min_lat', $stats['bounds']);
        $this->assertLessThan($stats['bounds']['max_lat'], $stats['bounds']['min_lat']);
    }

    #[Test]
    public function une_trace_vide_ne_fait_pas_planter_le_calcul(): void
    {
        // Cas réel : le membre appuie sur « Démarrer » puis « Arrêter » sans
        // que le GPS ait accroché.
        $stats = $this->calculator()->calculate([], Sport::Cycling);

        $this->assertSame(0, $stats['distance_m']);
        $this->assertSame(0, $stats['duration_s']);
        $this->assertNull($stats['polyline']);
    }

    #[Test]
    public function les_calories_ne_sont_pas_inventees_sans_le_poids(): void
    {
        // Le club ne demande pas le poids de ses membres. Plutôt que de
        // calculer sur une moyenne inventée, on ne renvoie rien.
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 600)->build();

        $sansPoids = $this->calculator()->calculate($trace, Sport::Cycling);
        $avecPoids = $this->calculator()->calculate($trace, Sport::Cycling, weightKg: 72.0);

        $this->assertNull($sansPoids['calories_kcal']);
        $this->assertGreaterThan(0, $avecPoids['calories_kcal']);
    }
}
