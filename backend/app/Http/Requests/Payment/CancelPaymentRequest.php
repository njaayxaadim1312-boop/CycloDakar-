<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Annulation d'un encaissement.
 *
 * **Le motif est obligatoire, et long de dix caractères au minimum.**
 *
 * Ce n'est pas une formalité administrative. Une annulation fait ressortir de
 * l'argent de la caisse ; c'est l'opération la plus sensible du module. En
 * assemblée générale, « erreur » n'explique rien, tandis que « saisi deux fois
 * lors de la sortie du 14 septembre » se vérifie. Le motif part au journal
 * d'audit et reste attaché au reçu.
 *
 * Dix caractères ne garantissent pas une bonne explication — rien ne le peut —
 * mais ils écartent le clic réflexe sur « ok ».
 */
final class CancelPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->route('payment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => "Le motif de l'annulation est obligatoire.",
            'reason.min' => 'Expliquez en quelques mots : ce motif sera lu en assemblée générale.',
        ];
    }
}
