<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Objectifs hebdomadaires du membre connecte.
 *
 * Chacun s'ajuste seul : personne d'autre ne fixe l'objectif d'un membre. Un
 * objectif impose par le bureau serait une pression, pas un encouragement.
 *
 * Les bornes hautes ne sont pas decoratives : 700 km ou 40 h par semaine ne
 * sont pas des objectifs, ce sont des fautes de frappe (metres saisis a la
 * place de kilometres, typiquement).
 */
final class UpdateGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->member !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // En METRES, comme toutes les distances de l'API.
            'distance_m' => ['sometimes', 'integer', 'min:0', 'max:700000'],
            'moving_time_s' => ['sometimes', 'integer', 'min:0', 'max:144000'],
            'activities' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'distance_m.max' => "700 km par semaine n'est pas un objectif : verifiez que la distance est bien en metres.",
            'moving_time_s.max' => "40 h par semaine n'est pas un objectif : verifiez que la duree est bien en secondes.",
        ];
    }
}
