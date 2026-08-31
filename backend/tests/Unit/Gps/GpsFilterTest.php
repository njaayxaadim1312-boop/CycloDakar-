<?php

declare(strict_types=1);

namespace Tests\Unit\Gps;

use App\Enums\Sport;
use App\Services\Gps\Distance;
use App\Services\Gps\GpsFilter;
use App\Services\Gps\GpsPoint;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GpsTraceBuilder;
use Tests\TestCase;

/**
 * Filtrage des points GPS.
 *
 * C'est le composant dont dépend la crédibilité de toute l'application : une
 * sortie de 30 km affichée à 38 km fait perdre la confiance du membre en une
 * seule utilisation.
 */
final class GpsFilterTest extends TestCase
{
    private function filter(Sport $sport = Sport::Cycling): GpsFilter
    {
        return new GpsFilter($sport);
    }

    #[Test]
    public function une_trace_propre_passe_entierement(): void
    {
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();

        $filter = $this->filter();
        $accepted = $filter->apply($trace);

        $this->assertCount(60, $accepted);
        $this->assertSame(0, $filter->rejectedCount());
    }

    #[Test]
    public function un_saut_de_multipath_est_rejete(): void
    {
        // Le cas des Almadies : le point bondit de 150 m en une seconde.
        // Sans ce filtre, la sortie gagnerait 300 m fantômes (aller-retour).
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 30)
            ->multipathJump(150.0)
            ->straight(speedMps: 6.0, seconds: 30)
            ->build();

        $filter = $this->filter();
        $accepted = $filter->apply($trace);

        $this->assertSame(1, $filter->rejections()[GpsFilter::REASON_SPEED] ?? 0);
        $this->assertCount(count($trace) - 1, $accepted);
    }

    #[Test]
    public function un_saut_ne_fausse_pas_la_distance(): void
    {
        // La vraie mesure du succès : la distance reste juste.
        $propre = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 60)->build();

        $bruite = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 30)
            ->multipathJump(150.0)
            ->straight(speedMps: 6.0, seconds: 30)
            ->build();

        $distancePropre = $this->totalDistance($this->filter()->apply($propre));
        $distanceBruitee = $this->totalDistance($this->filter()->apply($bruite));

        // Tolérance de 2 % : le saut retire un intervalle de la trace.
        $this->assertEqualsWithDelta($distancePropre, $distanceBruitee, $distancePropre * 0.02);
    }

    #[Test]
    public function un_point_trop_imprecis_est_rejete(): void
    {
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 10)
            ->poorAccuracy(80.0)
            ->straight(speedMps: 6.0, seconds: 10)
            ->build();

        $filter = $this->filter();
        $filter->apply($trace);

        $this->assertSame(1, $filter->rejections()[GpsFilter::REASON_ACCURACY] ?? 0);
    }

    #[Test]
    public function le_seuil_de_precision_depend_du_sport(): void
    {
        // 22 m passe en cyclisme (seuil 25) mais pas en course (seuil 20).
        $point = new GpsPoint(
            seq: 1,
            lat: 14.6928,
            lng: -17.4467,
            recordedAt: CarbonImmutable::now(),
            accuracyM: 22.0,
        );

        $this->assertTrue($this->filter(Sport::Cycling)->accepts($point));
        $this->assertFalse($this->filter(Sport::Running)->accepts($point));
    }

    #[Test]
    public function les_points_dupliques_sont_ecartes(): void
    {
        // Rejeu d'un lot : les points identiques ne doivent pas doubler la
        // distance. La contrainte en base est le second rempart ; ici c'est
        // le filtre qui les voit passer.
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 20)
            ->duplicateLast(5)
            ->build();

        $filter = $this->filter();
        $accepted = $filter->apply($trace);

        // Les doublons ont un horodatage antérieur ou égal au dernier accepté.
        $this->assertLessThan(count($trace), count($accepted));
        $this->assertGreaterThan(0, $filter->rejectedCount());
    }

    #[Test]
    public function un_point_qui_remonte_le_temps_est_rejete(): void
    {
        $now = CarbonImmutable::parse('2026-09-12 07:30:00');

        $trace = [
            new GpsPoint(1, 14.6928, -17.4467, $now, accuracyM: 5.0),
            new GpsPoint(2, 14.6930, -17.4467, $now->addSeconds(10), accuracyM: 5.0),
            // Horodatage antérieur : fausserait toutes les vitesses suivantes.
            new GpsPoint(3, 14.6932, -17.4467, $now->addSeconds(5), accuracyM: 5.0),
        ];

        $filter = $this->filter();
        $accepted = $filter->apply($trace);

        $this->assertCount(2, $accepted);
        $this->assertSame(1, $filter->rejections()[GpsFilter::REASON_CHRONOLOGY] ?? 0);
    }

    #[Test]
    public function les_coordonnees_aberrantes_sont_rejetees(): void
    {
        $now = CarbonImmutable::parse('2026-09-12 07:30:00');

        $trace = [
            // « Null Island » : ce que renvoient certains appareils sans fix.
            new GpsPoint(1, 0.0, 0.0, $now, accuracyM: 5.0),
            new GpsPoint(2, 91.0, -17.4467, $now->addSecond(), accuracyM: 5.0),
            new GpsPoint(3, 14.6928, 181.0, $now->addSeconds(2), accuracyM: 5.0),
            new GpsPoint(4, 14.6928, -17.4467, $now->addSeconds(3), accuracyM: 5.0),
        ];

        $filter = $this->filter();
        $accepted = $filter->apply($trace);

        $this->assertCount(1, $accepted);
        $this->assertSame(3, $filter->rejections()[GpsFilter::REASON_INVALID] ?? 0);
    }

    #[Test]
    public function les_lots_arrivant_dans_le_desordre_sont_remis_en_ordre(): void
    {
        // Après une reprise de réseau, les lots peuvent arriver mélangés.
        $trace = GpsTraceBuilder::make()->straight(speedMps: 6.0, seconds: 30)->build();
        shuffle($trace);

        $accepted = $this->filter()->apply($trace);

        $sequences = array_map(fn (GpsPoint $p) => $p->seq, $accepted);
        $sorted = $sequences;
        sort($sorted);

        $this->assertSame($sorted, $sequences);
    }

    #[Test]
    public function la_phase_d_acquisition_ecarte_la_position_en_cache(): void
    {
        // Au démarrage, l'OS renvoie sa dernière position connue — parfois
        // celle de la veille, à 20 km. Sans garde-fou, la sortie commencerait
        // par un segment fantôme de 20 km.
        $trace = GpsTraceBuilder::make()
            ->staleFirstFix(20.0)
            ->straight(speedMps: 6.0, seconds: 10)
            ->build();

        $first = GpsFilter::findFirstReliable($trace, Sport::Cycling);

        $this->assertNotNull($first);
        $this->assertNotSame($trace[0]->lat, $first->lat);
    }

    #[Test]
    public function l_acquisition_exige_des_points_nets_consecutifs(): void
    {
        // Trois bons points séparés par des mauvais ne prouvent pas que le
        // signal est stable : la série doit être consécutive.
        $now = CarbonImmutable::parse('2026-09-12 07:30:00');

        $trace = [
            new GpsPoint(1, 14.6928, -17.4467, $now, accuracyM: 5.0),
            new GpsPoint(2, 14.6929, -17.4467, $now->addSecond(), accuracyM: 60.0),
            new GpsPoint(3, 14.6930, -17.4467, $now->addSeconds(2), accuracyM: 5.0),
            new GpsPoint(4, 14.6931, -17.4467, $now->addSeconds(3), accuracyM: 60.0),
        ];

        $this->assertNull(GpsFilter::findFirstReliable($trace, Sport::Cycling));
    }

    #[Test]
    public function chaque_rejet_est_compte_par_motif(): void
    {
        // Sans ce décompte, une trace anormalement courte serait inexplicable.
        $trace = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 10)
            ->poorAccuracy(90.0)
            ->multipathJump(200.0)
            ->straight(speedMps: 6.0, seconds: 10)
            ->build();

        $filter = $this->filter();
        $filter->apply($trace);

        $reasons = $filter->rejections();

        $this->assertArrayHasKey(GpsFilter::REASON_ACCURACY, $reasons);
        $this->assertArrayHasKey(GpsFilter::REASON_SPEED, $reasons);
        $this->assertSame(2, array_sum($reasons));
    }

    /* ---------------------------------------------------------------------- */

    /** @param  list<GpsPoint>  $points */
    private function totalDistance(array $points): float
    {
        $total = 0.0;

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += Distance::between($points[$i - 1], $points[$i]);
        }

        return $total;
    }
}
