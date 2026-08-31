<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'login' => ['required', 'string', 'max:180'],
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
            'login' => is_string($this->input('login')) ? trim($this->input('login')) : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Le lien de réinitialisation est incomplet.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ];
    }
}
