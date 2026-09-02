<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Event;

/**
 * « La sortie, c'est demain. »
 *
 * Envoyée uniquement aux INSCRITS. Rappeler une sortie à quelqu'un qui ne s'y
 * est pas inscrit n'est pas un rappel, c'est une relance — et une relance non
 * demandée est exactement ce qui fait couper les notifications.
 *
 * La veille, pas le matin même : c'est le soir qu'on prépare son vélo et qu'on
 * décide de se lever.
 */
final class EventReminder extends ClubNotification
{
    public function __construct(private readonly Event $event) {}

    public function code(): string
    {
        return 'event.reminder';
    }

    public function title(object $notifiable): string
    {
        return 'Sortie demain';
    }

    public function body(object $notifiable): string
    {
        $heure = $this->event->starts_at?->format('H\\hi') ?? '';
        $lieu = $this->event->location_name;

        return "{$this->event->title} — départ à {$heure}"
            .($lieu !== null ? " depuis {$lieu}" : '').'.';
    }

    public function url(object $notifiable): string
    {
        return "/events/{$this->event->uuid}";
    }

    public function icon(): string
    {
        return 'alarm-clock';
    }
}
