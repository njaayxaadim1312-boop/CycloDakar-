<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Challenge;

/**
 * « Défi réussi. »
 *
 * La seule notification de ce module qui apporte une bonne nouvelle sans rien
 * demander en retour. Elle mérite d'exister pour cela : une application qui
 * n'écrit que pour réclamer de l'argent ou rappeler une échéance finit par
 * n'être ouverte qu'à contrecœur.
 */
final class ChallengeCompleted extends ClubNotification
{
    public function __construct(private readonly Challenge $challenge) {}

    public function code(): string
    {
        return 'challenge.completed';
    }

    public function title(object $notifiable): string
    {
        return 'Défi réussi';
    }

    public function body(object $notifiable): string
    {
        return "Vous avez terminé « {$this->challenge->title} ». Le badge est à vous.";
    }

    public function url(object $notifiable): string
    {
        return '/challenges';
    }

    public function icon(): string
    {
        return 'award';
    }
}
