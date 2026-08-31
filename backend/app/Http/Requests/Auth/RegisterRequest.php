<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Inscription d'un nouveau compte.
 *
 * Le rôle n'est volontairement PAS un champ acceptable : tout compte créé
 * publiquement est un simple membre. Une élévation de privilège est un acte
 * d'administration (phase 3), jamais une donnée de formulaire.
 */
final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // L'email est facultatif : beaucoup de membres n'en ont pas.
            'email' => ['nullable', 'email:rfc', 'max:180', 'unique:users,email'],

            // Le téléphone est l'identifiant principal. L'unicité est vérifiée
            // sur la forme NORMALISÉE (voir prepareForValidation), sinon
            // « 77 123 45 67 » et « 771234567 » créeraient deux comptes.
            'phone' => ['nullable', 'string', 'unique:users,phone'],

            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email'))
                ? mb_strtolower(trim($this->input('email')))
                : null,
            'phone' => PhoneNumber::normalize($this->input('phone')),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : null,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Sans identifiant, le compte serait créé mais inutilisable :
                // personne ne pourrait jamais s'y connecter.
                if ($this->input('email') === null && $this->input('phone') === null) {
                    $validator->errors()->add(
                        'phone',
                        'Indiquez au moins un numéro de téléphone ou une adresse email.'
                    );
                }

                // La normalisation renvoie null quand le numéro est
                // inexploitable : on distingue « absent » de « invalide ».
                $rawPhone = $this->request->get('phone');
                if ($this->input('phone') === null
                    && is_string($rawPhone)
                    && trim($rawPhone) !== '') {
                    $validator->errors()->add(
                        'phone',
                        'Numéro de téléphone invalide. Exemple attendu : 77 123 45 67.'
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
            'name.required' => 'Votre nom est obligatoire.',
            'name.min' => 'Le nom doit contenir au moins 2 caractères.',
            'email.email' => "L'adresse email n'est pas valide.",
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'email' => 'adresse email',
            'phone' => 'numéro de téléphone',
            'password' => 'mot de passe',
        ];
    }
}
