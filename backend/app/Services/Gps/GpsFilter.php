<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Enums\Sport;

/**
 * Filtre des points GPS — le composant qui décide de la crédibilité d'une trace.
 *
 * Le problème concret : à Dakar, les immeubles du Plateau et des Almadies
 * renvoient le signal (multipath). Le point « saute » de 50 à 200 m, et une
 * sortie de 30 km s'affiche à 38. Le membre cesse alors de faire confiance à
 * l'application — et il a raison.
 *
 * Chaque point candidat passe six tests, dans cet ordre. Le premier échec le
 * rejette, en incrémentant un compteur par motif :
 *
 *   1. VALIDITÉ      coordonnées hors bornes, ou (0,0) au large du Ghana
 *   2. PRÉCISION     accuracy au-delà du seuil du sport
 *   3. CHRONOLOGIE   horodatage antérieur ou égal au point précédent
 *   4. DUPLICAT      immobile depuis moins d'une seconde
 *   5. VITESSE       vitesse implicite impossible pour le sport  ← anti-multipath
 *   6. ACCÉLÉRATION  variation de vitesse humainement impossible
 *
 * Un point rejeté n'est pas perdu : il est compté, et `raw_points_count`
 * conserve le total reçu. C'est ce qui permet d'expliquer une trace courte.
 *
 * Documenté dans docs/gps.md — client et serveur doivent filtrer à
 * l'identique, avec les mêmes seuils servis par `GET /api/v1/config`.
 */
final class GpsFilter
{
    public const REASON_INVALID = 'invalid_coordinates';
    public const REASON_ACCURACY = 'poor_accuracy';
    public const REASON_CHRONOLOGY = 'out_of_order';
    public const REASON_DUPLICATE = 'duplicate';
    public const REASON_SPEED = 'impossible_speed';
    public const REASON_ACCELERATION = 'impossible_acceleration';

    /** @var array<string, int> */
    private array $rejections = [];

    private ?GpsPoint $lastAccepted = null;

    private ?float $lastSpeedMps = null;

    public function __construct(
        private readonly Sport $sport,
    ) {}

    /**
     * Filtre une trace complète.
     *
     * @param  list<GpsPoint>  $points
     * @return list<GpsPoint>
     */
    public function apply(array $points): array
    {
        // On trie par `seq` : les lots peuvent arriver dans le désordre après
        // une reprise de réseau, et un tri par horodatage serait faux si deux
        // points partagent la même milliseconde.
        usort($points, fn (GpsPoint $a, GpsPoint $b) => $a->seq <=> $b->seq);

        $accepted = [];

        foreach ($points as $point) {
            if ($this->accepts($point)) {
                $accepted[] = $point;
            }
        }

        return $accepted;
    }

    /**
     * Un point est-il crédible, compte tenu du précédent accepté ?
     */
    public function accepts(GpsPoint $point): bool
    {
        // 1. Validité des coordonnées.
        if (! $this->hasValidCoordinates($point)) {
            return $this->reject(self::REASON_INVALID);
        }

        // 2. Précision. Un point à ±80 m ne dit rien d'utile.
        if ($point->accuracyM !== null && $point->accuracyM > $this->sport->maxAccuracyM()) {
            return $this->reject(self::REASON_ACCURACY);
        }

        $previous = $this->lastAccepted;

        // Premier point accepté : rien à comparer.
        if ($previous === null) {
            return $this->accept($point, null);
        }

        $elapsed = $point->secondsSince($previous);

        // 3. Chronologie. Un point qui remonte le temps fausserait toutes les
        // vitesses calculées ensuite.
        if ($elapsed <= 0.0) {
            return $this->reject(self::REASON_CHRONOLOGY);
        }

        $distance = Distance::between($previous, $point);

        // 4. Duplicat : à l'arrêt, le GPS produit des points quasi identiques
        // qui n'apportent rien et alourdissent la trace.
        if ($distance < 1.0 && $elapsed < 1.0) {
            return $this->reject(self::REASON_DUPLICATE);
        }

        $impliedSpeed = $distance / $elapsed;

        // 5. Vitesse implicite — LE test qui absorbe le multipath urbain.
        // Un saut de 150 m en 1 s donne 150 m/s : aucun cycliste ne fait cela.
        if ($impliedSpeed > $this->sport->maxSpeedMps()) {
            return $this->reject(self::REASON_SPEED);
        }

        // 6. Accélération. Passer de 5 à 25 m/s en une seconde est impossible ;
        // c'est la signature d'un saut plus court que le seuil précédent.
        if ($this->lastSpeedMps !== null) {
            $acceleration = abs($impliedSpeed - $this->lastSpeedMps) / $elapsed;
            $maxAcceleration = (float) config('cyclo.gps.max_acceleration_mps2', 5.0);

            if ($acceleration > $maxAcceleration) {
                return $this->reject(self::REASON_ACCELERATION);
            }
        }

        return $this->accept($point, $impliedSpeed);
    }

    /**
     * Détecte le premier point exploitable au démarrage.
     *
     * Au lancement, l'OS renvoie volontiers sa dernière position connue —
     * parfois celle de la veille, à 20 km de là. Sans ce garde-fou, la sortie
     * commencerait par un segment fantôme de 20 km.
     *
     * @param  list<GpsPoint>  $points
     */
    public static function findFirstReliable(array $points, Sport $sport): ?GpsPoint
    {
        $required = (int) config('cyclo.gps.warmup_points', 3);
        $accuracy = (float) config('cyclo.gps.warmup_accuracy_m', 20.0);

        $streak = [];

        foreach ($points as $point) {
            if ($point->accuracyM !== null && $point->accuracyM <= $accuracy) {
                $streak[] = $point;

                if (count($streak) >= $required) {
                    return $streak[0];
                }
            } else {
                // La série doit être CONSÉCUTIVE : trois bons points séparés
                // par des mauvais ne prouvent pas que le signal est stable.
                $streak = [];
            }
        }

        return null;
    }

    /** @return array<string, int> Nombre de rejets par motif. */
    public function rejections(): array
    {
        return $this->rejections;
    }

    public function rejectedCount(): int
    {
        return array_sum($this->rejections);
    }

    /* ---------------------------------------------------------------------- */

    private function hasValidCoordinates(GpsPoint $point): bool
    {
        if ($point->lat < -90.0 || $point->lat > 90.0) {
            return false;
        }

        if ($point->lng < -180.0 || $point->lng > 180.0) {
            return false;
        }

        // (0, 0) est le « Null Island » du golfe de Guinée : c'est ce que
        // renvoient certains appareils quand ils n'ont pas de position.
        return ! ($point->lat === 0.0 && $point->lng === 0.0);
    }

    private function accept(GpsPoint $point, ?float $speed): true
    {
        $this->lastAccepted = $point;
        $this->lastSpeedMps = $speed;

        return true;
    }

    private function reject(string $reason): false
    {
        $this->rejections[$reason] = ($this->rejections[$reason] ?? 0) + 1;

        return false;
    }
}
