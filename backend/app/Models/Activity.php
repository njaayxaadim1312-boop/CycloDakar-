<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\Sport;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une sortie enregistrée au GPS.
 *
 * Seuls le titre, les notes et la visibilité sont assignables en masse : tout
 * le reste (distance, durée, vitesses, dénivelé) est RECALCULÉ par le serveur
 * à partir des points bruts. Un client qui enverrait « distance_m: 200000 »
 * le verrait ignoré — c'est ce qui protège les classements.
 */
#[Fillable(['title', 'notes', 'visibility'])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'status' => ActivityStatus::class,
            'visibility' => ActivityVisibility::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'synced_at' => 'datetime',
            'bounds' => 'array',
            'zones' => 'array',
            'device_info' => 'array',
            'avg_speed_mps' => 'float',
            'max_speed_mps' => 'float',
        ];
    }

    /**
     * L'uuid est fourni par le CLIENT (clé d'idempotence de la synchronisation),
     * et c'est aussi lui qui adresse la ressource dans les URL.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */
    /* Relations                                                              */
    /* ---------------------------------------------------------------------- */

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<ActivityPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(ActivityPoint::class)->orderBy('seq');
    }

    /** @return HasOne<ActivityStat, $this> */
    public function stats(): HasOne
    {
        return $this->hasOne(ActivityStat::class);
    }

    /* ---------------------------------------------------------------------- */
    /* Affichage                                                              */
    /* ---------------------------------------------------------------------- */

    /**
     * Titre par défaut : « Cyclisme du 12 septembre au matin ».
     * Bien plus parlant dans une liste que « Activité #4213 ».
     */
    public function displayTitle(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        $local = $this->started_at->timezone((string) config('cyclo.club.timezone'));
        $hour = (int) $local->format('H');

        $moment = match (true) {
            $hour < 11 => 'au matin',
            $hour < 14 => 'à midi',
            $hour < 18 => "de l'après-midi",
            default => 'du soir',
        };

        return sprintf(
            '%s du %s %s',
            $this->sport->label(),
            $local->translatedFormat('j F'),
            $moment,
        );
    }

    /* ---------------------------------------------------------------------- */
    /* Filtres                                                                */
    /* ---------------------------------------------------------------------- */

    /** @param  Builder<self>  $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', ActivityStatus::Completed);
    }

    /**
     * Activités visibles par un membre donné.
     *
     * Les siennes quel que soit leur statut, et celles du club marquées `CLUB`
     * ou `PUBLIC`. Une trace GPS révèle le domicile et les habitudes : la
     * visibilité par défaut est `CLUB`, jamais `PUBLIC`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Member $member): void
    {
        $query->where(function (Builder $q) use ($member): void {
            $q->whereIn('visibility', [
                ActivityVisibility::Club->value,
                ActivityVisibility::Public->value,
            ]);

            if ($member !== null) {
                $q->orWhere('member_id', $member->id);
            }
        });
    }
}
