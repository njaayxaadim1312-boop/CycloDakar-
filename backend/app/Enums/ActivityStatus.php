<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cycle de vie d'une activité.
 *
 * L'état vit côté SERVEUR et décrit ce que le serveur sait, pas ce que le
 * téléphone affiche. Une activité peut rester `RECORDING` des heures pendant
 * que le mobile enregistre hors ligne : c'est normal, elle passera à
 * `COMPLETED` au moment de la synchronisation finale.
 */
enum ActivityStatus: string
{
    /** Ouverte, des points peuvent encore arriver. */
    case Recording = 'RECORDING';

    /** Terminée côté serveur : statistiques recalculées, trace figée. */
    case Completed = 'COMPLETED';

    /** Abandonnée par le membre. Conservée, mais hors statistiques. */
    case Discarded = 'DISCARDED';

    public function label(): string
    {
        return match ($this) {
            self::Recording => 'En cours',
            self::Completed => 'Terminée',
            self::Discarded => 'Abandonnée',
        };
    }

    /** Peut-elle encore recevoir des points ? */
    public function acceptsPoints(): bool
    {
        return $this === self::Recording;
    }

    /** Compte-t-elle dans les statistiques et les classements ? */
    public function countsInStats(): bool
    {
        return $this === self::Completed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
