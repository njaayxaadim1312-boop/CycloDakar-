<?php

declare(strict_types=1);

namespace App\Services\Gps;

use Carbon\CarbonImmutable;

/**
 * Un point GPS, en mémoire.
 *
 * Objet valeur immuable : les algorithmes de filtrage et de calcul en
 * manipulent des dizaines de milliers, et pouvoir les modifier au passage
 * rendrait impossible de savoir quelle étape a altéré quoi.
 *
 * Les coordonnées sont des flottants ICI — en mémoire, le temps du calcul.
 * En base elles sont en DECIMAL : voir la migration des activités.
 */
final readonly class GpsPoint
{
    public function __construct(
        public int $seq,
        public float $lat,
        public float $lng,
        public CarbonImmutable $recordedAt,
        public ?float $altitudeM = null,
        public ?float $speedMps = null,
        public ?float $accuracyM = null,
        public ?float $headingDeg = null,
        public bool $isPaused = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            seq: (int) $data['seq'],
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            recordedAt: CarbonImmutable::parse((string) $data['recorded_at']),
            altitudeM: isset($data['altitude_m']) ? (float) $data['altitude_m'] : null,
            speedMps: isset($data['speed_mps']) ? (float) $data['speed_mps'] : null,
            accuracyM: isset($data['accuracy_m']) ? (float) $data['accuracy_m'] : null,
            headingDeg: isset($data['heading_deg']) ? (float) $data['heading_deg'] : null,
            isPaused: (bool) ($data['is_paused'] ?? false),
        );
    }

    /** Secondes écoulées depuis un autre point. Toujours positif ou nul. */
    public function secondsSince(self $other): float
    {
        return max(0.0, $this->recordedAt->getPreciseTimestamp(3) / 1000
            - $other->recordedAt->getPreciseTimestamp(3) / 1000);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(int $activityId): array
    {
        return [
            'activity_id' => $activityId,
            'seq' => $this->seq,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'altitude_m' => $this->altitudeM,
            'speed_mps' => $this->speedMps,
            'accuracy_m' => $this->accuracyM,
            'heading_deg' => $this->headingDeg,
            'recorded_at' => $this->recordedAt->format('Y-m-d H:i:s.v'),
            'is_paused' => $this->isPaused,
        ];
    }
}
