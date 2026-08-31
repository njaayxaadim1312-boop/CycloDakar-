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
 * Mise à jour d'une fiche membre.
 *
 * Le `status` n'est accepté que d'un administrateur : sinon un membre suspendu
 * se réactiverait lui-même en modifiant son propre profil. Le contrôle est
 * fait ici en plus de la Policy — la validation refuse le champ, la Policy
 * refuse l'action.
 */
final class UpdateMemberRequest extends FormRequest
{
    /** Autorisation vérifiée AVANT la validation : 403 plutôt que 422. */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('member')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Member $member */
        $member = $this->route('member');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                Rule::unique('members', 'phone')->ignore($member->id),
            ],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:180'],

            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(['M', 'F', 'AUTRE'])],
            'joined_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],

            'status' => ['sometimes', Rule::in(MemberStatus::values())],

            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'photo' => [
                'sometimes',
                'nullable',
                'file',
                'image',
                'max:'.config('cyclo.uploads.max_size_kb'),
                'mimetypes:'.implode(',', config('cyclo.uploads.image_mimes')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }

        if ($this->has('email')) {
            $this->merge([
                'email' => is_string($this->input('email'))
                    ? (mb_strtolower(trim($this->input('email'))) ?: null)
                    : null,
            ]);
        }
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $raw = $this->request->get('phone');

                if ($this->has('phone')
                    && $this->input('phone') === null
                    && is_string($raw)
                    && trim($raw) !== '') {
                    $validator->errors()->add(
                        'phone',
                        'Numéro de téléphone invalide. Exemple attendu : 77 123 45 67.'
                    );
                }

                // Seconde barrière : la validation refuse le champ, la Policy
                // refuse l'action. Les deux, parce qu'un oubli d'appel à la
                // Policy dans un futur contrôleur ne doit pas ouvrir la porte.
                if ($this->has('status')
                    && $this->user() !== null
                    && ! $this->user()->role->isAdmin()) {
                    $validator->errors()->add(
                        'status',
                        'Seul un responsable du club peut modifier le statut d\'un membre.'
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
            'first_name.required' => 'Le prénom ne peut pas être vide.',
            'last_name.required' => 'Le nom ne peut pas être vide.',
            'phone.unique' => 'Ce numéro est déjà associé à un autre membre.',
            'photo.mimetypes' => 'Formats acceptés : JPEG, PNG ou WebP.',
        ];
    }
}
