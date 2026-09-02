<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\MemberService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authentification par jeton (Laravel Sanctum).
 *
 * Le web et le mobile utilisent EXACTEMENT ces routes : un seul flux, un seul
 * endroit à corriger. Le jeton est renvoyé en clair une seule fois, à la
 * connexion ; il est ensuite stocké par le client (localStorage côté web,
 * trousseau sécurisé côté mobile).
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly MemberService $members,
    ) {}

    /**
     * Inscription.
     *
     * Crée le compte de connexion ET sa fiche club dans la même transaction :
     * dans ce club, s'inscrire c'est devenir membre. Un compte sans fiche
     * n'aurait ni matricule ni QR Code, et resterait invisible des collectes.
     *
     * Le compte créé est TOUJOURS un simple membre. Le rôle n'est pas lu dans
     * la requête : il est imposé ici.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = new User;
            $user->fill($request->safe()->only(['name', 'email', 'phone', 'password']));
            $user->role = UserRole::Member;
            $user->is_active = true;
            $user->save();

            $this->members->createForUser($user);

            return $user;
        });

        $token = $this->issueToken($user, $request->input('device_name'));

        return ApiResponse::created([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Connexion par email ou téléphone.
     *
     * Trois points de sécurité :
     *  - le même message d'erreur pour « compte inexistant » et « mauvais mot
     *    de passe » : sinon l'API permettrait d'énumérer les comptes du club ;
     *  - le hachage est vérifié même quand le compte n'existe pas, pour que le
     *    temps de réponse ne trahisse pas son existence ;
     *  - un compte désactivé est refusé, avec un message distinct — là,
     *    l'information est légitime et utile au membre.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::findByLogin($request->string('login')->toString());

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            // Comparaison factice contre un hachage réel : sans elle, une
            // réponse instantanée signalerait « ce compte n'existe pas ».
            if ($user === null) {
                Hash::check('mot-de-passe-factice', '$2y$12$'.str_repeat('a', 53));
            }

            $request->hitRateLimiter();

            return ApiResponse::error(
                message: 'Identifiants incorrects.',
                status: 422,
                errors: ['login' => ['Identifiant ou mot de passe incorrect.']],
                code: 'INVALID_CREDENTIALS',
            );
        }

        if (! $user->is_active) {
            // Pas de comptage ici : le mot de passe était bon. Bloquer un
            // membre désactivé qui insiste n'apporte rien.
            return ApiResponse::error(
                message: 'Votre compte est désactivé. Contactez un responsable du club.',
                status: 403,
                code: 'ACCOUNT_DISABLED',
            );
        }

        // Connexion réussie : on libère le compteur pour que le membre ne
        // reste pas bloqué par ses propres essais infructueux.
        $request->clearRateLimiter();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $token = $this->issueToken($user, $request->input('device_name'));

        return ApiResponse::ok([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /** Utilisateur courant. Sert aussi de sonde de validité du jeton. */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($request->user()));
    }

    /**
     * Déconnexion.
     *
     * Par défaut, seul le jeton utilisé est révoqué : se déconnecter du web ne
     * doit pas déconnecter le téléphone en pleine sortie GPS.
     * `all_devices=true` révoque tout — c'est le geste à faire en cas de perte
     * d'un téléphone.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->boolean('all_devices')) {
            $user->tokens()->delete();

            return ApiResponse::ok(['message' => 'Déconnecté de tous les appareils.']);
        }

        $request->user()->currentAccessToken()->delete();

        return ApiResponse::ok(['message' => 'Déconnexion réussie.']);
    }

    /**
     * Changement de mot de passe par un utilisateur connecté.
     *
     * Le mot de passe actuel est exigé (voir ChangePasswordRequest).
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->id;

        DB::transaction(function () use ($request, $user, $currentTokenId): void {
            $user->forceFill([
                'password' => $request->string('password')->toString(),
            ])->save();

            if ($request->boolean('logout_other_devices')) {
                // On garde la session courante : l'utilisateur vient de
                // s'authentifier en donnant son mot de passe actuel.
                $user->tokens()->whereKeyNot($currentTokenId)->delete();
            }
        });

        return ApiResponse::ok(['message' => 'Mot de passe modifié.']);
    }

    /* ---------------------------------------------------------------------- */

    private function issueToken(User $user, ?string $deviceName): string
    {
        $name = $deviceName !== null && trim($deviceName) !== ''
            ? mb_substr(trim($deviceName), 0, 120)
            : 'Appareil inconnu';

        // Les capacités du jeton reflètent le rôle. Sanctum les vérifie via
        // `tokenCan()` ; c'est une seconde barrière, en plus des Policies.
        $abilities = match (true) {
            $user->role->isAdmin() => ['*'],
            $user->role->canManageFinance() => ['finance:*', 'collect:*', 'rides:*', 'member:*'],
            $user->role->canCollect() => ['collect:*', 'rides:*', 'member:*'],
            // Le chef de groupe encadre les sorties et n'approche pas l'argent :
            // son jeton ne porte AUCUNE capacite de collecte.
            $user->role->canLeadRides() => ['rides:*', 'member:*'],
            default => ['member:*'],
        };

        return $user->createToken($name, $abilities)->plainTextToken;
    }
}
