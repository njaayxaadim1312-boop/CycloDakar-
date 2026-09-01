<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Où en est UN membre sur UNE collecte.
 *
 * Ce statut n'est **jamais reçu du client** : il se déduit du montant encaissé
 * comparé au montant attendu (voir `ParticipationMember::recalculate()`).
 * L'accepter en entrée permettrait de marquer « payé » une dette impayée,
 * c'est-à-dire de falsifier la comptabilité du club par une simple requête.
 */
enum ParticipationMemberStatus: string
{
    case Unpaid = 'NON_PAYE';
    case Partial = 'PARTIELLEMENT_PAYE';
    case Paid = 'PAYE';

    /** Dispensé ou retiré de la collecte. Conservé, jamais effacé. */
    case Cancelled = 'ANNULE';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Non payé',
            self::Partial => 'Partiellement payé',
            self::Paid => 'Payé',
            self::Cancelled => 'Annulé',
        };
    }

    /** Compte-t-elle dans le montant attendu de la collecte ? */
    public function countsAsExpected(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * Déduit le statut d'un couple (attendu, encaissé).
     *
     * Seul endroit du projet qui décide de ce statut.
     */
    public static function derive(int $expected, int $paid): self
    {
        if ($paid <= 0) {
            return self::Unpaid;
        }

        return $paid >= $expected ? self::Paid : self::Partial;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
