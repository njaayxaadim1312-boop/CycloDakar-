<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Payment;

/**
 * « Votre paiement de 5 000 FCFA est enregistré. »
 *
 * REPORTÉE DEPUIS LA PHASE 12, ET C'ÉTAIT LA BONNE DÉCISION.
 *
 * Le module d'encaissement était prêt ; le canal ne l'était pas. Plutôt que
 * de simuler un envoi qui n'aurait pas eu lieu, le reçu a été rendu
 * consultable dans « Mes cotisations » et la notification marquée pour cette
 * phase. La voici.
 *
 * ELLE PORTE LE NUMÉRO DE REÇU, ET C'EST L'ESSENTIEL. Un membre qui reçoit
 * « paiement enregistré » sans référence ne peut rien vérifier ; avec
 * `RC-2026-000042`, il tient la pièce qui fait foi en cas de contestation.
 */
final class PaymentReceived extends ClubNotification
{
    public function __construct(private readonly Payment $payment) {}

    public function code(): string
    {
        return 'payment.received';
    }

    public function title(object $notifiable): string
    {
        return 'Paiement enregistré';
    }

    public function body(object $notifiable): string
    {
        $montant = number_format($this->payment->amount, 0, ',', "\u{00A0}");

        return "{$montant} FCFA reçus pour « {$this->payment->participation->name} ». "
            ."Reçu {$this->payment->receipt_number}.";
    }

    public function url(object $notifiable): string
    {
        return '/mes-cotisations';
    }

    public function icon(): string
    {
        return 'receipt';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(object $notifiable): array
    {
        return [
            'receipt_number' => $this->payment->receipt_number,
            'amount' => (int) $this->payment->amount,
        ];
    }
}
