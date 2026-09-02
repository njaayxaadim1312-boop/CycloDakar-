<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un poste du grand livre.
 *
 * Le SENS fait partie de la catégorie : « Transport » est une sortie et ne
 * peut pas servir à classer une recette. Sans cette contrainte, un rapport
 * annuel pourrait être faux sans qu'aucune règle ne s'en aperçoive.
 *
 * @property TransactionDirection $direction
 */
final class TransactionCategory extends Model
{
    /** Poste des encaissements de collecte — utilisé en dur par le service. */
    public const PARTICIPATION = 'PARTICIPATION';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<FinancialTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    public static function byCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }
}
