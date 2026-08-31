<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle d'accès par rôle minimal.
 *
 *     Route::middleware('role:TREASURER')  →  trésorier, administrateur, super
 *
 * Le middleware raisonne en rôle MINIMUM, pas en liste exacte : sans cela, il
 * faudrait penser à ajouter ADMIN sur chaque route financière, et l'oubli
 * finirait par arriver.
 *
 * Ce filtre est une barrière de premier niveau, pas l'autorisation elle-même :
 * les règles fines (« ce collecteur sur cette participation ») restent portées
 * par les Policies.
 */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $minimumRole): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                message: 'Authentification requise.',
                status: 401,
                code: 'UNAUTHENTICATED',
            );
        }

        if (! $user->is_active) {
            return ApiResponse::error(
                message: 'Votre compte est désactivé.',
                status: 403,
                code: 'ACCOUNT_DISABLED',
            );
        }

        $required = UserRole::tryFrom($minimumRole);

        if ($required === null) {
            // Erreur de programmation : un rôle inconnu dans une route. On
            // refuse au lieu de laisser passer — la panne doit être visible.
            return ApiResponse::error(
                message: 'Configuration de rôle invalide.',
                status: 500,
                code: 'INVALID_ROLE_CONFIG',
            );
        }

        if (! $user->hasAtLeastRole($required)) {
            return ApiResponse::error(
                message: sprintf(
                    "Cette action est réservée aux comptes « %s » et au-delà.",
                    $required->label(),
                ),
                status: 403,
                code: 'FORBIDDEN',
            );
        }

        return $next($request);
    }
}
