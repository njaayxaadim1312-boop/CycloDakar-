<?php

declare(strict_types=1);

namespace App\Http\Requests\Participation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modification d'UNE ligne : son montant, son collecteur, sa dispense.
 *
 * Ce que cette requête N'ACCEPTE PAS est aussi important que ce qu'elle
 * accepte : ni `paid_amount`, ni `status`. Ces deux champs sont dérivés des
 * paiements réels. Les recevoir du client reviendrait à laisser quiconque se
 * déclarer à jour de cotisation — la falsification la plus simple qu'on
 * puisse imaginer sur cette application.
 */
final class UpdateLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('participation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_amount' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'collector' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid'],
            'exempt' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
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
