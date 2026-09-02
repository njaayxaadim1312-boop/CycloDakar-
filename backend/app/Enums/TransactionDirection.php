<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le sens d'une écriture.
 *
 * **Le signe n'est jamais dans le montant** (docs/finance.md §2). `amount` est
 * toujours positif ; c'est cette énumération qui dit s'il entre ou s'il sort.
 *
 * La raison est pratique : un montant signé se prête aux erreurs de signe
 * silencieuses — un `abs()` oublié quelque part et une dépense se met à
 * créditer la caisse. Avec deux colonnes distinctes, la somme des entrées et
 * la somme des sorties se lisent séparément et se vérifient l'une l'autre.
 */
enum TransactionDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrée',
            self::Out => 'Sortie',
        };
    }

    /** +1 ou −1, pour appliquer le montant au solde. */
    public function sign(): int
    {
        return $this === self::In ? 1 : -1;
    }

    /** Le sens contraire — celui d'une contre-passation. */
    public function opposite(): self
    {
        return $this === self::In ? self::Out : self::In;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
