<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ChallengeController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventParticipationController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ParticipationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\NotificationController;
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

            // Objectifs hebdomadaires : chacun ajuste les siens.
            Route::patch('/me/goals', [MemberController::class, 'updateGoals'])
                ->name('me.goals');

            // Recherche rapide pour la collecte sur le terrain : charge utile
            // réduite, limite basse. C'est elle qui remplace la saisie
            // manuelle des noms.
            Route::get('/search', [MemberController::class, 'search'])
                ->middleware('throttle:qr-scan')
                ->name('search');

            // Resolution d'un QR scanne — PHASE 11. Declaree AVANT /{member}
            // pour que « resolve » ne soit pas pris pour un uuid. Meme limite
            // de debit que la recherche : sans elle, l'API deviendrait un
            // oracle permettant d'eprouver des jetons au hasard.
            Route::get('/resolve/{token}', [MemberController::class, 'resolveQr'])
                ->middleware('throttle:qr-scan')
                ->name('resolve');

            Route::get('/', [MemberController::class, 'index'])->name('index');
            Route::post('/', [MemberController::class, 'store'])->name('store');

            Route::get('/{member}', [MemberController::class, 'show'])->name('show');
            // POST plutôt que PATCH : les navigateurs et React Native
            // n'envoient pas de fichier en multipart sur une requête PATCH.
            Route::post('/{member}', [MemberController::class, 'update'])->name('update');
            Route::delete('/{member}', [MemberController::class, 'destroy'])->name('destroy');

            Route::post('/{member}/role', [MemberController::class, 'updateRole'])->name('role');
            /*
            | L'image de fond du compte — chacun choisit la sienne.
            |
            | POST et non PUT : c'est un televersement multipart, et PHP ne
            | remplit `$_FILES` que sur une requete POST. Le meme piege que
            | pour la photo de membre, et la meme reponse.
            */
            Route::post('/{member}/cover', [MemberController::class, 'updateCover'])
                ->name('cover.update');
            Route::delete('/{member}/cover', [MemberController::class, 'removeCover'])
                ->name('cover.destroy');

            Route::post('/{member}/rotate-qr', [MemberController::class, 'rotateQrCode'])->name('rotate-qr');

            // Image du QR, en SVG — PHASE 11.
            Route::get('/{member}/qr', [MemberController::class, 'qrCode'])->name('qr');
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

            // Trace horodatee pour le rejeu anime — PHASE 15.
            Route::get('/{activity}/replay', [ActivityController::class, 'replay'])
                ->name('replay');
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
        | Participations — PHASE 10
        |----------------------------------------------------------------------
        |
        | Campagnes de collecte. Reserve aux collecteurs et au-dessus ; creer
        | et modifier demande le role de tresorier (ParticipationPolicy). Un
        | membre verra SA dette dans son espace personnel — PHASE 12.
        |
        | Tous les montants sont des ENTIERS de FCFA, a l'entree comme a la
        | sortie. Aucun flottant ne touche l'argent (docs/finance.md, I5).
        |
        */
        Route::prefix('participations')->name('participations.')->group(function (): void {
            // Ce qu'un collecteur doit aller chercher sur le terrain, toutes
            // collectes confondues. Declaree AVANT /{participation} pour que
            // « mine » ne soit pas pris pour un uuid.
            Route::get('/mine', [ParticipationController::class, 'myAssignments'])
                ->name('mine');

            Route::get('/', [ParticipationController::class, 'index'])->name('index');
            Route::post('/', [ParticipationController::class, 'store'])->name('store');

            Route::get('/{participation}', [ParticipationController::class, 'show'])->name('show');
            Route::patch('/{participation}', [ParticipationController::class, 'update'])->name('update');
            Route::patch('/{participation}/status', [ParticipationController::class, 'updateStatus'])
                ->name('status');
            Route::delete('/{participation}', [ParticipationController::class, 'destroy'])
                ->name('destroy');

            // Affectation des membres. Sans liste : tous les membres actifs.
            Route::post('/{participation}/members', [ParticipationController::class, 'assign'])
                ->name('assign');
            Route::patch('/{participation}/members/{line}', [ParticipationController::class, 'updateLine'])
                ->name('lines.update');
            Route::delete('/{participation}/members/{line}', [ParticipationController::class, 'removeLine'])
                ->name('lines.destroy');

            // Encaissements — PHASE 12.
            Route::get('/{participation}/payments', [PaymentController::class, 'index'])
                ->name('payments.index');
            Route::post('/{participation}/payments', [PaymentController::class, 'store'])
                ->name('payments.store');
        });

        /*
        |----------------------------------------------------------------------
        | Encaissements — PHASE 12
        |----------------------------------------------------------------------
        |
        | La saisie vit sous /participations : un encaissement n'existe pas
        | sans la dette qu'il solde. Ce bloc-ci ne porte que ce qui se lit ou
        | se corrige APRES coup, et qui n'a plus besoin de sa collecte pour
        | etre designe.
        |
        | /payments/mine est la SEULE route financiere ouverte a un simple
        | membre, et elle ne montre que lui. Declaree avant /{payment} pour que
        | « mine » ne soit pas pris pour un uuid — meme piege qu'en phase 10.
        |
        | L'annulation est un POST et non un DELETE : rien n'est supprime. Une
        | contre-passation est ecrite au grand livre et le paiement reste
        | consultable, marque annule. Un verbe DELETE laisserait croire le
        | contraire a quiconque lit la liste des routes (docs/finance.md, I2).
        */
        // Ce qu'un membre doit, vu par un collecteur. Complete le scan du QR
        // Code : on reconnait quelqu'un, et on voit quoi lui demander.
        Route::get('/members/{member}/dues', [PaymentController::class, 'memberDues'])
            ->name('members.dues');

        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::get('/mine', [PaymentController::class, 'mine'])->name('mine');
            Route::get('/{payment}', [PaymentController::class, 'show'])->name('show');
            Route::post('/{payment}/cancel', [PaymentController::class, 'cancel'])->name('cancel');
        });

        /*
        |----------------------------------------------------------------------
        | Caisse — PHASE 12 (partiel)
        |----------------------------------------------------------------------
        |
        | Il n'existe AUCUNE route qui accepte un solde en entree, et il ne
        | doit jamais en exister : le solde est derive (docs/finance.md, I1).
        |
        | /finance/collections est le controle contre le risque F7 — qui a
        | encaisse combien, et combien d'annulations. Ce n'est pas une
        | statistique de confort.
        |
        | Le journal de caisse complet, les depenses et les rapports arrivent
        | en PHASE 13 et 14.
        */
        Route::prefix('finance')->name('finance.')->group(function (): void {
            Route::get('/cash', [FinanceController::class, 'cash'])->name('cash');
            Route::get('/collections', [FinanceController::class, 'collections'])
                ->name('collections');

            // PHASE 13 — tableau de bord, journal, recettes manuelles.
            Route::get('/dashboard', [FinanceController::class, 'dashboard'])
                ->name('dashboard');
            Route::get('/transactions', [FinanceController::class, 'transactions'])
                ->name('transactions');
            Route::get('/categories', [FinanceController::class, 'categories'])
                ->name('categories');

            // Une recette manuelle entre DIRECTEMENT au grand livre, sans
            // circuit de validation : de l'argent qui entre ne peut pas
            // appauvrir le club, et exiger un double regard pour enregistrer
            // un don ferait perdre la trace du don.
            Route::post('/income', [FinanceController::class, 'storeIncome'])
                ->name('income');

            // PHASE 14 — rapports. Le format demande decide de la reponse :
            // json pour l'ecran, pdf pour l'assemblee, xlsx pour retravailler,
            // csv pour importer ailleurs.
            Route::get('/reports', [FinanceController::class, 'reports'])->name('reports');
        });

        /*
        |----------------------------------------------------------------------
        | Depenses — PHASE 13
        |----------------------------------------------------------------------
        |
        | Une depense en attente n'a AUCUNE ligne au grand livre : elle
        | n'est pas de l'argent sorti, mais une intention (docs/finance.md, I4).
        | L'ecriture nait dans la meme transaction SQL que l'approbation.
        |
        | Approuver et refuser sont des POST : ce sont des ACTES, pas des mises
        | a jour de champ. Un PATCH sur `status` laisserait croire qu'on peut
        | repasser d'approuve a en attente, ce qui reviendrait a defaire une
        | ecriture — precisement ce que la regle I2 interdit.
        |
        | Les justificatifs ne sont JAMAIS servis depuis public/ : une facture
        | porte un fournisseur, un montant, parfois un numero de compte.
        */
        Route::prefix('expenses')->name('expenses.')->group(function (): void {
            Route::get('/', [ExpenseController::class, 'index'])->name('index');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');

            Route::get('/{expense}', [ExpenseController::class, 'show'])->name('show');
            Route::post('/{expense}/approve', [ExpenseController::class, 'approve'])
                ->name('approve');
            Route::post('/{expense}/reject', [ExpenseController::class, 'reject'])
                ->name('reject');

            Route::post('/{expense}/attachments', [ExpenseController::class, 'attach'])
                ->name('attachments.store');
            Route::get('/{expense}/attachments/{attachment}', [ExpenseController::class, 'attachment'])
                ->name('attachments.show');
            Route::delete('/{expense}/attachments/{attachment}', [ExpenseController::class, 'detach'])
                ->name('attachments.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Classements et defis — PHASE 16
        |----------------------------------------------------------------------
        |
        | UNE SORTIE PRIVEE NE CLASSE JAMAIS SON AUTEUR. La regle vit dans
        | `LeaderboardService` et `ChallengeService`, en un seul endroit
        | chacun, precisement pour qu'elle ne puisse pas etre oubliee dans une
        | variante. Un classement est une PUBLICATION : y faire apparaitre une
        | sortie qu'on a demande a garder privee trahirait cette demande.
        |
        | Creer un defi releve du CHEF DE GROUPE, pas du tresorier : c'est un
        | acte d'animation sportive. Participer est ouvert a tout membre, sans
        | validation — un defi qu'il faut demander a rejoindre n'est plus un
        | defi.
        |
        | Rejoindre et quitter sont des POST : ce sont des ACTES. Un DELETE sur
        | une participation laisserait croire qu'on peut effacer un badge deja
        | gagne, ce que le service refuse justement de faire.
        */
        Route::get('/leaderboard', [LeaderboardController::class, 'index'])
            ->name('leaderboard');

        Route::prefix('challenges')->name('challenges.')->group(function (): void {
            // Declaree AVANT /{challenge} pour que « mine » ne soit pas pris
            // pour un uuid — meme piege qu'en phase 10.
            Route::get('/badges', [ChallengeController::class, 'badges'])->name('badges');

            Route::get('/', [ChallengeController::class, 'index'])->name('index');
            Route::post('/', [ChallengeController::class, 'store'])->name('store');

            Route::get('/{challenge}', [ChallengeController::class, 'show'])->name('show');
            Route::patch('/{challenge}', [ChallengeController::class, 'update'])->name('update');
            Route::delete('/{challenge}', [ChallengeController::class, 'destroy'])
                ->name('destroy');

            Route::get('/{challenge}/standings', [ChallengeController::class, 'standings'])
                ->name('standings');
            Route::post('/{challenge}/join', [ChallengeController::class, 'join'])->name('join');
            Route::post('/{challenge}/leave', [ChallengeController::class, 'leave'])->name('leave');
        });

        /*
        |----------------------------------------------------------------------
        | Notifications — PHASE 17
        |----------------------------------------------------------------------
        |
        | TOUT EST STRICTEMENT PERSONNEL. Aucune de ces routes ne prend
        | d'identifiant d'utilisateur : on ne lit et on ne marque que ses
        | propres notifications, celles de la session. Un parametre `user`
        | ouvrirait la lecture des notifications d'autrui — et elles portent
        | des montants, des dettes, des decisions financieres.
        |
        | `unread-count` existe separement de la liste : c'est le seul chiffre
        | dont l'interface a besoin en permanence, et charger trente
        | notifications pour afficher une pastille serait absurde.
        |
        | Les APPAREILS : enregistrer un jeton est un POST idempotent, appele a
        | chaque demarrage du mobile — Expo peut changer le jeton apres une
        | mise a jour du systeme, et un jeton perime ne previent pas, il cesse
        | simplement de recevoir. Le retirer est aussi le reglage « ne plus me
        | notifier » : pas de jeton, pas de push. Les notifications EN BASE,
        | elles, continuent d'arriver — elles ne reveillent personne.
        */
        Route::prefix('notifications')->name('notifications.')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('/unread-count', [NotificationController::class, 'unreadCount'])
                ->name('unread-count');
            Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])
                ->name('read-all');
            Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])
                ->name('read');
        });

        Route::prefix('devices')->name('devices.')->group(function (): void {
            Route::post('/', [NotificationController::class, 'registerDevice'])->name('register');
            Route::delete('/', [NotificationController::class, 'forgetDevice'])->name('forget');
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
        | PHASE 15 — /activities/{id}/video, /video-jobs/{id}
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
