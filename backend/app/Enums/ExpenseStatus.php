<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Où en est une dépense.
 *
 * **`PENDING` n'a AUCUNE ligne au grand livre** (règle I4 de
 * `docs/finance.md`). Une dépense en attente n'est pas de l'argent sorti :
 * c'est une intention. L'écriture naît dans la même transaction SQL que le
 * passage à `APPROVED`, jamais avant.
 *
 * C'est pour cela que le tableau de bord distingue le solde disponible du
 * montant « engagé ». Confondre les deux ferait croire à un trésorier qu'il a
 * moins d'argent qu'il n'en a — ou, plus grave, qu'il en a plus.
 */
enum ExpenseStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Approuvée',
            self::Rejected => 'Refusée',
        };
    }

    /** Cette dépense a-t-elle réellement sorti de l'argent ? */
    public function movedMoney(): bool
    {
        return $this === self::Approved;
    }

    /** Compte-t-elle dans les engagements — décidés mais pas encore payés ? */
    public function isCommitment(): bool
    {
        return $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
