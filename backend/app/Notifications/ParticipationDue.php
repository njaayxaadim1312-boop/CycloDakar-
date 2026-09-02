<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ParticipationMember;

/**
 * « Il vous reste 3 000 FCFA à régler. »
 *
 * LE TON COMPTE PLUS QUE LE RAPPEL.
 *
 * On parle d'argent à quelqu'un qui n'a peut-être pas payé parce qu'il ne
 * pouvait pas. Le message dit le montant, l'échéance et à qui remettre —
 * factuel, sans reproche, sans exclamation. Un rappel qui fait honte fait
 * partir un membre bien plus sûrement qu'il ne fait payer.
 *
 * Envoyée une fois à l'approche de l'échéance, pas tous les jours. Une
 * insistance quotidienne sur une dette est du harcèlement, quelle que soit la
 * légitimité de la créance.
 */
final class ParticipationDue extends ClubNotification
{
    public function __construct(private readonly ParticipationMember $line) {}

    public function code(): string
    {
        return 'participation.due';
    }

    public function title(object $notifiable): string
    {
        return 'Cotisation à régler';
    }

    public function body(object $notifiable): string
    {
        $reste = number_format($this->line->remaining(), 0, ',', "\u{00A0}");
        $echeance = $this->line->participation->due_on?->locale('fr')->translatedFormat('j F');
        $collecteur = $this->line->collector?->name;

        $message = "{$this->line->participation->name} : il reste {$reste} FCFA";

        if ($echeance !== null) {
            $message .= ", à régler avant le {$echeance}";
        }

        if ($collecteur !== null) {
            $message .= ". Votre collecteur est {$collecteur}";
        }

        return $message.'.';
    }

    public function url(object $notifiable): string
    {
        return '/mes-cotisations';
    }

    public function icon(): string
    {
        return 'wallet';
    }
}
