<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Où en est un challenge.
 *
 * Le statut est DÉRIVÉ des dates dans la plupart des cas — un challenge dont
 * l'échéance est passée est terminé, quoi qu'on ait écrit en base. Il n'est
 * stocké que pour distinguer un brouillon d'un challenge annoncé, et une
 * annulation d'une fin normale.
 */
enum ChallengeStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Annoncé',
            self::Cancelled => 'Annulé',
        };
    }

    /** Un brouillon n'est visible que de son auteur et de l'administration. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
