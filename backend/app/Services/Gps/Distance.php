<?php

declare(strict_types=1);

namespace App\Services\Gps;

/**
 * Distances géographiques.
 *
 * Formule de **Haversine**, et non Vincenty : l'écart entre les deux est
 * d'environ 0,3 %, très en dessous de l'erreur du GPS lui-même (5 à 15 m),
 * alors que Vincenty coûte une vingtaine de fois plus cher — sur 10 000 points
 * par sortie et des dizaines de milliers de sorties, la différence se voit.
 */
final class Distance
{
    /** Rayon terrestre moyen (WGS-84), en mètres. */
    private const EARTH_RADIUS_M = 6_371_008.8;

    /** Distance entre deux points, en mètres. */
    public static function between(GpsPoint $a, GpsPoint $b): float
    {
        return self::betweenCoordinates($a->lat, $a->lng, $b->lat, $b->lng);
    }

    public static function betweenCoordinates(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        // Cas fréquent à l'arrêt : deux points identiques. On coupe court
        // plutôt que de payer six fonctions trigonométriques pour zéro.
        if ($lat1 === $lat2 && $lng1 === $lng2) {
            return 0.0;
        }

        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Distance perpendiculaire d'un point à un segment, en mètres.
     *
     * Utilisée par la simplification Douglas-Peucker. Le calcul se fait dans
     * un plan local : à l'échelle de quelques centaines de mètres, la
     * courbure terrestre est négligeable, et une projection exacte coûterait
     * cher pour un résultat identique.
     */
    public static function pointToSegment(
        GpsPoint $point,
        GpsPoint $start,
        GpsPoint $end,
    ): float {
        // Les longitudes rétrécissent avec la latitude : sans ce facteur, un
        // écart est-ouest serait surévalué (×1,03 à Dakar, ×2 en Scandinavie).
        $latScale = cos(deg2rad($start->lat));

        $x = ($point->lng - $start->lng) * $latScale;
        $y = $point->lat - $start->lat;
        $dx = ($end->lng - $start->lng) * $latScale;
        $dy = $end->lat - $start->lat;

        $segmentLengthSquared = $dx * $dx + $dy * $dy;

        // Segment de longueur nulle : la distance au segment est la distance
        // au point de départ.
        if ($segmentLengthSquared === 0.0) {
            return self::between($point, $start);
        }

        // Projection orthogonale, bornée au segment.
        $t = max(0.0, min(1.0, ($x * $dx + $y * $dy) / $segmentLengthSquared));

        $projectedLat = $start->lat + $t * $dy;
        $projectedLng = $start->lng + $t * ($end->lng - $start->lng);

        return self::betweenCoordinates($point->lat, $point->lng, $projectedLat, $projectedLng);
    }
}
