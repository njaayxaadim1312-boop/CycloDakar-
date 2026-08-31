<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\Request;
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
| Les routes des phases suivantes sont annoncées ici en commentaire afin que
| la surface de l'API reste lisible d'un seul coup d'œil. Voir docs/api.md.
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
    | POST   /auth/register
    | POST   /auth/login              (throttle: 5/min)
    | POST   /auth/forgot-password
    | POST   /auth/reset-password
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Routes authentifiées
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {

        // Utilisateur courant. Disponible dès maintenant : c'est la sonde qui
        // permet au client de savoir si son token est encore valide.
        Route::get('/me', fn (Request $request) => [
            'data' => $request->user()?->only(['id', 'uuid', 'name', 'email', 'role']),
        ])->name('me');

        /*
        | PHASE 2  — POST /auth/logout, POST /auth/change-password
        | PHASE 3  — /members, /members/{id}, /members/search
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
    | Protégées par signature HMAC, jamais par un token utilisateur : c'est un
    | service qui appelle, pas une personne. Node n'écrit jamais directement en
    | base ; il rend compte ici.
    |
    | POST /internal/video-jobs/{uuid}/progress
    | POST /internal/video-jobs/{uuid}/complete
    | POST /internal/video-jobs/{uuid}/fail
    |
    */
});
