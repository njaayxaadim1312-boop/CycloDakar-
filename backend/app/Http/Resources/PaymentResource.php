<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un reçu.
 *
 * Cette ressource est lue aussi bien par un trésorier que par le membre qui a
 * payé. Ce qui la distingue d'un simple miroir de la table :
 *
 *  - **le montant sort en entier de FCFA**, jamais formaté ni arrondi. La mise
 *    en forme (« 5 000 FCFA ») appartient à l'affichage, pas au transport ;
 *  - **`balance_after` n'y figure pas.** Le solde de la caisse n'est pas
 *    l'affaire de celui qui paie sa cotisation, et le glisser dans un reçu
 *    l'exposerait à tout membre par la porte de derrière ;
 *  - **une annulation est visible**, avec son motif. Cacher qu'un reçu a été
 *    annulé serait le meilleur moyen qu'un membre croie avoir payé.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'receipt_number' => $this->receipt_number,

            // Entier de FCFA. Règle I5, sans exception.
            'amount' => (int) $this->amount,

            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'reference' => $this->reference,
            'note' => $this->note,

            'paid_on' => $this->paid_on->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),

            'member' => $this->whenLoaded('member', fn () => [
                'uuid' => $this->member->uuid,
                'matricule' => $this->member->matricule,
                'full_name' => $this->member->fullName(),
            ]),

            'participation' => $this->whenLoaded('participation', fn () => [
                'uuid' => $this->participation->uuid,
                'name' => $this->participation->name,
            ]),

            'collector' => $this->whenLoaded('collector', fn () => [
                'uuid' => $this->collector->uuid,
                'name' => $this->collector->name,
            ]),

            'cancelled' => $this->isCancelled(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
        ];
    }
}
