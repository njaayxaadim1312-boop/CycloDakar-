<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Séries agrégées d'une activité : splits kilométriques, profil d'altitude,
 * histogramme de vitesse.
 *
 * Stockées à part, et en JSON, parce qu'elles sont volumineuses et rarement
 * lues : la liste des activités n'en a pas besoin, seule la fiche détaillée
 * les affiche. Les charger systématiquement alourdirait l'écran le plus
 * consulté pour servir le moins consulté.
 */
class ActivityStat extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'splits' => 'array',
            'elevation_profile' => 'array',
            'speed_histogram' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
