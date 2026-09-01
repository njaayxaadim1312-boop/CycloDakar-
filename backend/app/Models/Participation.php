<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Une campagne de collecte.
 *
 * **Tous les montants sont des entiers de FCFA.** Aucun flottant ne touche
 * l'argent, à aucun étage (règle I5 de `docs/finance.md`).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $expected_amount
 * @property ParticipationStatus $status
 */
final class Participation extends Model
{
    /** @use HasFactory<\Database\Factories\ParticipationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'status' => ParticipationStatus::class,
            // `integer` et non `decimal` : le franc CFA n'a pas de centime.
            'expected_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $participation): void {
            $participation->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */
    /* Relations                                                              */
    /* ---------------------------------------------------------------------- */

    /** @return HasMany<ParticipationMember, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ParticipationMember::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
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
     * Collectes visibles d'un utilisateur.
     *
     * Les brouillons ne sortent pas, sauf pour leur auteur et l'administration.
     * Annoncer un montant puis le corriger coûte la confiance du club.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $query->where(function (Builder $q) use ($user): void {
            $q->where('status', '!=', ParticipationStatus::Draft)
                ->orWhere('created_by', $user->id);
        });
    }

    /* ---------------------------------------------------------------------- */
    /* Suivi                                                                  */
    /* ---------------------------------------------------------------------- */

    /**
     * Attendu, encaissé, reste — les trois chiffres du bureau.
     *
     * Calculés par agrégation SQL et non en chargeant les lignes : une collecte
     * annuelle concerne tout le club, et instancier 250 modèles pour en faire
     * une somme serait absurde.
     *
     * Les lignes ANNULÉES sont exclues de l'attendu : un membre dispensé ne
     * doit pas gonfler le montant que le club croit avoir à recevoir.
     *
     * @return array{expected: int, collected: int, remaining: int, members: int, paid_members: int}
     */
    public function tally(): array
    {
        $row = $this->lines()
            ->where('status', '!=', ParticipationMemberStatus::Cancelled)
            ->selectRaw('
                COUNT(*) as members,
                COALESCE(SUM(expected_amount), 0) as expected,
                COALESCE(SUM(paid_amount), 0) as collected
            ')
            ->first();

        $expected = (int) ($row->expected ?? 0);
        $collected = (int) ($row->collected ?? 0);

        $paidMembers = $this->lines()
            ->where('status', ParticipationMemberStatus::Paid)
            ->count();

        return [
            'expected' => $expected,
            'collected' => $collected,
            // Jamais négatif : un trop-perçu se lit dans `collected`, pas en
            // reste négatif, qui n'aurait aucun sens à l'affichage.
            'remaining' => max(0, $expected - $collected),
            'members' => (int) ($row->members ?? 0),
            'paid_members' => $paidMembers,
        ];
    }
}
