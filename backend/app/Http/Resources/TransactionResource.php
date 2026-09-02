<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FinancialTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne du journal de caisse.
 *
 * `balance_after` est **lu, jamais recalculé à l'affichage**. C'est ce qui
 * garantit qu'un journal imprimé pour une assemblée générale se réimprime
 * identique six mois plus tard, même si une écriture antérieure a été
 * contre-passée entre-temps.
 *
 * Attention à la lecture : ce solde suit l'ordre d'ENREGISTREMENT, pas la date
 * métier. Trié par `occurred_on`, il n'est donc pas monotone dès qu'une saisie
 * a été antidatée — c'est la réalité d'une caisse tenue à la main, et
 * `docs/finance.md` §2 l'explique.
 *
 * @mixin FinancialTransaction
 */
final class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,

            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),

            // Entiers de FCFA, et le montant est TOUJOURS positif : le signe
            // est porté par `direction`, jamais glissé dans le nombre.
            'amount' => (int) $this->amount,
            'balance_after' => (int) $this->balance_after,

            'label' => $this->label,

            'source_type' => $this->source_type->value,
            'source_label' => $this->source_type->label(),

            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'code' => $this->category->code,
                'name' => $this->category->name,
            ]),

            'event' => $this->whenLoaded('event', fn () => $this->event === null ? null : [
                'uuid' => $this->event->uuid,
                'title' => $this->event->title,
            ]),

            // Une contre-passation se voit : cacher qu'une écriture en annule
            // une autre rendrait le journal incompréhensible.
            'reverses' => $this->reverses_transaction_id === null ? null : $this->whenLoaded(
                'reverses',
                fn () => ['uuid' => $this->reverses->uuid, 'label' => $this->reverses->label],
            ),
            'reason' => $this->reason,

            'occurred_on' => $this->occurred_on->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),

            'author' => $this->whenLoaded('author', fn () => [
                'uuid' => $this->author->uuid,
                'name' => $this->author->name,
            ]),
        ];
    }
}
