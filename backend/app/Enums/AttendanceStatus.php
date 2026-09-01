<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Présence réelle d'un membre à un événement.
 *
 * `UNKNOWN` est l'état par défaut et il est important : tant que personne n'a
 * pointé, on ne sait pas. Le confondre avec `ABSENT` accuserait d'absence des
 * membres présents que le bureau n'a simplement pas eu le temps de pointer —
 * et ces listes servent, à terme, à justifier des participations financières.
 */
enum AttendanceStatus: string
{
    case Unknown = 'UNKNOWN';
    case Present = 'PRESENT';
    case Absent = 'ABSENT';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Non pointé',
            self::Present => 'Présent',
            self::Absent => 'Absent',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
