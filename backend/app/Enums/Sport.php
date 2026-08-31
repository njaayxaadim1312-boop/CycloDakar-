<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sports pratiqués par le club.
 *
 * Les seuils de capture et de filtrage GPS vivent dans `config/cyclo.php` et
 * non ici : le club doit pouvoir les ajuster après une sortie d'essai sans
 * toucher au code, et le mobile les récupère par `GET /api/v1/config` pour
 * filtrer exactement comme le serveur recalcule.
 */
enum Sport: string
{
    case Cycling = 'CYCLING';
    case Running = 'RUNNING';
    case Hiking = 'HIKING';

    public function label(): string
    {
        return match ($this) {
            self::Cycling => 'Cyclisme',
            self::Running => 'Course',
            self::Hiking => 'Randonnée',
        };
    }

    /**
     * Faut-il afficher l'allure (min/km) plutôt que la vitesse (km/h) ?
     * Un coureur raisonne en allure, un cycliste en vitesse.
     */
    public function usesPace(): bool
    {
        return $this !== self::Cycling;
    }

    /** Vitesse maximale plausible, en m/s. Au-delà, c'est un saut GPS. */
    public function maxSpeedMps(): float
    {
        return (float) config("cyclo.sports.{$this->value}.max_speed_mps", 25.0);
    }

    /** Précision minimale exigée d'un point, en mètres. */
    public function maxAccuracyM(): float
    {
        return (float) config("cyclo.sports.{$this->value}.max_accuracy_m", 25.0);
    }

    /** Équivalent métabolique, pour l'estimation des calories. */
    public function met(): float
    {
        return (float) config("cyclo.sports.{$this->value}.met", 8.0);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $sport) => $sport->value, self::cases());
    }
}
