<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un encaissement.
 *
 * **Le montant ne change jamais.** Une erreur s'annule (`cancelled_at` + une
 * contre-passation au grand livre), elle ne se corrige pas sur place : sinon
 * le reçu de 5 000 FCFA que le membre a dans sa poche ne correspondrait plus à
 * rien. Le modèle interdit donc de toucher `amount` après création.
 *
 * @property int $amount
 * @property PaymentMethod $method
 */
final class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'paid_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $payment): void {
            // L'annulation met à jour le tampon, le motif et la
            // contre-passation — jamais l'argent lui-même.
            foreach (['amount', 'method', 'member_id', 'participation_member_id'] as $gele) {
                if ($payment->isDirty($gele)) {
                    throw new \LogicException(
                        "Un encaissement est immuable : « {$gele} » ne se modifie pas. "
                        .'Annulez le paiement et saisissez-en un nouveau.'
                    );
                }
            }
        });

        static::deleting(function (): never {
            throw new \LogicException(
                'Un encaissement ne se supprime pas : annulez-le, ce qui écrit une '
                .'contre-passation et conserve la trace.'
            );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */

    /** @return BelongsTo<ParticipationMember, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ParticipationMember::class, 'participation_member_id');
    }

    /** @return BelongsTo<Participation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return BelongsTo<FinancialTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    /* ---------------------------------------------------------------------- */

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
