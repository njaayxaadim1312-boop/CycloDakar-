<?php

declare(strict_types=1);

namespace App\Http\Requests\Participation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modification d'une collecte.
 *
 * Le statut ne se change pas ici : ouvrir ou clôturer sont des ACTES soumis à
 * des transitions, et non des champs que l'on modifie au passage. Ils ont
 * leur propre route.
 *
 * Changer `expected_amount` ne réécrit PAS les lignes déjà créées — le montant
 * y est figé à l'affectation. Le nouveau tarif ne vaut que pour les membres
 * rattachés ensuite.
 */
final class UpdateParticipationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('participation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'expected_amount' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000000'],
            'starts_on' => ['sometimes', 'required', 'date'],
            'due_on' => ['sometimes', 'required', 'date', 'after_or_equal:starts_on'],
            'event_id' => ['nullable', 'uuid', 'exists:events,uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expected_amount.integer' => 'Le montant doit être un nombre entier de francs CFA, sans décimale.',
        ];
    }
}
