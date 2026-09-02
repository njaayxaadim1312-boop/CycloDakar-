<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refus d'une dépense.
 *
 * **Le motif est obligatoire.** Celui qui a demandé mérite de savoir pourquoi
 * on lui a dit non, et le bureau doit pouvoir expliquer en assemblée pourquoi
 * 80 000 FCFA de transport n'ont pas été engagés. Un refus sans motif se
 * transmet mal et se conteste toujours.
 *
 * L'approbation, elle, n'exige rien : elle est déjà tracée par l'identité de
 * l'approbateur et l'écriture qu'elle produit.
 */
final class DecideExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', $this->route('expense'));
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
            'reason.required' => 'Le motif du refus est obligatoire.',
            'reason.min' => 'Expliquez en quelques mots : ce motif sera lu par le demandeur.',
        ];
    }
}
