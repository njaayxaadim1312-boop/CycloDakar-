<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Gps\GpsPoint;
use Carbon\CarbonImmutable;

/**
 * Construit des traces GPS synthétiques pour les tests.
 *
 * Une trace fabriquée est préférable à un enregistrement réel : on connaît sa
 * distance exacte au mètre près, on peut y injecter un défaut précis (un saut,
 * un point aberrant, une pause) et vérifier que l'algorithme réagit comme
 * prévu. Un vrai fichier GPX ne dirait jamais quelle est la « bonne » réponse.
 *
 * Les coordonnées partent de la Corniche Ouest de Dakar, pour que les traces
 * ressemblent à ce que le club produira réellement.
 */
final class GpsTraceBuilder
{
    /** Corniche Ouest, Dakar. */
    private const START_LAT = 14.6928;

    private const START_LNG = -17.4467;

    /** Un degré de latitude vaut ~111 320 m, partout. */
    private const METERS_PER_DEGREE_LAT = 111_320.0;

    /** @var list<GpsPoint> */
    private array $points = [];

    private int $seq = 0;

    private CarbonImmutable $clock;

    private float $lat = self::START_LAT;

    private float $lng = self::START_LNG;

    private float $altitude = 12.0;

    public function __construct(?CarbonImmutable $start = null)
    {
        $this->clock = $start ?? CarbonImmutable::parse('2026-09-12 07:30:00');
    }

    public static function make(?CarbonImmutable $start = null): self
    {
        return new self($start);
    }

    /**
     * Avance vers le nord en ligne droite.
     *
     * @param  float  $speedMps  vitesse constante
     * @param  int  $seconds  durée du segment
     * @param  int  $intervalS  cadence de capture
     */
    public function straight(
        float $speedMps,
        int $seconds,
        int $intervalS = 1,
        float $accuracyM = 5.0,
    ): self {
        for ($t = 0; $t < $seconds; $t += $intervalS) {
            $this->lat += ($speedMps * $intervalS) / self::METERS_PER_DEGREE_LAT;
            $this->clock = $this->clock->addSeconds($intervalS);
            $this->push($accuracyM);
        }

        return $this;
    }

    /**
     * Pause déclarée par l'utilisateur : le temps passe, la position ne bouge
     * pas (au tremblement du GPS près).
     */
    public function pause(int $seconds, int $intervalS = 1): self
    {
        for ($t = 0; $t < $seconds; $t += $intervalS) {
            // Tremblement de ±0,3 m : c'est ce que produit un GPS posé sur une
            // table, et c'est précisément ce que le filtre doit ignorer.
            $this->lat += (mt_rand(-3, 3) / 10) / self::METERS_PER_DEGREE_LAT;
            $this->clock = $this->clock->addSeconds($intervalS);
            $this->push(5.0, paused: true);
        }

        return $this;
    }

    /**
     * Arrêt non déclaré : feu rouge, ravitaillement. Le membre n'a pas appuyé
     * sur pause, mais il ne bouge pas.
     */
    public function idle(int $seconds, int $intervalS = 1): self
    {
        for ($t = 0; $t < $seconds; $t += $intervalS) {
            $this->lat += (mt_rand(-3, 3) / 10) / self::METERS_PER_DEGREE_LAT;
            $this->clock = $this->clock->addSeconds($intervalS);
            $this->push(5.0);
        }

        return $this;
    }

    /**
     * Saut de multipath : le point bondit de N mètres puis revient.
     * C'est le défaut typique des Almadies et du Plateau.
     */
    public function multipathJump(float $meters = 150.0): self
    {
        $this->clock = $this->clock->addSecond();
        $this->seq++;

        $this->points[] = new GpsPoint(
            seq: $this->seq,
            lat: $this->lat + ($meters / self::METERS_PER_DEGREE_LAT),
            lng: $this->lng,
            recordedAt: $this->clock,
            altitudeM: $this->altitude,
            accuracyM: 8.0, // Le GPS se croit précis : c'est tout le problème.
        );

        return $this;
    }

    /** Point trop imprécis pour être exploitable. */
    public function poorAccuracy(float $accuracyM = 80.0): self
    {
        $this->lat += 5 / self::METERS_PER_DEGREE_LAT;
        $this->clock = $this->clock->addSecond();
        $this->push($accuracyM);

        return $this;
    }

    /**
     * Position en cache renvoyée par l'OS au démarrage : celle de la veille,
     * à plusieurs kilomètres, avec une précision médiocre.
     */
    public function staleFirstFix(float $kilometersAway = 20.0): self
    {
        $this->seq++;

        $this->points[] = new GpsPoint(
            seq: $this->seq,
            lat: $this->lat + (($kilometersAway * 1000) / self::METERS_PER_DEGREE_LAT),
            lng: $this->lng,
            recordedAt: $this->clock,
            altitudeM: $this->altitude,
            accuracyM: 65.0,
        );

        return $this;
    }

    /** Monte régulièrement sur la distance parcourue. */
    public function climb(float $meters, int $overSeconds, float $speedMps = 5.0): self
    {
        $steps = max(1, $overSeconds);
        $perStep = $meters / $steps;

        for ($t = 0; $t < $steps; $t++) {
            $this->lat += $speedMps / self::METERS_PER_DEGREE_LAT;
            $this->altitude += $perStep;
            $this->clock = $this->clock->addSecond();
            $this->push(5.0);
        }

        return $this;
    }

    /**
     * Parcours plat avec du bruit barométrique.
     *
     * C'est LE cas piège : l'altitude oscille de ±$noiseM autour d'une valeur
     * constante. Un calcul naïf y trouverait des centaines de mètres de
     * dénivelé.
     */
    public function flatWithNoise(int $seconds, float $noiseM = 8.0, float $speedMps = 5.0): self
    {
        $base = $this->altitude;

        for ($t = 0; $t < $seconds; $t++) {
            $this->lat += $speedMps / self::METERS_PER_DEGREE_LAT;
            // Oscillation pseudo-aléatoire déterministe : le test doit donner
            // le même résultat à chaque exécution.
            $this->altitude = $base + (sin($t * 1.7) + sin($t * 0.31)) * ($noiseM / 2);
            $this->clock = $this->clock->addSecond();
            $this->push(5.0);
        }

        $this->altitude = $base;

        return $this;
    }

    /**
     * Une marche lente, avec le tremblement lateral reel d'un GPS.
     *
     * C'est le cas le plus exigeant du filtre, et celui qui a revele le bug
     * de la phase 15bis : a 1,2 m/s, le membre avance moins vite que ne bouge
     * l'incertitude de position. Chaque point tombe a quelques metres de
     * cote, et un calcul naif additionne ce bruit comme du deplacement.
     *
     * L'oscillation est deterministe : un test doit donner le meme resultat
     * a chaque execution.
     *
     * @param  int  $seconds  duree de la marche
     * @param  float  $speedMps  vitesse reelle, en m/s
     * @param  float  $noiseM  amplitude du tremblement lateral, en metres
     */
    public function walkWithJitter(
        int $seconds,
        float $speedMps = 1.2,
        float $noiseM = 3.0,
    ): self {
        $baseLng = $this->lng;

        for ($t = 0; $t < $seconds; $t++) {
            $this->lat += $speedMps / self::METERS_PER_DEGREE_LAT;

            // Le bruit porte sur la LONGITUDE : perpendiculaire a la marche,
            // il n'ajoute aucune distance reelle. Tout metre compte en plus
            // est donc, a coup sur, une erreur.
            $lngMeters = (sin($t * 2.3) + sin($t * 0.77)) * ($noiseM / 2);
            $this->lng = $baseLng + $lngMeters / (self::METERS_PER_DEGREE_LAT * cos(deg2rad($this->lat)));

            $this->clock = $this->clock->addSecond();
            $this->push(4.0);
        }

        $this->lng = $baseLng;

        return $this;
    }

    /**
     * Un telephone POSE, dont le GPS derive.
     *
     * Un recepteur a l'arret ne rend pas deux fois la meme position : il erre
     * lentement, souvent de 3 a 10 m, en suivant les satellites qui passent.
     * Cette derive lente est plus perfide qu'un bruit vif — elle finit par
     * franchir n'importe quel seuil de DISTANCE, et seule la vitesse la
     * demasque.
     *
     * @param  int  $seconds  duree de l'arret
     * @param  float  $driftM  amplitude de l'errance, en metres
     */
    public function stationaryDrift(int $seconds, float $driftM = 8.0): self
    {
        $baseLat = $this->lat;
        $baseLng = $this->lng;

        for ($t = 0; $t < $seconds; $t++) {
            $dLat = (sin($t / 17) + sin($t / 6.3)) * ($driftM / 2);
            $dLng = (cos($t / 11) + sin($t / 4.7)) * ($driftM / 2);

            $this->lat = $baseLat + $dLat / self::METERS_PER_DEGREE_LAT;
            $this->lng = $baseLng
                + $dLng / (self::METERS_PER_DEGREE_LAT * cos(deg2rad($baseLat)));

            $this->clock = $this->clock->addSecond();
            $this->push(8.0);
        }

        $this->lat = $baseLat;
        $this->lng = $baseLng;

        return $this;
    }

    /** Répète les N derniers points à l'identique (rejeu d'un lot). */
    public function duplicateLast(int $count): self
    {
        $tail = array_slice($this->points, -$count);

        foreach ($tail as $point) {
            $this->points[] = $point;
        }

        return $this;
    }

    /** @return list<GpsPoint> */
    public function build(): array
    {
        return $this->points;
    }

    /**
     * Charge utile telle que l'enverrait le mobile.
     *
     * @return list<array<string, mixed>>
     */
    public function toPayload(): array
    {
        return array_map(static fn (GpsPoint $p) => [
            'seq' => $p->seq,
            'lat' => $p->lat,
            'lng' => $p->lng,
            'recorded_at' => $p->recordedAt->format('Y-m-d\TH:i:s.vP'),
            'altitude_m' => $p->altitudeM,
            'speed_mps' => $p->speedMps,
            'accuracy_m' => $p->accuracyM,
            'is_paused' => $p->isPaused,
        ], $this->points);
    }

    public function count(): int
    {
        return count($this->points);
    }

    /* ---------------------------------------------------------------------- */

    private function push(float $accuracyM, bool $paused = false): void
    {
        $this->seq++;

        $this->points[] = new GpsPoint(
            seq: $this->seq,
            lat: $this->lat,
            lng: $this->lng,
            recordedAt: $this->clock,
            altitudeM: $this->altitude,
            accuracyM: $accuracyM,
            isPaused: $paused,
        );
    }
}
