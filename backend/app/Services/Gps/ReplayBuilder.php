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
            ->get(['lat', 'lng', 'altitude_m', 'accuracy_m', 'recorded_at']);

        if ($rows->count() < 2) {
            return [
                'available' => false,
                'reason' => 'NO_TRACE',
                'points' => [],
            ];
        }

        /*
         | La distance se calcule sur TOUS les points, avec les memes regles
         | que le reste de l'application, PUIS la trace est decimee.
         |
         | L'inverse — decimer d'abord, additionner ensuite — donnait un
         | chiffre different de celui de la sortie : 38 m dans la video contre
         | 26 m sur la fiche, pour la meme sortie. Deux chiffres pour une meme
         | chose detruisent la confiance plus surement qu'un chiffre
         | approximatif.
         */
        $cumulative = $this->cumulativeDistance($rows->all(), $activity);

        $indices = $this->keptIndices($rows->count(), self::MAX_POINTS);

        $first = strtotime((string) $rows[0]->recorded_at);
        $built = [];
        $previous = null;

        foreach ($indices as $index) {
            $row = $rows[$index];

            $at = strtotime((string) $row->recorded_at) - $first;
            $distance = $cumulative[$index];

            // La vitesse est celle du SEGMENT affiche, pas du point : c'est
            // entre deux points affiches que le client interpole.
            $speed = 0.0;

            if ($previous !== null) {
                $elapsed = $at - $previous['t'];
                $speed = $elapsed > 0 ? ($distance - $previous['d']) / $elapsed : 0.0;
            }

            $built[] = [
                'lat' => round((float) $row->lat, 6),
                'lng' => round((float) $row->lng, 6),
                // Secondes depuis le depart : le temps reel, pauses comprises.
                't' => $at,
                'd' => (int) round($distance),
                'v' => round($speed, 2),
                'a' => $row->altitude_m !== null ? (int) round((float) $row->altitude_m) : null,
            ];

            $previous = ['t' => $at, 'd' => $distance];
        }

        return [
            'available' => true,
            'points' => $built,
            // Duree reelle de la sortie : c'est elle que l'acceleration
            // rapporte a la duree de la video.
            'duration_s' => $built[count($built) - 1]['t'],
            'distance_m' => $built[count($built) - 1]['d'],
            'bounds' => $this->bounds($built),
            // Les zones traversees jalonnent le recit : « Ouakam, puis la
            // Corniche, puis Popenguine ».
            'zones' => $activity->zones ?? [],
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Distance cumulee a chaque point, filtree comme sur la fiche.
     *
     * Reprend mot pour mot les regles d'`ActivityStatsCalculator` :
     *
     *  - une ANCRE, qui ne bouge QUE sur un deplacement reel — et qui reste
     *    en place dans tous les autres cas, ce qui evite de perdre les metres
     *    deja parcourus ;
     *  - un seuil de distance par sport, releve a deux fois la precision
     *    annoncee par l'appareil ;
     *  - une CONFIRMATION de deux secondes, qui separe un depart d'un sursaut
     *    de derive ;
     *  - un seuil de vitesse PAR SPORT, qui demasque la derive d'un recepteur
     *    immobile sans confondre une flanerie avec un arret.
     *
     * Sans cela, la video afficherait une distance qui grimpe alors que le
     * membre est a l'arret, et se terminerait sur un chiffre different de
     * celui de sa sortie.
     *
     * @param  list<object>  $rows
     * @return list<float>
     */
    private function cumulativeDistance(array $rows, Activity $activity): array
    {
        $sport = $activity->sport->value;

        $minSegment = (float) config(
            "cyclo.sports.{$sport}.min_distance_m",
            config('cyclo.gps.min_segment_m', 1.0),
        );
        $idleSpeed = (float) config(
            "cyclo.sports.{$sport}.idle_speed_mps",
            config('cyclo.gps.idle_speed_mps', 0.8),
        );
        $factor = (float) config('cyclo.gps.accuracy_factor', 2.0);
        $confirm = (float) config('cyclo.gps.confirm_move_s', 2.0);

        $pendingAt = null;

        $cumulative = [0.0];
        $distance = 0.0;

        $anchor = $rows[0];
        $anchorAt = strtotime((string) $anchor->recorded_at);

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $current = $rows[$i];
            $at = strtotime((string) $current->recorded_at);
            $elapsed = $at - $anchorAt;

            if ($elapsed > 0) {
                $segment = $this->haversine(
                    (float) $anchor->lat,
                    (float) $anchor->lng,
                    (float) $current->lat,
                    (float) $current->lng,
                );

                $threshold = max(
                    $minSegment,
                    $this->uncertainty($anchor, $current) * $factor,
                );

                if ($segment < $threshold) {
                    // Sous le seuil : bruit. L'ancre RESTE, et un
                    // franchissement en attente est annule — c'etait un
                    // sursaut de derive, pas un depart.
                    $pendingAt = null;
                } else {
                    $pendingAt ??= $at;

                    $speed = $segment / $elapsed;

                    if ($at - $pendingAt >= $confirm && $speed >= $idleSpeed) {
                        // Deplacement reel et confirme : on compte, et
                        // l'ancre suit.
                        $distance += $segment;
                        $anchor = $current;
                        $anchorAt = $at;
                        $pendingAt = null;
                    }
                    // Sinon l'ancre RESTE : les metres attendent au lieu
                    // d'etre perdus. C'etait le defaut de la version
                    // precedente, qui avancait l'ancre au rejet.
                }
            }

            /*
             | LES DERNIERS METRES, comme sur la fiche.
             |
             | Au dernier point il n'y a plus rien a confirmer : ce qui reste
             | entre l'ancre et l'arrivee doit etre credite, sinon la video se
             | terminerait sur un chiffre inferieur a celui de la sortie — et
             | c'est exactement l'ecart qu'un membre remarque.
             |
             | Memes garde-fous que dans `ActivityStatsCalculator` : il faut
             | qu'un deplacement ait deja ete prouve, que la distance depasse
             | le plancher du sport, et que l'allure soit celle de quelqu'un
             | qui bouge.
             */
            if ($i === $n - 1 && $distance > 0.0 && $elapsed > 0) {
                $reste = $this->haversine(
                    (float) $anchor->lat,
                    (float) $anchor->lng,
                    (float) $current->lat,
                    (float) $current->lng,
                );

                if ($reste >= $minSegment && $reste / $elapsed >= $idleSpeed) {
                    $distance += $reste;
                }
            }

            $cumulative[] = $distance;
        }

        return $cumulative;
    }

    /** Incertitude combinee de deux points, en metres. */
    private function uncertainty(object $a, object $b): float
    {
        $values = array_filter(
            [$a->accuracy_m ?? null, $b->accuracy_m ?? null],
            static fn ($value): bool => $value !== null && (float) $value > 0,
        );

        if ($values === []) {
            return 0.0;
        }

        return array_sum(array_map('floatval', $values)) / count($values);
    }

    /**
     * Indices des points conserves pour l'affichage.
     *
     * @return list<int>
     */
    private function keptIndices(int $count, int $max): array
    {
        if ($count <= $max) {
            return range(0, $count - 1);
        }

        $step = $count / $max;
        $indices = [];

        for ($i = 0; $i < $max; $i++) {
            $indices[] = (int) floor($i * $step);
        }

        // Le dernier point est conserve quoi qu'il arrive : sinon la trace
        // paraitrait s'arreter avant l'arrivee.
        if (end($indices) !== $count - 1) {
            $indices[] = $count - 1;
        }

        return $indices;
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
