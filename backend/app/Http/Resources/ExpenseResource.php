<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une dépense.
 *
 * `is_commitment` dit explicitement si ce montant est **engagé mais pas
 * encore sorti**. Sans ce champ, un client afficherait 60 000 FCFA de dépenses
 * dont une partie n'a jamais quitté la caisse — et le solde n'aurait plus l'air
 * de correspondre à la somme des lignes.
 *
 * Les justificatifs ne portent PAS de chemin de fichier : seulement une URL de
 * téléchargement construite sur l'uuid. Exposer l'arborescence du stockage la
 * ferait fuiter dans l'API, et en changer casserait des clients.
 *
 * @mixin Expense
 */
final class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'uuid' => $this->uuid,

            // Entier de FCFA. Règle I5, sans exception.
            'amount' => (int) $this->amount,

            'label' => $this->label,
            'description' => $this->description,
            'supplier' => $this->supplier,
            'reference' => $this->reference,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'moved_money' => $this->status->movedMoney(),
            'is_commitment' => $this->status->isCommitment(),

            'category' => $this->whenLoaded('category', fn () => [
                'code' => $this->category->code,
                'name' => $this->category->name,
            ]),

            'event' => $this->whenLoaded('event', fn () => $this->event === null ? null : [
                'uuid' => $this->event->uuid,
                'title' => $this->event->title,
            ]),

            'spent_on' => $this->spent_on->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),

            'requested_by' => $this->whenLoaded('requester', fn () => [
                'uuid' => $this->requester->uuid,
                'name' => $this->requester->name,
            ]),

            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver === null ? null : [
                'uuid' => $this->approver->uuid,
                'name' => $this->approver->name,
            ]),

            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,

            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(
                fn ($piece) => [
                    'uuid' => $piece->uuid,
                    'name' => $piece->original_name,
                    'mime_type' => $piece->mime_type,
                    'size_bytes' => (int) $piece->size_bytes,
                    'is_image' => $piece->isImage(),
                    // Route contrôlée : le fichier n'est jamais servi depuis
                    // `public/`, une facture n'a rien à y faire.
                    'url' => route('api.v1.expenses.attachments.show', [
                        'expense' => $this->uuid,
                        'attachment' => $piece->uuid,
                    ]),
                ],
            )),

            // Décidées par le serveur : le client s'en sert pour MASQUER, pas
            // pour autoriser. Un approbateur ne peut pas approuver sa propre
            // dépense, et cette règle ne se devine pas côté client.
            'permissions' => $viewer === null ? null : [
                'approve' => $viewer->can('approve', $this->resource),
                'reject' => $viewer->can('reject', $this->resource),
                'update' => $viewer->can('update', $this->resource),
            ],
        ];
    }
}
