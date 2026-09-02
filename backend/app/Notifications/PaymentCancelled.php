<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Payment;

/**
 * « Votre paiement a été annulé. »
 *
 * CELLE-CI N'EST PAS OPTIONNELLE.
 *
 * Un membre à qui l'on a remis un reçu croit être à jour. Si le paiement est
 * annulé sans qu'il le sache, il découvrira sa dette au pire moment — devant
 * un collecteur, en public, en croyant avoir déjà payé. Le prévenir, avec le
 * motif, est le minimum qu'on lui doit.
 *
 * Le motif est repris tel quel : c'est celui que le trésorier a écrit en
 * sachant qu'il serait lu.
 */
final class PaymentCancelled extends ClubNotification
{
    public function __construct(private readonly Payment $payment) {}

    public function code(): string
    {
        return 'payment.cancelled';
    }

    public function title(object $notifiable): string
    {
        return 'Paiement annulé';
    }

    public function body(object $notifiable): string
    {
        $montant = number_format($this->payment->amount, 0, ',', "\u{00A0}");
        $motif = $this->payment->cancellation_reason ?? 'sans motif précisé';

        return "Le reçu {$this->payment->receipt_number} ({$montant} FCFA) a été annulé : {$motif}";
    }

    public function url(object $notifiable): string
    {
        return '/mes-cotisations';
    }

    public function icon(): string
    {
        return 'alert-triangle';
    }
}
