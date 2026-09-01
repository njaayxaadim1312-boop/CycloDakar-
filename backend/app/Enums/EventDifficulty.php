<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Difficulté annoncée d'une sortie.
 *
 * Information de sécurité autant que de confort : un membre qui débute doit
 * pouvoir écarter d'un coup d'œil une sortie de 80 km. Les repères de
 * distance sont indicatifs et propres au cyclisme de loisir dakarois.
 */
enum EventDifficulty: string
{
    case Easy = 'EASY';
    case Medium = 'MEDIUM';
    case Hard = 'HARD';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'Facile',
            self::Medium => 'Modéré',
            self::Hard => 'Difficile',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Easy => 'Accessible à tous, allure de groupe',
            self::Medium => 'Rythme soutenu, quelques relances',
            self::Hard => 'Longue distance ou allure élevée',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
