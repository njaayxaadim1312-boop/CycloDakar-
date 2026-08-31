<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qui peut voir une activité.
 *
 * La trace GPS d'un membre révèle son domicile, ses horaires et ses habitudes.
 * Le défaut est donc `CLUB` et non `PUBLIC` : partager avec le club a du sens,
 * publier sur Internet est une décision qui doit être prise sciemment.
 */
enum ActivityVisibility: string
{
    case Private = 'PRIVATE';
    case Club = 'CLUB';
    case Public = 'PUBLIC';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Privée',
            self::Club => 'Visible par le club',
            self::Public => 'Publique',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $v) => $v->value, self::cases());
    }
}
