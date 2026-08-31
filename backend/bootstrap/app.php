<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Limiteur par défaut de toute l'API. Les limiteurs plus stricts
        // (connexion) ou plus larges (ingestion GPS) sont définis dans
        // AppServiceProvider et appliqués route par route.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->throttleApi('api');

        $middleware->alias([
            // Rôle MINIMUM requis : role:TREASURER laisse aussi passer
            // ADMIN et SUPER_ADMIN.
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'active' => \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);

        // Les clients de l'API sont React et React Native : ils attendent
        // toujours du JSON, jamais une redirection vers une page de connexion.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tout ce qui sort de /api/* sort au format ApiResponse, sans exception.
        // Un client mobile hors ligne ne doit jamais recevoir du HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                // On respecte le statut porté par l'exception : une
                // ValidationException vaut 422 par défaut, mais la limitation
                // de débit de la connexion en lève une avec 429. Forcer 422
                // ici ferait passer un blocage pour une simple faute de saisie.
                $e instanceof ValidationException => ApiResponse::error(
                    message: $e->status === 429
                        ? 'Trop de tentatives. Patientez avant de réessayer.'
                        : 'Les données envoyées sont invalides.',
                    status: $e->status,
                    errors: $e->errors(),
                    code: $e->status === 429 ? 'TOO_MANY_ATTEMPTS' : 'VALIDATION_FAILED',
                ),

                $e instanceof AuthenticationException => ApiResponse::error(
                    message: 'Authentification requise.',
                    status: 401,
                    code: 'UNAUTHENTICATED',
                ),

                $e instanceof AuthorizationException => ApiResponse::error(
                    message: "Vous n'avez pas la permission d'effectuer cette action.",
                    status: 403,
                    code: 'FORBIDDEN',
                ),

                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    message: 'Ressource introuvable.',
                    status: 404,
                    code: 'NOT_FOUND',
                ),

                // Le middleware `throttle` lève une HttpException dont le
                // message est « Too Many Attempts. », en anglais. On le
                // remplace : l'application est en français, y compris quand
                // elle refuse.
                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    message: 'Trop de requêtes. Patientez avant de réessayer.',
                    status: 429,
                    code: 'TOO_MANY_ATTEMPTS',
                ),

                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    message: $e->getMessage() !== '' ? $e->getMessage() : 'Requête refusée.',
                    status: $e->getStatusCode(),
                ),

                default => null, // laisse Laravel gérer (500, avec trace en local)
            };
        });
    })->create();
