<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Cyclo Dakar — v1
|--------------------------------------------------------------------------
|
| Le web et le mobile consomment STRICTEMENT ces mêmes routes : il n'existe
| pas d'API « web » et d'API « mobile ». Toute route est préfixée par la
| version pour permettre une v2 sans casser les applications déjà installées
| sur les téléphones des membres (que l'on ne maîtrise pas).
|
| Les routes des phases suivantes sont annoncées en commentaire afin que la
| surface de l'API reste lisible d'un seul coup d'œil. Voir docs/api.md.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | Routes publiques
    |--------------------------------------------------------------------------
    */

    // État du serveur, de la base et du stockage.
    Route::get('/health', HealthController::class)->name('health');

    // Paramètres métier partagés (sports, seuils GPS, moyens de paiement...).
    Route::get('/config', ConfigController::class)->name('config');

    /*
    |--------------------------------------------------------------------------
    | Authentification — PHASE 2
    |--------------------------------------------------------------------------
    |
    | Limites de débit spécifiques : la connexion et la demande de
    | réinitialisation sont les deux points qu'on attaque en premier.
    | `login` compte par identifiant ET par IP (voir AppServiceProvider) pour
    | qu'un attaquant ne puisse pas verrouiller le compte d'un membre.
    |
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:6,1')
            ->name('register');

        // La limitation de debit de la connexion est portee par
        // LoginRequest, qui peut remettre le compteur a zero apres une
        // connexion reussie -- ce que le middleware throttle ne permet pas.
        Route::post('/login', [AuthController::class, 'login'])->name('login');

        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:password-reset')
            ->name('forgot-password');

        Route::post('/reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:password-reset-confirm')
            ->name('reset-password');
    });

    /*
    |--------------------------------------------------------------------------
    | Routes authentifiées
    |--------------------------------------------------------------------------
    |
    | `active` révoque immédiatement l'accès d'un compte désactivé, sans
    | attendre l'expiration de son jeton.
    |
    */
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {

        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
            ->name('auth.change-password');

        /*
        |----------------------------------------------------------------------
        | Exemples de routes protégées par rôle (à décommenter avec le module)
        |----------------------------------------------------------------------
        |
        | Route::middleware('role:COLLECTOR')->group(...)   PHASE 12
        | Route::middleware('role:TREASURER')->group(...)   PHASE 13
        | Route::middleware('role:ADMIN')->group(...)       PHASE 19
        |
        | PHASE 3  — /members, /members/{uuid}, /members/search
        | PHASE 6  — /activities, /activities/{uuid}/points, /finalize
        | PHASE 9  — /events, /events/{id}/join
        | PHASE 10 — /participations
        | PHASE 11 — /members/resolve/{qr_token}
        | PHASE 12 — /participations/{id}/payments
        | PHASE 13 — /finance/dashboard, /finance/transactions, /expenses
        | PHASE 14 — /finance/reports
        | PHASE 15 — /activities/{id}/video, /video-jobs/{id}
        | PHASE 16 — /challenges, /leaderboard
        | PHASE 17 — /notifications
        */
    });

    /*
    |--------------------------------------------------------------------------
    | Routes internes (service Node.js) — PHASE 15
    |--------------------------------------------------------------------------
    |
    | Protégées par signature HMAC, jamais par un jeton utilisateur : c'est un
    | service qui appelle, pas une personne. Node n'écrit jamais directement en
    | base ; il rend compte ici.
    |
    | POST /internal/video-jobs/{uuid}/progress
    | POST /internal/video-jobs/{uuid}/complete
    | POST /internal/video-jobs/{uuid}/fail
    |
    */
});
