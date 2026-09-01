<?php

declare(strict_types=1);

namespace App\Http\Requests\Participation;

use App\Enums\ParticipationStatus;
use App\Models\Participation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une campagne de collecte.
 *
 * `expected_amount` est validé comme **entier**, jamais comme nombre : accepter
 * `5000.50` ferait entrer un centime dans une monnaie qui n'en a pas, et
 * MySQL l'arrondirait en silence. Un refus explicite vaut mieux qu'un arrondi
 * invisible sur de l'argent.
 */
final class StoreParticipationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Participation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],

            // En FCFA, entier. 10 millions : au-delà, c'est une saisie fautive
            // (un montant total pris pour un montant unitaire, typiquement).
            'expected_amount' => ['required', 'integer', 'min:1', 'max:10000000'],

            'starts_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:starts_on'],

            'event_id' => ['nullable', 'uuid', 'exists:events,uuid'],

            'status' => ['nullable', Rule::in([
                ParticipationStatus::Draft->value,
                ParticipationStatus::Open->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expected_amount.integer' => 'Le montant doit être un nombre entier de francs CFA, sans décimale.',
            'expected_amount.min' => 'Une collecte à zéro franc n\'a pas de sens.',
            'expected_amount.max' => 'Ce montant paraît être un total, pas la part de chaque membre.',
            'due_on.after_or_equal' => 'L\'échéance ne peut pas précéder le début de la collecte.',
        ];
    }
}
