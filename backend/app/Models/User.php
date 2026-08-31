<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte de connexion.
 *
 * Un `User` est une identité technique : il porte les identifiants, le rôle et
 * la session. La fiche club (matricule, photo, date d'adhésion, QR Code) vit
 * dans `Member`, en relation 1-1, livré en phase 3.
 *
 * `role` n'est PAS dans les attributs assignables en masse : une élévation de
 * privilège ne doit jamais pouvoir passer par un champ de formulaire.
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // L'identifiant public est généré à la création, jamais fourni par le
        // client : c'est ce qui garantit qu'il est réellement imprévisible.
        static::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /* ---------------------------------------------------------------------- */
    /* Résolution d'identifiant                                               */
    /* ---------------------------------------------------------------------- */

    /**
     * Retrouve un compte à partir d'un identifiant de connexion, qu'il s'agisse
     * d'une adresse email ou d'un numéro de téléphone sous n'importe quelle
     * forme (« 77 123 45 67 », « +221771234567 »…).
     *
     * C'est le seul point d'entrée : connexion et mot de passe oublié
     * l'utilisent tous les deux, ce qui évite qu'ils divergent.
     */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return static::query()
                ->where('email', mb_strtolower($login))
                ->first();
        }

        $phone = PhoneNumber::normalize($login);

        return $phone === null
            ? null
            : static::query()->where('phone', $phone)->first();
    }

    /* ---------------------------------------------------------------------- */
    /* Rôles                                                                  */
    /* ---------------------------------------------------------------------- */

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, strict: true);
    }

    public function hasAtLeastRole(UserRole $role): bool
    {
        return $this->role->atLeast($role);
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    /* ---------------------------------------------------------------------- */
    /* Notifications                                                          */
    /* ---------------------------------------------------------------------- */

    /**
     * Notification de réinitialisation en français, pointant vers l'application
     * web plutôt que vers une route Laravel : le lien doit ouvrir le formulaire
     * du front, pas une page rendue par l'API.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /* ---------------------------------------------------------------------- */
    /* Filtres                                                                */
    /* ---------------------------------------------------------------------- */

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
