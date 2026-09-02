<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * « Le classement du mois est arrêté. »
 *
 * Envoyée aux membres CLASSÉS, avec leur rang. Un classement qu'on fige sans
 * le dire ne sert à personne : c'est l'annonce qui transforme un tableau en
 * moment de club.
 *
 * Le rang est dans le message. « Le classement est disponible » obligerait à
 * ouvrir l'application pour apprendre qu'on est quinzième ; autant le dire
 * tout de suite, et laisser chacun décider s'il veut regarder.
 */
final class LeaderboardClosed extends ClubNotification
{
    public function __construct(
        private readonly string $periodLabel,
        private readonly int $rank,
        private readonly int $total,
        private readonly string $value,
    ) {}

    public function code(): string
    {
        return 'leaderboard.closed';
    }

    public function title(object $notifiable): string
    {
        return 'Classement '.$this->periodLabel;
    }

    public function body(object $notifiable): string
    {
        return "Vous finissez {$this->rank}ᵉ sur {$this->total} — {$this->value}.";
    }

    public function url(object $notifiable): string
    {
        return '/leaderboard';
    }

    public function icon(): string
    {
        return 'trophy';
    }
}
