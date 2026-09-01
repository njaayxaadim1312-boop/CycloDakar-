<?php

declare(strict_types=1);

namespace Tests\Unit\Gps;

use App\Enums\Sport;
use App\Services\Gps\ActivityStatsCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GpsTraceBuilder;
use Tests\TestCase;

/**
 * La marche lente : le cas où le bruit du GPS dépasse le déplacement.
 *
 * À 1,2 m/s, un marcheur avance de 1,2 m par seconde alors que l'incertitude
 * de position vaut plusieurs mètres. Chaque point tombe à côté du précédent,
 * et un calcul naïf additionne ce tremblement comme du déplacement : six
 * mètres parcourus s'affichent en vingt, et la vitesse s'envole.
 *
 * C'est le défaut signalé par le club, et ces tests le verrouillent.
 *
 * Le bruit est appliqué **perpendiculairement** à la marche : il n'ajoute
 * aucune distance réelle. Tout mètre compté en plus est donc, à coup sûr, une
 * erreur — il n'y a pas d'ambiguïté à interpréter.
 */
final class WalkingDistanceTest extends TestCase
{
    private function calculator(): ActivityStatsCalculator
    {
        return app(ActivityStatsCalculator::class);
    }

    #[Test]
    public function une_marche_lente_ne_voit_pas_sa_distance_multipliee_par_le_bruit(): void
    {
        // 60 s à 1,2 m/s : 72 m réels, avec 3 m de tremblement latéral.
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 60, speedMps: 1.2, noiseM: 3.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        /*
         | Avant correction : 135 m pour 72 m réels, soit +87 %.
         | Après : 69 m, soit −4 %. La bande retenue, 60 à 80 m, laisse
         | respirer le va-et-vient résiduel sans jamais tolérer un
         | sur-comptage.
         */
        $this->assertGreaterThan(
            60,
            $stats['distance_m'],
            'Le filtre rogne trop : 72 m réels mesurés à '.$stats['distance_m'].' m.',
        );
        $this->assertLessThan(
            80,
            $stats['distance_m'],
            'La distance est gonflée par le bruit GPS : 72 m réels mesurés à '.$stats['distance_m'].' m.',
        );
    }

    #[Test]
    public function la_vitesse_moyenne_d_une_marche_reste_une_vitesse_de_marche(): void
    {
        // Le symptôme le plus visible pour le membre : « la vitesse exagère ».
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 60, speedMps: 1.2, noiseM: 3.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        // Mesuré : 1,25 m/s pour 1,2 réel. Avant correction : 2,29 m/s,
        // soit 8,2 km/h en marchant.
        $this->assertLessThan(
            1.6,
            $stats['avg_speed_mps'],
            'Une marche à 1,2 m/s ne peut pas afficher '
            .round($stats['avg_speed_mps'] * 3.6, 1).' km/h.',
        );
    }

    #[Test]
    public function la_vitesse_maximale_d_une_marche_reste_plausible(): void
    {
        // Sans anti-tremblement, un saut de 4 m en 1 s donne 14 km/h : un
        // record personnel de marche fabriqué par le bruit.
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 60, speedMps: 1.2, noiseM: 3.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        // Mesuré : 1,28 m/s. La pointe d'une marche reste une marche.
        $this->assertLessThan(
            1.8,
            $stats['max_speed_mps'],
            'Vitesse maximale aberrante : '.round($stats['max_speed_mps'] * 3.6, 1).' km/h en marchant.',
        );
    }

    #[Test]
    public function un_marcheur_immobile_n_accumule_aucune_distance(): void
    {
        // Le cas limite : le membre s'arrête pour discuter. Le GPS continue
        // de trembler, la distance ne doit pas bouger d'un mètre.
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 90, speedMps: 0.0, noiseM: 4.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        $this->assertLessThan(
            15,
            $stats['distance_m'],
            'À l\'arrêt, le tremblement a été compté comme du déplacement : '
            .$stats['distance_m'].' m.',
        );
    }

    #[Test]
    public function une_sortie_velo_reste_mesuree_fidelement(): void
    {
        // Le correctif ne doit pas rogner les sports rapides : à 6 m/s, chaque
        // seconde franchit largement le seuil, et rien ne doit être perdu.
        $points = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Cycling);

        // 1 800 m attendus, à 3 % près.
        $this->assertGreaterThan(1_740, $stats['distance_m']);
        $this->assertLessThan(1_860, $stats['distance_m']);
        $this->assertEqualsWithDelta(6.0, $stats['avg_speed_mps'], 0.4);
    }

    #[Test]
    public function une_course_reste_mesuree_fidelement(): void
    {
        $points = GpsTraceBuilder::make()
            ->straight(speedMps: 3.0, seconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Running);

        // 900 m attendus.
        $this->assertGreaterThan(860, $stats['distance_m']);
        $this->assertLessThan(940, $stats['distance_m']);
    }
}
