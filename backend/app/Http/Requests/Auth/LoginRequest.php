<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion.
 *
 * `login` accepte indifféremment une adresse email ou un numéro de téléphone,
 * sous n'importe quelle mise en forme. La résolution est faite par
 * `User::findByLogin()`, seul endroit du projet qui sait interpréter un
 * identifiant de connexion.
 *
 * La limitation de débit est gérée ICI plutôt que par le middleware
 * `throttle:` : il faut pouvoir REMETTRE LE COMPTEUR À ZÉRO après une
 * connexion réussie. Sans cela, un membre qui se trompe quatre fois puis
 * réussit resterait à une tentative du blocage sans raison. Le middleware ne
 * permet pas ce geste, car sa clé interne n'est pas accessible depuis le
 * contrôleur.
 */
final class LoginRequest extends FormRequest
{
    /** Tentatives autorisées avant blocage, par identifiant et par IP. */
    private const MAX_ATTEMPTS = 5;

    /** Durée du blocage, en secondes. */
    private const DECAY_SECONDS = 60;

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
            'password' => ['required', 'string'],

            // Nom de l'appareil, affiché dans « mes sessions » et utile pour
            // révoquer un téléphone perdu sans déconnecter les autres.
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login' => is_string($this->input('login')) ? trim($this->input('login')) : null,
        ]);
    }

    /**
     * Clé de comptage : identifiant + adresse IP.
     *
     * Compter sur l'identifiant SEUL permettrait à un attaquant de verrouiller
     * le compte d'un membre à sa place. Compter sur l'IP seule laisserait
     * passer une attaque distribuée. On combine les deux.
     */
    public function throttleKey(): string
    {
        return mb_strtolower((string) $this->input('login')).'|'.$this->ip();
    }

    /** À appeler AVANT de vérifier le mot de passe. */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => [
                sprintf(
                    'Trop de tentatives. Réessayez dans %d seconde%s.',
                    $seconds,
                    $seconds > 1 ? 's' : '',
                ),
            ],
        ])->status(429);
    }

    /** À appeler après un échec d'identification. */
    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    /** À appeler après une connexion réussie. */
    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'Saisissez votre numéro de téléphone ou votre adresse email.',
            'password.required' => 'Saisissez votre mot de passe.',
        ];
    }
}
