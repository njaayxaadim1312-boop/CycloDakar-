<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ce qu'un challenge — ou un classement — mesure.
 *
 * Quatre mesures, et le choix n'est pas décoratif : chacune récompense une
 * chose différente, et un club qui ne proposerait que la distance ferait
 * gagner toujours les mêmes.
 *
 * La RÉGULARITÉ (`activities`) est celle qui compte le plus pour un club :
 * elle met en avant celui qui vient chaque dimanche, pas celui qui a le vélo
 * le plus rapide. Le DÉNIVELÉ récompense l'effort là où la distance
 * récompense le plat.
 */
enum ChallengeMetric: string
{
    case Distance = 'distance';
    case Activities = 'activities';
    case Duration = 'duration';
    case Elevation = 'elevation';

    public function label(): string
    {
        return match ($this) {
            self::Distance => 'Distance',
            self::Activities => 'Nombre de sorties',
            self::Duration => 'Temps en mouvement',
            self::Elevation => 'Dénivelé positif',
        };
    }

    /** L'unité dans laquelle la cible et la progression sont EXPRIMÉES. */
    public function unit(): string
    {
        return match ($this) {
            self::Distance => 'm',
            self::Activities => 'sorties',
            self::Duration => 's',
            self::Elevation => 'm',
        };
    }

    /**
     * La colonne agrégée, ou `null` pour un simple comptage.
     *
     * `moving_time_s` et non `duration_s` : le temps en mouvement exclut les
     * arrêts. Classer sur la durée totale récompenserait celui qui s'arrête
     * le plus longtemps au ravitaillement.
     */
    public function column(): ?string
    {
        return match ($this) {
            self::Distance => 'distance_m',
            self::Activities => null,
            self::Duration => 'moving_time_s',
            self::Elevation => 'elevation_gain_m',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
