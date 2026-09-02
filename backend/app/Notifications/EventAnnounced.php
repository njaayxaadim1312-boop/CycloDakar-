<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Event;

/**
 * « Une nouvelle sortie est annoncée. »
 *
 * C'est la notification la plus utile du club, et la plus délicate : elle part
 * à TOUT LE MONDE. Une sortie annoncée trois fois par semaine devient du bruit,
 * et le bruit fait désinstaller l'application.
 *
 * Elle n'est donc envoyée qu'au passage d'un brouillon à l'état annoncé, une
 * seule fois par sortie — jamais à chaque modification. Corriger l'heure de
 * départ ne doit pas réveiller cinquante personnes.
 */
final class EventAnnounced extends ClubNotification
{
    public function __construct(private readonly Event $event) {}

    public function code(): string
    {
        return 'event.announced';
    }

    public function title(object $notifiable): string
    {
        return 'Nouvelle sortie du club';
    }

    public function body(object $notifiable): string
    {
        $quand = $this->event->starts_at?->locale('fr')->translatedFormat('l j F à H\\hi') ?? '';

        return "{$this->event->title} — {$quand}.";
    }

    public function url(object $notifiable): string
    {
        return "/events/{$this->event->uuid}";
    }

    public function icon(): string
    {
        return 'calendar-days';
    }
}
