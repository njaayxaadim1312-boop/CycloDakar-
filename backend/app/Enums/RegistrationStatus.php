<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Inscription d'un membre à un événement.
 *
 * `CANCELLED` est conservé plutôt que supprimé : le bureau doit pouvoir
 * distinguer « ne s'est jamais inscrit » de « s'est désisté ». Sur une sortie
 * à places limitées, la différence compte.
 */
enum RegistrationStatus: string
{
    case Registered = 'REGISTERED';
    case Waitlist = 'WAITLIST';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Inscrit',
            self::Waitlist => "Liste d'attente",
            self::Cancelled => 'Désisté',
        };
    }

    /** Occupe-t-elle une place ? */
    public function holdsSeat(): bool
    {
        return $this === self::Registered;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
