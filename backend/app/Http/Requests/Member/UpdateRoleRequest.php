<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Attribution d'un rôle au compte d'un membre.
 *
 * C'est l'opération la plus sensible du module : elle donne accès à la caisse.
 * Deux règles y sont attachées, en plus de la Policy :
 *
 *  - un ADMIN ne peut pas nommer un SUPER_ADMIN (seul un super administrateur
 *    peut créer son égal) ;
 *  - un motif est demandé pour le journal d'audit, parce qu'un changement de
 *    rôle doit pouvoir s'expliquer six mois plus tard en assemblée générale.
 */
final class UpdateRoleRequest extends FormRequest
{
    /**
     * Autorisation vérifiée AVANT la validation.
     *
     * Important ici : quelqu'un qui n'a pas le droit de distribuer les rôles
     * ne doit pas apprendre, par le message d'erreur, quels rôles existent ni
     * jusqu'où il pourrait aller.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('updateRole', $this->route('member')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(UserRole::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();
                $target = UserRole::tryFrom((string) $this->input('role'));

                if ($actor === null || $target === null) {
                    return;
                }

                // On ne nomme pas plus haut que soi. Sans cette règle, un
                // ADMIN pourrait se créer un complice SUPER_ADMIN, puis se
                // faire promouvoir par lui.
                if ($target->level() > $actor->role->level()) {
                    $validator->errors()->add(
                        'role',
                        sprintf(
                            'Vous ne pouvez pas attribuer un rôle supérieur au vôtre (« %s »).',
                            $actor->role->label(),
                        ),
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => "Ce rôle n'existe pas.",
        ];
    }
}
