<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventDifficulty;
use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Une sortie officielle du club.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property EventStatus $status
 * @property Sport $sport
 * @property EventDifficulty|null $difficulty
 * @property int|null $max_participants
 */
final class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sport' => Sport::class,
            'status' => EventStatus::class,
            'difficulty' => EventDifficulty::class,
            'start_lat' => 'float',
            'start_lng' => 'float',
            'planned_distance_m' => 'integer',
            'max_participants' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
    }

    /** L'uuid est la clé publique : l'identifiant interne ne sort jamais. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */
    /* Relations                                                              */
    /* ---------------------------------------------------------------------- */

    /** @return HasMany<EventParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------------- */
    /* Portées                                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * Événements visibles d'un utilisateur donné.
     *
     * Les brouillons ne sortent pas : le bureau prépare une sortie, corrige
     * l'horaire, hésite sur le parcours. Annoncer puis déplacer une date coûte
     * plus de confiance qu'annoncer tard.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $query->where(function (Builder $q) use ($user): void {
            $q->where('status', '!=', EventStatus::Draft)
                // Son auteur voit son propre brouillon : un collecteur qui
                // prépare une sortie doit pouvoir la relire avant publication.
                ->orWhere('created_by', $user->id);
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>=', now())
            ->whereIn('status', [EventStatus::Published, EventStatus::Ongoing]);
    }

    /* ---------------------------------------------------------------------- */
    /* Places                                                                 */
    /* ---------------------------------------------------------------------- */

    /** Nombre de places réellement occupées. */
    public function seatsTaken(): int
    {
        return $this->participants()
            ->where('registration_status', RegistrationStatus::Registered)
            ->count();
    }

    /**
     * Places restantes, ou `null` quand la sortie n'est pas limitée.
     *
     * `null` et non un grand nombre : « illimité » et « il reste 9 999 places »
     * ne s'affichent pas de la même façon.
     */
    public function seatsLeft(): ?int
    {
        if ($this->max_participants === null) {
            return null;
        }

        return max(0, $this->max_participants - $this->seatsTaken());
    }

    public function isFull(): bool
    {
        return $this->seatsLeft() === 0;
    }

    /** Titre lisible dans un journal ou une notification. */
    public function displayTitle(): string
    {
        return $this->title;
    }
}
