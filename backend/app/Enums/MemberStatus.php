<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'un membre dans la vie du club.
 *
 * Un membre n'est jamais supprimé : il change de statut. Ses activités, ses
 * paiements et son historique restent rattachés — c'est indispensable pour
 * que la comptabilité du club reste vraie plusieurs années après.
 */
enum MemberStatus: string
{
    /** Inscrit, à jour, participe aux sorties. */
    case Active = 'ACTIVE';

    /** Dossier créé mais pas encore validé par un responsable. */
    case Pending = 'PENDING';

    /** Temporairement écarté (discipline, cotisation impayée). */
    case Suspended = 'SUSPENDED';

    /** A quitté le club. Conservé pour l'historique. */
    case Former = 'FORMER';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Pending => 'En attente',
            self::Suspended => 'Suspendu',
            self::Former => 'Ancien membre',
        };
    }

    /**
     * Ce membre compte-t-il dans l'effectif et les collectes ?
     *
     * Un membre suspendu ou ancien n'est pas ajouté d'office à une nouvelle
     * participation, mais reste consultable et payable s'il régularise.
     */
    public function isCountedInRoster(): bool
    {
        return $this === self::Active;
    }

    /** Peut-il être associé à une nouvelle participation ? */
    public function canJoinParticipation(): bool
    {
        return $this === self::Active || $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
