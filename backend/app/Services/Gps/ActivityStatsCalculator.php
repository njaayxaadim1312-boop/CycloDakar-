<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Enums\Sport;

/**
 * Calcul des statistiques d'une activité, à partir des points bruts.
 *
 * **Le client n'est jamais cru.** Le téléphone affiche des chiffres pendant la
 * sortie pour le confort de l'utilisateur, mais tout est recalculé ici à la
 * finalisation. C'est ce qui garantit qu'un client modifié ne peut pas
 * s'inventer 200 km pour gagner le classement du mois.
 *
 * Toutes les valeurs produites sont en unités SI : mètres, secondes, m/s.
 * La conversion en km, km/h et min/km appartient à l'affichage.
 *
 * Algorithmes détaillés dans docs/gps.md.
 */
final class ActivityStatsCalculator
{
    public function __construct(
        private readonly ElevationCalculator $elevation = new ElevationCalculator,
        private readonly PolylineEncoder $polyline = new PolylineEncoder,
    ) {}

    /**
     * @param  list<GpsPoint>  $points  Points DÉJÀ filtrés, triés par `seq`.
     * @return array<string, mixed>
     */
    public function calculate(array $points, Sport $sport, ?float $weightKg = null): array
    {
        if (count($points) < 2) {
            return $this->emptyStats(count($points) === 1 ? $points[0] : null);
        }

        $movement = $this->measureMovement($points);
        $elevation = $this->elevation->calculate($points);

        $first = $points[0];
        $last = $points[count($points) - 1];

        $durationS = (int) round($last->secondsSince($first));
        $movingTimeS = (int) round($movement['moving_time_s']);
        $distanceM = (int) round($movement['distance_m']);

        // La vitesse moyenne se calcule sur le temps ACTIF, pas sur le temps
        // total : sinon une pause déjeuner de 40 min ferait passer une sortie
        // à 12 km/h de moyenne alors qu'on roulait à 24.
        $avgSpeed = $movingTimeS > 0 ? $distanceM / $movingTimeS : 0.0;

        $simplified = $this->polyline->simplify($points);

        return [
            'distance_m' => $distanceM,
            'duration_s' => $durationS,
            'moving_time_s' => $movingTimeS,
            'paused_time_s' => max(0, $durationS - $movingTimeS),

            'avg_speed_mps' => round($avgSpeed, 3),
            'max_speed_mps' => round($movement['max_speed_mps'], 3),

            'elevation_gain_m' => $elevation['gain'],
            'elevation_loss_m' => $elevation['loss'],
            'min_altitude_m' => $elevation['min'],
            'max_altitude_m' => $elevation['max'],

            'avg_pace_s_per_km' => $this->pace($distanceM, $movingTimeS),
            'best_pace_s_per_km' => $this->bestSplitPace($movement['splits']),

            'calories_kcal' => $this->calories($sport, $movingTimeS, $weightKg),

            'polyline' => $this->polyline->encode($simplified),
            'bounds' => $this->polyline->bounds($points),

            'start_lat' => $first->lat,
            'start_lng' => $first->lng,
            'end_lat' => $last->lat,
            'end_lng' => $last->lng,

            'points_count' => count($points),

            'splits' => $movement['splits'],
            'elevation_profile' => $this->elevationProfile($points),
            'speed_histogram' => $movement['histogram'],
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Parcourt la trace une seule fois pour en tirer distance, temps actif,
     * vitesse maximale, splits kilométriques et histogramme.
     *
     * Un seul passage plutôt que cinq : sur 10 000 points, relire la trace à
     * chaque mesure quintuplerait le coût de la finalisation, qui se produit
     * au moment précis où le membre attend son résumé.
     *
     * @param  list<GpsPoint>  $points
     * @return array{distance_m: float, moving_time_s: float, max_speed_mps: float,
     *               splits: list<array<string, mixed>>, histogram: array<string, int>}
     */
    private function measureMovement(array $points): array
    {
        $minSegment = (float) config('cyclo.gps.min_segment_m', 1.0);
        $idleSpeed = (float) config('cyclo.gps.idle_speed_mps', 0.8);

        $distance = 0.0;
        $movingTime = 0.0;
        $maxSpeed = 0.0;

        $splits = [];
        $splitDistance = 0.0;
        $splitTime = 0.0;
        $splitIndex = 1;

        /** @var array<string, int> $histogram */
        $histogram = [];

        // Lissage de la vitesse : la vitesse maximale est prise sur la valeur
        // LISSÉE, jamais sur une mesure isolée. Sans cela, un seul point
        // aberrant fixerait un record personnel à 87 km/h.
        $smoothedSpeed = null;

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $previous = $points[$i - 1];
            $current = $points[$i];

            $elapsed = $current->secondsSince($previous);

            if ($elapsed <= 0.0) {
                continue;
            }

            $segment = Distance::between($previous, $current);

            // Segment sous le seuil : c'est le tremblement du GPS à l'arrêt,
            // pas un déplacement. L'ignorer évite d'accumuler 300 m sur une
            // pause d'un quart d'heure.
            if ($segment < $minSegment) {
                continue;
            }

            // Une pause déclarée par l'utilisateur ne compte ni en distance
            // ni en temps actif : il peut marcher, prendre un taxi, rentrer.
            if ($current->isPaused || $previous->isPaused) {
                continue;
            }

            $speed = $segment / $elapsed;

            $smoothedSpeed = $smoothedSpeed === null
                ? $speed
                // Lissage exponentiel : réactif aux vraies accélérations,
                // insensible à un point isolé.
                : 0.7 * $smoothedSpeed + 0.3 * $speed;

            $distance += $segment;

            // Le temps actif exclut les moments passés sous la vitesse de
            // marche lente : feux rouges, ravitaillements, photos.
            if ($speed >= $idleSpeed) {
                $movingTime += $elapsed;
                $maxSpeed = max($maxSpeed, $smoothedSpeed);

                $bucket = (string) (int) floor($speed * 3.6 / 5) * 5;
                $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
            }

            // Splits kilométriques.
            $splitDistance += $segment;
            $splitTime += $elapsed;

            while ($splitDistance >= 1000.0) {
                // Le segment peut franchir la borne du kilomètre : on
                // n'attribue au split que la part qui lui revient.
                $overflow = $splitDistance - 1000.0;
                $ratio = $segment > 0 ? ($segment - $overflow) / $segment : 1.0;
                $timeForSplit = $splitTime - ($elapsed * (1 - $ratio));

                $splits[] = [
                    'km' => $splitIndex,
                    'duration_s' => (int) round($timeForSplit),
                    'pace_s_per_km' => (int) round($timeForSplit),
                    'speed_mps' => $timeForSplit > 0 ? round(1000 / $timeForSplit, 3) : 0.0,
                ];

                $splitIndex++;
                $splitDistance = $overflow;
                $splitTime = $elapsed * (1 - $ratio);
            }
        }

        return [
            'distance_m' => $distance,
            'moving_time_s' => $movingTime,
            'max_speed_mps' => $maxSpeed,
            'splits' => $splits,
            'histogram' => $histogram,
        ];
    }

    /** Allure moyenne, en secondes par kilomètre. */
    private function pace(int $distanceM, int $movingTimeS): ?int
    {
        if ($distanceM < 100 || $movingTimeS <= 0) {
            return null;
        }

        return (int) round($movingTimeS / ($distanceM / 1000));
    }

    /**
     * Meilleure allure, calculée sur le kilomètre le plus rapide.
     *
     * Et non sur un point isolé : la « vitesse maximale instantanée » est une
     * mesure fragile, alors qu'un kilomètre complet est un effort réel dont on
     * peut être fier.
     *
     * @param  list<array<string, mixed>>  $splits
     */
    private function bestSplitPace(array $splits): ?int
    {
        if ($splits === []) {
            return null;
        }

        return (int) min(array_column($splits, 'pace_s_per_km'));
    }

    /**
     * Calories estimées : MET × poids × durée.
     *
     * Estimation grossière et assumée comme telle. Sans le poids du membre —
     * que le club ne demande pas — on ne renvoie rien plutôt qu'un chiffre
     * calculé sur un poids moyen inventé.
     */
    private function calories(Sport $sport, int $movingTimeS, ?float $weightKg): ?int
    {
        if ($weightKg === null || $movingTimeS <= 0) {
            return null;
        }

        return (int) round($sport->met() * $weightKg * ($movingTimeS / 3600));
    }

    /**
     * Profil d'altitude réduit à ~200 points.
     *
     * Un graphique n'a pas besoin de 10 000 valeurs : l'écran fait 400 pixels
     * de large, et transmettre le reste ne ferait que ralentir l'affichage.
     *
     * @param  list<GpsPoint>  $points
     * @return list<array{d: int, a: int}>
     */
    private function elevationProfile(array $points): array
    {
        $target = 200;
        $count = count($points);
        $step = max(1, (int) ceil($count / $target));

        $profile = [];
        $distance = 0.0;

        for ($i = 1; $i < $count; $i++) {
            $distance += Distance::between($points[$i - 1], $points[$i]);

            if ($i % $step === 0 && $points[$i]->altitudeM !== null) {
                $profile[] = [
                    'd' => (int) round($distance),
                    'a' => (int) round($points[$i]->altitudeM),
                ];
            }
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(?GpsPoint $single): array
    {
        return [
            'distance_m' => 0,
            'duration_s' => 0,
            'moving_time_s' => 0,
            'paused_time_s' => 0,
            'avg_speed_mps' => 0.0,
            'max_speed_mps' => 0.0,
            'elevation_gain_m' => 0,
            'elevation_loss_m' => 0,
            'min_altitude_m' => null,
            'max_altitude_m' => null,
            'avg_pace_s_per_km' => null,
            'best_pace_s_per_km' => null,
            'calories_kcal' => null,
            'polyline' => null,
            'bounds' => null,
            'start_lat' => $single?->lat,
            'start_lng' => $single?->lng,
            'end_lat' => $single?->lat,
            'end_lng' => $single?->lng,
            'points_count' => $single === null ? 0 : 1,
            'splits' => [],
            'elevation_profile' => [],
            'speed_histogram' => [],
        ];
    }
}
