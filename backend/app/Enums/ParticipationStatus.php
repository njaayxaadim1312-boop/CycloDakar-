<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cycle de vie d'une campagne de collecte.
 *
 * `DRAFT` n'est pas une commodité : le bureau prépare une collecte, ajuste le
 * montant, choisit les membres concernés. Annoncer 5 000 FCFA puis corriger à
 * 7 500 coûterait bien plus que d'annoncer un jour plus tard.
 *
 * `CLOSED` fige la collecte : on n'y ajoute plus de membre et on n'y encaisse
 * plus. C'est ce qui permet de dire « la collecte du Lac Rose est soldée » et
 * que le chiffre ne bouge plus.
 */
enum ParticipationStatus: string
{
    /** En préparation. Visible du seul bureau. */
    case Draft = 'DRAFT';

    /** Ouverte : les membres sont informés, les encaissements possibles. */
    case Open = 'OPEN';

    /** Terminée. Les montants sont figés. */
    case Closed = 'CLOSED';

    /** Annulée. Les lignes sont conservées — il faut pouvoir s'expliquer. */
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Open => 'Ouverte',
            self::Closed => 'Clôturée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Les collecteurs et les membres concernés peuvent-ils la voir ? */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /** Peut-on encore y rattacher des membres ? */
    public function acceptsAssignments(): bool
    {
        return $this === self::Draft || $this === self::Open;
    }

    /** Peut-on encore encaisser ? (utilisé à partir de la PHASE 12) */
    public function acceptsPayments(): bool
    {
        return $this === self::Open;
    }

    /**
     * Transitions autorisées.
     *
     * On ne rouvre pas une collecte clôturée : les comptes ont été arrêtés,
     * et y rajouter une recette après coup fausserait le rapport déjà présenté.
     * On en crée une nouvelle.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Cancelled],
            self::Open => [self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
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
