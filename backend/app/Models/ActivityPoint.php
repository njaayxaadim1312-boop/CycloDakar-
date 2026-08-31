<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Gps\GpsPoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un point de la trace brute.
 *
 * `$timestamps = false` : deux colonnes de plus multipliées par des centaines
 * de millions de lignes, pour une information que `recorded_at` porte déjà.
 *
 * Ce modèle sert à la LECTURE. L'écriture passe par une insertion en masse
 * (voir `ActivitySyncService`) : instancier 10 000 modèles Eloquent pour une
 * seule sortie consommerait plusieurs centaines de mégaoctets.
 */
class ActivityPoint extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'altitude_m' => 'float',
            'speed_mps' => 'float',
            'accuracy_m' => 'float',
            'heading_deg' => 'float',
            'recorded_at' => 'immutable_datetime',
            'is_paused' => 'boolean',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** Conversion vers l'objet valeur manipulé par les algorithmes GPS. */
    public function toGpsPoint(): GpsPoint
    {
        return new GpsPoint(
            seq: (int) $this->seq,
            lat: (float) $this->lat,
            lng: (float) $this->lng,
            recordedAt: CarbonImmutable::parse($this->recorded_at),
            altitudeM: $this->altitude_m,
            speedMps: $this->speed_mps,
            accuracyM: $this->accuracy_m,
            headingDeg: $this->heading_deg,
            isPaused: (bool) $this->is_paused,
        );
    }
}
