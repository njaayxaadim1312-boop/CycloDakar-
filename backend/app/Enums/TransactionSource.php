<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * D'où vient une écriture du grand livre.
 *
 * Ce champ n'est pas décoratif : c'est lui qui permet de remonter d'une ligne
 * du journal de caisse à l'acte qui l'a produite — un paiement, une dépense,
 * une saisie manuelle, une correction. Un journal dont on ne peut pas remonter
 * les lignes n'est pas auditable, et un trésorier à qui l'on demande
 * d'expliquer 40 000 FCFA en assemblée générale doit pouvoir montrer la pièce.
 */
enum TransactionSource: string
{
    case Payment = 'payment';
    case Expense = 'expense';
    case Manual = 'manual';
    case Reversal = 'reversal';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Encaissement',
            self::Expense => 'Dépense',
            self::Manual => 'Saisie manuelle',
            self::Reversal => 'Contre-passation',
            self::Opening => 'Solde initial',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
