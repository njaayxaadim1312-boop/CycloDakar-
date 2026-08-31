<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse les comptes désactivés sur TOUTES les routes authentifiées.
 *
 * Sans ce filtre, désactiver un compte n'aurait aucun effet immédiat : le
 * jeton déjà émis resterait valable jusqu'à sa révocation manuelle. Or on
 * désactive précisément un compte quand on veut lui couper l'accès tout de
 * suite — un ancien membre, ou un collecteur écarté du bureau.
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            // Le jeton présenté est révoqué au passage : inutile de laisser
            // traîner un accès dont on vient de constater qu'il ne vaut plus.
            $request->user()->currentAccessToken()?->delete();

            return ApiResponse::error(
                message: 'Votre compte est désactivé. Contactez un responsable du club.',
                status: 403,
                code: 'ACCOUNT_DISABLED',
            );
        }

        return $next($request);
    }
}
