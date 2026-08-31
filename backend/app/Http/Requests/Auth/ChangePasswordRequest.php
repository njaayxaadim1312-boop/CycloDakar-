<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Changement de mot de passe par un utilisateur déjà connecté.
 *
 * Le mot de passe actuel est exigé même si la session est valide : un
 * téléphone laissé déverrouillé ne doit pas suffire à verrouiller le compte
 * de son propriétaire.
 */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)->letters()->numbers(),
            ],
            // Déconnecter les autres appareils après un changement : c'est le
            // comportement attendu quand on change de mot de passe parce qu'on
            // pense avoir été compromis.
            'logout_other_devices' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Saisissez votre mot de passe actuel.',
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ];
    }
}
