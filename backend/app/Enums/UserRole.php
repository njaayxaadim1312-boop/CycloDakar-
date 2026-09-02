<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rôles de la plateforme, du moins au plus étendu.
 *
 * LE CHEF DE GROUPE SE PLACE SOUS LE COLLECTEUR, ET C'EST TOUT LE POINT.
 *
 * Encadrer une sortie et manier de l'argent sont deux responsabilités
 * différentes, confiées à des personnes différentes. Tant que planifier un
 * itinéraire exigeait le rôle de collecteur, il fallait donner l'accès à la
 * caisse à quelqu'un qui voulait seulement mener le groupe le dimanche matin.
 * C'est exactement ce qu'une hiérarchie de rôles doit éviter.
 *
 * L'inverse est assumé : un collecteur, étant au-dessus, peut aussi proposer
 * une sortie. C'est sans danger — une sortie mal placée se corrige, un franc
 * disparu ne se retrouve pas — et cela évite d'avoir à cumuler deux rôles sur
 * les officiers du club, qui font souvent les deux.
 *
 * Le rôle vit sur `users.role` : c'est simple, rapide et suffisant pour six
 * rôles hiérarchiques. Les permissions FINES (par exemple « ce collecteur est
 * autorisé sur cette participation-là ») ne passent pas par le rôle mais par
 * une relation explicite — voir `participation_members.assigned_collector_id`
 * en phase 10.
 */
enum UserRole: string
{
    case Member = 'MEMBER';
    /** Encadre les sorties : les planifie, trace l'itinéraire, pointe les présences. */
    case RideLeader = 'RIDE_LEADER';
    case Collector = 'COLLECTOR';
    case Treasurer = 'TREASURER';
    case Admin = 'ADMIN';
    case SuperAdmin = 'SUPER_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Membre',
            self::RideLeader => 'Chef de groupe',
            self::Collector => 'Collecteur',
            self::Treasurer => 'Trésorier',
            self::Admin => 'Administrateur',
            self::SuperAdmin => 'Super administrateur',
        };
    }

    /**
     * Niveau hiérarchique. Sert aux comparaisons `atLeast()`.
     *
     * Attention : la hiérarchie ne remplace pas les Policies. Un ADMIN a un
     * niveau supérieur à un TREASURER, mais cela ne l'autorise pas
     * automatiquement à approuver sa propre dépense — cette règle-là est
     * portée par la Policy (voir docs/finance.md).
     */
    public function level(): int
    {
        return match ($this) {
            self::Member => 10,
            // 15, entre le membre et le collecteur : encadrer n'ouvre AUCUN
            // accès à l'argent, et l'espacement laisse la place à un rôle
            // intermédiaire si le club en crée un.
            self::RideLeader => 15,
            self::Collector => 20,
            self::Treasurer => 30,
            self::Admin => 40,
            self::SuperAdmin => 50,
        };
    }

    /** Ce rôle est-il au moins aussi étendu que `$other` ? */
    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    /**
     * Peut encadrer une sortie : la planifier, en tracer l'itinéraire, pointer
     * les présences.
     *
     * Volontairement SÉPARÉ de `canCollect()` : c'est cette séparation qui
     * permet de nommer un chef de groupe sans lui confier la caisse.
     */
    public function canLeadRides(): bool
    {
        return $this->atLeast(self::RideLeader);
    }

    /** Peut encaisser un paiement (sous réserve de la Policy). */
    public function canCollect(): bool
    {
        return $this->atLeast(self::Collector);
    }

    /** Accès au module financier. */
    public function canManageFinance(): bool
    {
        return $this->atLeast(self::Treasurer);
    }

    /** Administration du club. */
    public function isAdmin(): bool
    {
        return $this->atLeast(self::Admin);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    /**
     * Rôles qu'un utilisateur peut se voir attribuer à l'inscription.
     * Un compte créé publiquement est TOUJOURS un simple membre : l'élévation
     * de rôle est un acte d'administration, jamais une donnée d'entrée.
     *
     * @return list<self>
     */
    public static function assignableAtRegistration(): array
    {
        return [self::Member];
    }
}
