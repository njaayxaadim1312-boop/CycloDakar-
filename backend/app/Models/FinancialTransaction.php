<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Une écriture du grand livre. **Immuable.**
 *
 * Ce modèle refuse activement la mise à jour et la suppression : les deux
 * lèvent une exception. Ce n'est pas de la coquetterie — c'est le seul moyen
 * de garantir la règle I2 ailleurs que dans la revue de code. Un jour, une
 * commande d'import ou un correctif pressé appellera `->update()` sur cette
 * table ; il vaut mieux qu'il s'arrête net plutôt que de fausser un solde en
 * silence.
 *
 * La seule exception : `reversed_by`, qui est une relation en LECTURE et non
 * une colonne — c'est la contre-passation qui pointe l'écriture annulée, pas
 * l'inverse. L'écriture d'origine n'est donc jamais touchée.
 *
 * @property int $amount
 * @property int $balance_after
 * @property TransactionDirection $direction
 * @property TransactionSource $source_type
 */
final class FinancialTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'source_type' => TransactionSource::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->uuid ??= (string) Str::uuid();
        });

        // Le grand livre est append-only. Ces deux gardes rendent la règle
        // exécutable, pas seulement écrite dans un document.
        static::updating(function (): never {
            throw new \LogicException(
                'Une écriture du grand livre est immuable : corrigez par contre-passation '
                .'(CashLedger::reverse), jamais par mise à jour.'
            );
        });

        static::deleting(function (): never {
            throw new \LogicException(
                'Une écriture du grand livre ne se supprime pas : corrigez par contre-passation '
                .'(CashLedger::reverse).'
            );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------- */

    /** @return BelongsTo<CashAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    /** @return BelongsTo<TransactionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** L'écriture que celle-ci annule. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /** La contre-passation qui a annulé celle-ci, s'il y en a une. */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    /* ---------------------------------------------------------------------- */

    /** Le montant signé, pour un calcul. Jamais stocké ainsi. */
    public function signedAmount(): int
    {
        return $this->direction->sign() * $this->amount;
    }
}
