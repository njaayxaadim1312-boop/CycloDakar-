<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Création d'une fiche membre depuis l'administration.
 *
 * Ni le matricule ni le jeton QR ne figurent ici : ce sont des attributs que
 * le serveur décide. Un client qui les enverrait les verrait ignorés.
 */
final class StoreMemberRequest extends FormRequest
{
    /**
     * L'autorisation est vérifiée AVANT la validation.
     *
     * Sans cela, un simple membre qui tente de créer une fiche recevrait
     * d'abord le détail des règles de validation (« le prénom doit faire au
     * moins 2 caractères ») alors qu'il n'a de toute façon pas le droit de
     * créer quoi que ce soit. On répond 403, pas 422.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Member::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['required', 'string', 'min:1', 'max:80'],

            'phone' => ['nullable', 'string', 'unique:members,phone'],
            'email' => ['nullable', 'email:rfc', 'max:180'],

            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['M', 'F', 'AUTRE'])],

            // Un membre peut être inscrit rétroactivement, mais pas au futur.
            'joined_at' => ['nullable', 'date', 'before_or_equal:today'],

            'status' => ['nullable', Rule::in(MemberStatus::values())],

            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'photo' => [
                'nullable',
                'file',
                'image',
                'max:'.config('cyclo.uploads.max_size_kb'),
                // Le type est vérifié sur le CONTENU réel du fichier, pas sur
                // son extension ni sur l'en-tête envoyé par le client.
                'mimetypes:'.implode(',', config('cyclo.uploads.image_mimes')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PhoneNumber::normalize($this->input('phone')),
            'email' => is_string($this->input('email'))
                ? (mb_strtolower(trim($this->input('email'))) ?: null)
                : null,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // On distingue « pas de numéro » (parfaitement normal) de
                // « numéro saisi mais inexploitable » (erreur de frappe).
                $raw = $this->request->get('phone');

                if ($this->input('phone') === null && is_string($raw) && trim($raw) !== '') {
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
            'first_name.required' => 'Le prénom est obligatoire.',
            'last_name.required' => 'Le nom est obligatoire.',
            'phone.unique' => 'Ce numéro est déjà associé à un autre membre.',
            'email.email' => "L'adresse email n'est pas valide.",
            'birth_date.before' => 'La date de naissance doit être dans le passé.',
            'joined_at.before_or_equal' => "La date d'adhésion ne peut pas être dans le futur.",
            'photo.image' => 'Le fichier doit être une image.',
            'photo.max' => 'La photo est trop volumineuse.',
            'photo.mimetypes' => 'Formats acceptés : JPEG, PNG ou WebP.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'prénom',
            'last_name' => 'nom',
            'phone' => 'numéro de téléphone',
            'email' => 'adresse email',
            'birth_date' => 'date de naissance',
            'joined_at' => "date d'adhésion",
            'photo' => 'photo',
        ];
    }
}
