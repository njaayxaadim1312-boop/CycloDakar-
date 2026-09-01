<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventParticipationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\StatsController;
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
        | Tableau de bord — PHASE 4
        |----------------------------------------------------------------------
        |
        | Ne renvoie que ce qui est reellement mesurable : les modules non
        | livres repondent `available: false` avec leur phase, jamais un zero
        | qui laisserait croire a une valeur reelle.
        |
        */
        Route::get('/stats/dashboard', [StatsController::class, 'dashboard'])
            ->name('stats.dashboard');

        // Cumuls et records personnels — PHASE 8.
        Route::get('/stats/me', [StatsController::class, 'me'])->name('stats.me');

        /*
        |----------------------------------------------------------------------
        | Membres — PHASE 3
        |----------------------------------------------------------------------
        |
        | Les fiches sont adressées par leur `uuid`, jamais par l'identifiant
        | auto-incrémenté : sinon on pourrait énumérer les fiches et connaître
        | l'effectif du club. Les autorisations sont portées par MemberPolicy.
        |
        */
        Route::prefix('members')->name('members.')->group(function (): void {
            // Fiche club du compte connecté. Déclarée AVANT /{member} pour que
            // « me » ne soit pas pris pour un uuid.
            Route::get('/me', [MemberController::class, 'me'])->name('me');

            // Recherche rapide pour la collecte sur le terrain : charge utile
            // réduite, limite basse. C'est elle qui remplace la saisie
            // manuelle des noms.
            Route::get('/search', [MemberController::class, 'search'])
                ->middleware('throttle:qr-scan')
                ->name('search');

            Route::get('/', [MemberController::class, 'index'])->name('index');
            Route::post('/', [MemberController::class, 'store'])->name('store');

            Route::get('/{member}', [MemberController::class, 'show'])->name('show');
            // POST plutôt que PATCH : les navigateurs et React Native
            // n'envoient pas de fichier en multipart sur une requête PATCH.
            Route::post('/{member}', [MemberController::class, 'update'])->name('update');
            Route::delete('/{member}', [MemberController::class, 'destroy'])->name('destroy');

            Route::post('/{member}/role', [MemberController::class, 'updateRole'])->name('role');
            Route::post('/{member}/rotate-qr', [MemberController::class, 'rotateQrCode'])->name('rotate-qr');
        });

        /*
        |----------------------------------------------------------------------
        | Activités et GPS — PHASE 6
        |----------------------------------------------------------------------
        |
        | Tout est concu pour un reseau qui tombe : chaque etape peut etre
        | rejouee sans consequence. L'uuid est genere par le CLIENT et sert de
        | cle d'idempotence. Voir docs/gps.md §12.
        |
        */
        Route::prefix('activities')->name('activities.')->group(function (): void {
            Route::get('/', [ActivityController::class, 'index'])->name('index');
            Route::post('/', [ActivityController::class, 'store'])->name('store');

            Route::get('/{activity}', [ActivityController::class, 'show'])->name('show');
            Route::patch('/{activity}', [ActivityController::class, 'update'])->name('update');
            Route::delete('/{activity}', [ActivityController::class, 'destroy'])->name('destroy');

            // Limite large : un membre rentrant d'une sortie de 3 h peut
            // envoyer des dizaines de lots d'affilee pour rattraper une
            // coupure. La brider ici ferait echouer la synchronisation.
            Route::post('/{activity}/points', [ActivityController::class, 'storePoints'])
                ->middleware('throttle:gps-sync')
                ->name('points');

            Route::post('/{activity}/finalize', [ActivityController::class, 'finalize'])
                ->middleware('throttle:gps-sync')
                ->name('finalize');
        });

        /*
        |----------------------------------------------------------------------
        | Evenements — PHASE 9
        |----------------------------------------------------------------------
        |
        | Les sorties officielles du club. Trois cercles de droits, portes par
        | EventPolicy : tout membre voit et s'inscrit, un collecteur cree et
        | pointe, seul l'auteur ou un administrateur modifie et annule.
        |
        | Le changement d'etat a sa PROPRE route : publier, demarrer ou annuler
        | sont des actes soumis a des transitions, pas des champs que l'on
        | modifie au passage.
        |
        */
        Route::prefix('events')->name('events.')->group(function (): void {
            Route::get('/', [EventController::class, 'index'])->name('index');
            Route::post('/', [EventController::class, 'store'])->name('store');

            Route::get('/{event}', [EventController::class, 'show'])->name('show');
            Route::patch('/{event}', [EventController::class, 'update'])->name('update');
            Route::patch('/{event}/status', [EventController::class, 'updateStatus'])
                ->name('status');
            Route::delete('/{event}', [EventController::class, 'destroy'])->name('destroy');

            // Inscription et desistement : le membre vient de la SESSION, on
            // ne s'inscrit jamais a la place d'un autre.
            Route::post('/{event}/register', [EventParticipationController::class, 'register'])
                ->name('register');
            Route::delete('/{event}/register', [EventParticipationController::class, 'cancel'])
                ->name('unregister');

            Route::get('/{event}/participants', [EventParticipationController::class, 'index'])
                ->name('participants');

            // Pointage : reserve aux collecteurs et au-dessus (EventPolicy).
            Route::post('/{event}/attendance', [EventParticipationController::class, 'attendance'])
                ->name('attendance');
        });

        /*
        |----------------------------------------------------------------------
        | Modules à venir
        |----------------------------------------------------------------------
        |
        | Route::middleware('role:COLLECTOR')->group(...)   PHASE 12
        | Route::middleware('role:TREASURER')->group(...)   PHASE 13
        | Route::middleware('role:ADMIN')->group(...)       PHASE 19
        |
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
