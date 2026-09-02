<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un membre inscrit à un défi.
 *
 * `progress` est un **cache** — la vérité est la somme de ses sorties sur la
 * fenêtre du défi. `completed_at`, en revanche, est **figé** : un badge obtenu
 * ne se reprend pas, même si une sortie est ensuite supprimée ou passée en
 * privé. Reprendre une récompense est le plus sûr moyen de faire quitter un
 * club.
 *
 * @property int $progress
 */
final class ChallengeMember extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'completed_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function hasCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** Part de l'objectif atteinte, plafonnée à 100. */
    public function percent(int $target): int
    {
        if ($target <= 0) {
            return 0;
        }

        return (int) min(100, round($this->progress / $target * 100));
    }
}
