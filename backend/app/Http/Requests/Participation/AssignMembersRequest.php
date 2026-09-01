<?php

declare(strict_types=1);

namespace App\Http\Requests\Participation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rattachement de membres à une collecte.
 *
 * `members` absent signifie « tous les membres actifs » — le geste le plus
 * fréquent, celui d'une cotisation annuelle. On ne demande pas au bureau de
 * cocher 250 cases pour exprimer « tout le monde ».
 *
 * `amount` permet d'individualiser une part (un tarif étudiant, par exemple)
 * sans créer une seconde collecte.
 */
final class AssignMembersRequest extends FormRequest
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
            'members' => ['nullable', 'array', 'max:1000'],
            'members.*' => ['uuid', 'exists:members,uuid'],

            'amount' => ['nullable', 'integer', 'min:1', 'max:10000000'],

            'collector' => ['nullable', 'uuid', 'exists:users,uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'members.*.exists' => "L'un des membres indiqués n'existe pas.",
            'amount.integer' => 'Le montant doit être un nombre entier de francs CFA, sans décimale.',
        ];
    }
}
