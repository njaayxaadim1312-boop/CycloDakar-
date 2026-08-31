<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
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
            'login' => ['required', 'string', 'max:180'],
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
            'login.required' => 'Saisissez votre numéro de téléphone ou votre adresse email.',
        ];
    }
}
