<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cycle de vie d'un événement du club.
 *
 * `DRAFT` existe pour une raison précise : le bureau prépare une sortie
 * plusieurs jours à l'avance, corrige l'horaire, hésite sur le parcours. Rien
 * de tout cela ne doit apparaître aux membres avant que ce ne soit arrêté —
 * une date annoncée puis déplacée coûte plus de confiance qu'une annonce
 * tardive.
 *
 * `ONGOING` et `DONE` ne sont pas décoratifs : ils décident si l'on peut
 * encore s'inscrire, et si l'on peut encore pointer les présents.
 */
enum EventStatus: string
{
    /** En préparation. Visible du seul bureau. */
    case Draft = 'DRAFT';

    /** Annoncé au club. Les inscriptions sont ouvertes. */
    case Published = 'PUBLISHED';

    /** La sortie a commencé. On pointe les présents. */
    case Ongoing = 'ONGOING';

    /** Terminée. Les présences sont figées. */
    case Done = 'DONE';

    /** Annulée. Les inscrits sont conservés — on doit pouvoir les prévenir. */
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Annoncé',
            self::Ongoing => 'En cours',
            self::Done => 'Terminé',
            self::Cancelled => 'Annulé',
        };
    }

    /** Les membres ordinaires peuvent-ils le voir ? */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Peut-on encore s'inscrire ?
     *
     * On accepte pendant `ONGOING` : au Sénégal, un membre qui rejoint le
     * groupe au premier rond-point est un participant réel, et le refuser
     * fausserait la liste des présents.
     */
    public function acceptsRegistrations(): bool
    {
        return $this === self::Published || $this === self::Ongoing;
    }

    /** Peut-on pointer les présences ? */
    public function acceptsAttendance(): bool
    {
        return $this === self::Ongoing || $this === self::Done;
    }

    /**
     * Transitions autorisées.
     *
     * Volontairement restrictif : on ne « dépublie » pas un événement annoncé
     * (les membres l'ont déjà noté — on l'annule, ce qui les prévient), et on
     * ne ressuscite pas un événement annulé.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Cancelled],
            self::Published => [self::Ongoing, self::Done, self::Cancelled],
            self::Ongoing => [self::Done, self::Cancelled],
            self::Done, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
