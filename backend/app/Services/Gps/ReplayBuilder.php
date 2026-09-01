<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;

/**
 * Prépare une trace pour le rejeu animé.
 *
 * La polyligne encodée suffit à DESSINER un parcours, mais pas à le REJOUER :
 * elle ne porte aucun temps. Une animation qui parcourrait la polyligne à
 * vitesse constante mentirait doublement — elle effacerait les pauses, et
 * ferait monter une côte aussi vite qu'une descente. Or c'est précisément ce
 * qu'un membre veut revoir.
 *
 * Ce service renvoie donc des points **horodatés**, avec pour chacun :
 * la seconde écoulée depuis le départ, la distance cumulée et la vitesse
 * instantanée. Le client n'a plus qu'à interpoler entre deux points.
 *
 * Les points sont **décimés** à quelques centaines : une sortie de trois
 * heures en compte plus de dix mille, et les envoyer tous ferait plusieurs
 * mégaoctets pour une animation qui n'affiche pas deux points par pixel.
 */
final class ReplayBuilder
{
    /** Points renvoyés au plus. 600 donnent une animation fluide en 30 s. */
    private const MAX_POINTS = 600;

    /**
     * @return array<string, mixed>
     */
    public function build(Activity $activity): array
    {
        $rows = DB::table('activity_points')
            ->where('activity_id', $activity->id)
            ->orderBy('seq')
            ->get(['lat', 'lng', 'altitude_m', 'recorded_at']);

        if ($rows->count() < 2) {
            return [
                'available' => false,
                'reason' => 'NO_TRACE',
                'points' => [],
            ];
        }

        $points = $this->decimate($rows->all(), self::MAX_POINTS);

        $first = strtotime((string) $points[0]->recorded_at);
        $built = [];
        $distance = 0.0;
        $previous = null;

        foreach ($points as $point) {
            $lat = (float) $point->lat;
            $lng = (float) $point->lng;
            $at = strtotime((string) $point->recorded_at) - $first;

            $speed = 0.0;

            if ($previous !== null) {
                $segment = $this->haversine($previous['lat'], $previous['lng'], $lat, $lng);
                $distance += $segment;

                $elapsed = $at - $previous['t'];
                // La décimation crée de longs segments : la vitesse est celle
                // du SEGMENT, pas du point. C'est ce qu'il faut pour une
                // animation, où l'on interpole justement entre deux points.
                $speed = $elapsed > 0 ? $segment / $elapsed : 0.0;
            }

            $built[] = [
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                // Secondes depuis le départ : le temps réel, pauses comprises.
                't' => $at,
                'd' => (int) round($distance),
                'v' => round($speed, 2),
                'a' => $point->altitude_m !== null ? (int) round((float) $point->altitude_m) : null,
            ];

            $previous = ['lat' => $lat, 'lng' => $lng, 't' => $at];
        }

        return [
            'available' => true,
            'points' => $built,
            // Durée réelle de la sortie : c'est elle que l'accélération
            // rapporte à la durée de la vidéo.
            'duration_s' => $built[count($built) - 1]['t'],
            'distance_m' => $built[count($built) - 1]['d'],
            'bounds' => $this->bounds($built),
            // Les zones traversées jalonnent le récit : « Ouakam, puis la
            // Corniche, puis Popenguine ».
            'zones' => $activity->zones ?? [],
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Réduit la trace en gardant le premier et le dernier point.
     *
     * Un pas régulier suffit ici : la simplification de Douglas-Peucker, qui
     * sert à la polyligne stockée, supprimerait des points en ligne droite —
     * or ce sont eux qui portent le TEMPS. Une longue ligne droite parcourue
     * lentement doit rester lente à l'écran.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    private function decimate(array $rows, int $max): array
    {
        $count = count($rows);

        if ($count <= $max) {
            return $rows;
        }

        $step = $count / $max;
        $kept = [];

        for ($i = 0; $i < $max; $i++) {
            $kept[] = $rows[(int) floor($i * $step)];
        }

        // Le dernier point est conservé quoi qu'il arrive : sinon la trace
        // paraîtrait s'arrêter avant l'arrivée.
        $last = $rows[$count - 1];

        if (end($kept) !== $last) {
            $kept[] = $last;
        }

        return $kept;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     * @return array<string, float>
     */
    private function bounds(array $points): array
    {
        $lats = array_column($points, 'lat');
        $lngs = array_column($points, 'lng');

        return [
            'min_lat' => min($lats),
            'max_lat' => max($lats),
            'min_lng' => min($lngs),
            'max_lng' => max($lngs),
        ];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        if ($lat1 === $lat2 && $lng1 === $lng2) {
            return 0.0;
        }

        $radius = 6_371_008.8;
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLambda / 2) ** 2;

        return 2 * $radius * atan2(sqrt($a), sqrt(1 - $a));
    }
}
