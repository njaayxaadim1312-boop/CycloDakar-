<?php

declare(strict_types=1);

namespace App\Services\Gps;

/**
 * Encodage et simplification de la trace.
 *
 * Une sortie de 3 h à 1 Hz produit ~10 800 points, soit ~500 Ko de JSON.
 * Envoyer cela pour afficher une miniature dans une liste de 20 activités
 * signifierait 10 Mo par écran — impensable sur un réseau mobile.
 *
 * Deux étapes :
 *
 *  1. **Douglas-Peucker** (tolérance 5 m) : ramène 10 000 points à 400-900,
 *     sans écart visible à l'échelle d'affichage ;
 *  2. **Google Encoded Polyline** : encode la trace simplifiée en une chaîne
 *     de ~1 Ko, lisible directement par Leaflet et react-native-maps.
 *
 * Les points bruts restent en base : ils ne sont relus que pour l'export GPX
 * et le rendu vidéo. Voir docs/architecture.md, ADR-002.
 */
final class PolylineEncoder
{
    /**
     * Simplification de Ramer-Douglas-Peucker.
     *
     * Implémentation **itérative** et non récursive : sur une trace de 10 000
     * points presque rectiligne, la version récursive atteindrait une
     * profondeur de pile de plusieurs milliers d'appels et ferait tomber PHP.
     *
     * @param  list<GpsPoint>  $points
     * @return list<GpsPoint>
     */
    public function simplify(array $points, ?float $toleranceM = null): array
    {
        $tolerance = $toleranceM ?? (float) config('cyclo.gps.simplify_tolerance_m', 5.0);
        $count = count($points);

        if ($count <= 2) {
            return $points;
        }

        // Marque les points à conserver. Les extrémités le sont toujours :
        // le départ et l'arrivée ne doivent jamais être déplacés.
        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;

        /** @var list<array{int, int}> $stack */
        $stack = [[0, $count - 1]];

        while ($stack !== []) {
            [$first, $last] = array_pop($stack);

            $maxDistance = 0.0;
            $farthest = 0;

            for ($i = $first + 1; $i < $last; $i++) {
                $distance = Distance::pointToSegment(
                    $points[$i],
                    $points[$first],
                    $points[$last],
                );

                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                    $farthest = $i;
                }
            }

            // Le point le plus éloigné dépasse la tolérance : il porte une
            // information de forme, on le garde et on traite les deux moitiés.
            if ($maxDistance > $tolerance && $farthest > 0) {
                $keep[$farthest] = true;
                $stack[] = [$first, $farthest];
                $stack[] = [$farthest, $last];
            }
        }

        $simplified = [];

        foreach ($points as $index => $point) {
            if ($keep[$index]) {
                $simplified[] = $point;
            }
        }

        return $simplified;
    }

    /**
     * Encode une trace au format Google Encoded Polyline (précision 5).
     *
     * @param  list<GpsPoint>  $points
     */
    public function encode(array $points): string
    {
        $precision = (int) config('cyclo.gps.polyline_precision', 5);
        $factor = 10 ** $precision;

        $encoded = '';
        $previousLat = 0;
        $previousLng = 0;

        foreach ($points as $point) {
            // On encode des DIFFÉRENCES entre points successifs, pas des
            // valeurs absolues : deux points voisins diffèrent de quelques
            // unités, ce qui tient sur un ou deux caractères.
            $lat = (int) round($point->lat * $factor);
            $lng = (int) round($point->lng * $factor);

            $encoded .= $this->encodeValue($lat - $previousLat);
            $encoded .= $this->encodeValue($lng - $previousLng);

            $previousLat = $lat;
            $previousLng = $lng;
        }

        return $encoded;
    }

    /**
     * Décode une polyline.
     *
     * @return list<array{lat: float, lng: float}>
     */
    public function decode(string $encoded): array
    {
        $precision = (int) config('cyclo.gps.polyline_precision', 5);
        $factor = 10 ** $precision;

        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $lat += $this->decodeValue($encoded, $index);
            $lng += $this->decodeValue($encoded, $index);

            $points[] = ['lat' => $lat / $factor, 'lng' => $lng / $factor];
        }

        return $points;
    }

    /**
     * Rectangle englobant, pour que le client cadre la carte sans lire la trace.
     *
     * @param  list<GpsPoint>  $points
     * @return array{min_lat: float, min_lng: float, max_lat: float, max_lng: float}|null
     */
    public function bounds(array $points): ?array
    {
        if ($points === []) {
            return null;
        }

        $lats = array_map(fn (GpsPoint $p) => $p->lat, $points);
        $lngs = array_map(fn (GpsPoint $p) => $p->lng, $points);

        return [
            'min_lat' => min($lats),
            'min_lng' => min($lngs),
            'max_lat' => max($lats),
            'max_lng' => max($lngs),
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Encode un entier signé selon l'algorithme de Google : décalage d'un bit
     * pour porter le signe, inversion si négatif, puis découpage en groupes
     * de 5 bits décalés de 63 pour rester dans l'ASCII imprimable.
     */
    private function encodeValue(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunks = '';

        while ($value >= 0x20) {
            $chunks .= chr((0x20 | ($value & 0x1f)) + 63);
            $value >>= 5;
        }

        return $chunks.chr($value + 63);
    }

    private function decodeValue(string $encoded, int &$index): int
    {
        $result = 0;
        $shift = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }
}
