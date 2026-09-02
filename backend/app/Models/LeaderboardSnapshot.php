<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne d'un classement FIGÉ.
 *
 * Ces lignes ne concernent que des périodes RÉVOLUES. La période en cours se
 * calcule en direct — la figer n'aurait aucun sens, elle n'est pas finie.
 *
 * Voir la migration pour la raison de fond : un classement recalculé change
 * après coup, parce que les sorties bougent (synchronisation en différé,
 * passage en privé, correction de trace). Reprendre une première place déjà
 * annoncée est le plus sûr moyen de faire quitter un club.
 */
final class LeaderboardSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'value' => 'integer',
            'activities' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
