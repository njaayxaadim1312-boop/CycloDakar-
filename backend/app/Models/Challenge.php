<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChallengeMetric;
use App\Enums\ChallengeStatus;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Un défi du club : « 500 km en septembre ».
 *
 * `target` est en **unité SI** — mètres, secondes, ou nombre de sorties selon
 * `metric`. La conversion vers les kilomètres se fait à l'affichage, comme
 * partout ailleurs dans le projet.
 *
 * @property int $target
 * @property ChallengeMetric $metric
 * @property ChallengeStatus $status
 */
final class Challenge extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metric' => ChallengeMetric::class,
            'status' => ChallengeStatus::class,
            'sport' => Sport::class,
            'target' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            $challenge->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */

    /** @return HasMany<ChallengeMember, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ChallengeMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Ce que le lecteur a le droit de voir.
     *
     * Un brouillon n'appartient qu'à son auteur et à l'administration : un défi
     * annoncé engage le club, et on doit pouvoir le préparer sans que le
     * calendrier s'affole.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if ($user !== null && $user->role->isAdmin()) {
            return;
        }

        $query->where(function (Builder $q) use ($user): void {
            $q->where('status', ChallengeStatus::Published);

            if ($user !== null) {
                $q->orWhere('created_by', $user->id);
            }
        });
    }

    /* ---------------------------------------------------------------------- */

    /** A-t-il commencé et pas encore fini ? */
    public function isRunning(): bool
    {
        return $this->status === ChallengeStatus::Published
            && ! $this->starts_on->isFuture()
            && ! $this->ends_on->isPast();
    }

    /**
     * Peut-on encore s'y inscrire ?
     *
     * OUI, MÊME EN COURS DE ROUTE — et c'est délibéré. Un membre qui découvre
     * le défi le 15 doit pouvoir tenter sa chance ; sa progression compte
     * depuis le DÉBUT du défi, pas depuis son inscription, sinon on
     * pénaliserait celui qui a simplement ouvert l'application plus tard alors
     * qu'il roulait déjà.
     */
    public function acceptsEntries(): bool
    {
        return $this->status === ChallengeStatus::Published && ! $this->ends_on->isPast();
    }

    /** Jours restants, ou `null` si le défi est terminé. */
    public function daysLeft(): ?int
    {
        if ($this->ends_on->isPast()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->ends_on->endOfDay(), absolute: true);
    }
}
