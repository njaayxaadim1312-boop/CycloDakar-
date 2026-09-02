<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saisie d'une dépense.
 *
 * Ce qui n'est PAS ici, et ne le sera jamais : `status`, `approved_by`,
 * `financial_transaction_id`, le moindre solde. Une dépense naît toujours
 * `PENDING` ; c'est le serveur qui décide si elle passe seule sous le seuil
 * (règle I3 de `docs/finance.md`).
 *
 * Le montant est un ENTIER de FCFA. `integer` refuse « 40000.50 » plutôt que
 * de l'arrondir en silence : aucun flottant ne touche l'argent (règle I5).
 */
final class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expense::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'exists:transaction_categories,code'],

            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'label' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],

            'supplier' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],

            // Rattachement facultatif à une sortie : c'est ce qui permet de
            // calculer le résultat d'un événement.
            'event' => ['nullable', 'uuid', 'exists:events,uuid'],

            // Date métier. Le futur est refusé : on n'enregistre pas une
            // dépense qui n'a pas encore eu lieu — ce serait un engagement,
            // et un engagement se saisit le jour où il devient une dépense.
            'spent_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.exists' => "Ce poste de dépense n'existe pas.",
            'amount.integer' => 'Le montant doit être un nombre entier de francs CFA, sans décimale.',
            'label.required' => 'Indiquez à quoi correspond cette dépense.',
            'spent_on.before_or_equal' => "Une dépense ne s'enregistre pas dans le futur.",
        ];
    }
}
