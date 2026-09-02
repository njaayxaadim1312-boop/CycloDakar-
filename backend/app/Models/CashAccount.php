<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * La caisse du club.
 *
 * `current_balance` est un **cache**. La vérité est `derivedBalance()`, qui
 * repart du solde d'ouverture et de toutes les écritures. Les deux doivent
 * coïncider ; `php artisan finance:recompute-balance` le vérifie et refuse de
 * se taire en cas d'écart.
 *
 * @property int $opening_balance
 * @property int $current_balance
 */
final class CashAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'current_balance' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<FinancialTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    /**
     * Le solde recalculé depuis le grand livre. **La vérité.**
     *
     * Deux sommes séparées plutôt qu'un `SUM(CASE …)` : les entrées et les
     * sorties se lisent alors indépendamment, et un rapport peut les afficher
     * sans refaire la requête.
     */
    public function derivedBalance(): int
    {
        $entrees = (int) $this->transactions()
            ->where('direction', TransactionDirection::In)->sum('amount');

        $sorties = (int) $this->transactions()
            ->where('direction', TransactionDirection::Out)->sum('amount');

        return $this->opening_balance + $entrees - $sorties;
    }

    /** La caisse utilisée par défaut à l'encaissement. */
    public static function default(): self
    {
        $account = self::query()->where('is_default', true)->where('is_active', true)->first();

        if ($account === null) {
            // Un club sans caisse déclarée ne peut pas encaisser, et un
            // message clair vaut mieux qu'une contrainte de clé étrangère
            // violée trois appels plus bas.
            throw new \RuntimeException(
                "Aucune caisse par défaut n'est configurée. Exécutez « php artisan db:seed --class=FinanceSeeder »."
            );
        }

        return $account;
    }
}
