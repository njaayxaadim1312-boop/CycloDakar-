<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Vérification de l'état du backend.
 *
 * Sert à trois choses :
 *  - au développeur, pour confirmer en une requête que XAMPP, MySQL et Laravel
 *    se parlent correctement ;
 *  - au web et au mobile, pour afficher un écran « serveur indisponible »
 *    plutôt qu'une erreur brute ;
 *  - à la supervision en production.
 *
 * DEUX NIVEAUX DE PANNE, ET C'EST VOLONTAIRE.
 *
 * La base ou le stockage en échec, c'est une application qui ne peut rien
 * servir : elle répond 503, et la supervision doit réveiller quelqu'un.
 *
 * La file d'attente ou le planificateur à l'arrêt, c'est autre chose : les
 * écrans fonctionnent, les encaissements passent, mais les notifications ne
 * partent plus et le contrôle nocturne du solde ne tourne plus. Répondre 503
 * ferait redémarrer un serveur qui va bien ; ne rien dire laisserait la panne
 * courir des semaines. On répond donc 200 avec `status: "degraded"`, et la
 * supervision surveille CE champ, pas seulement le code HTTP.
 *
 * C'est la même leçon que le journal d'audit de la phase 19 : une sonde qui
 * dit « tout va bien » alors qu'un rouage est mort est pire qu'une absence de
 * sonde, parce qu'on cesse de regarder.
 *
 * Route publique : elle ne divulgue aucune donnée sensible.
 */
final class HealthController extends Controller
{
    /**
     * Clé du battement de cœur écrit chaque minute par le planificateur.
     *
     * Voir `routes/console.php`. Un planificateur arrêté ne peut pas signaler
     * lui-même son arrêt : c'est l'ABSENCE de battement récent qui le trahit.
     */
    public const HEARTBEAT = 'cyclo:scheduler:heartbeat';

    /**
     * Au-delà, on considère le rouage arrêté.
     *
     * Cinq minutes : le planificateur bat chaque minute, et Windows comme cron
     * peuvent prendre du retard sous charge. Une minute donnerait de fausses
     * alertes, une heure laisserait passer une nuit entière sans contrôle du
     * solde.
     */
    private const TOLERANCE_S = 300;

    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $storage = $this->checkStorage();
        $queue = $this->checkQueue();
        $scheduler = $this->checkScheduler();

        // Ce qui empêche de servir…
        $vital = $database['ok'] && $storage['ok'];
        // …et ce qui n'empêche que de bien servir.
        $complet = $vital && $queue['ok'] && $scheduler['ok'];

        return ApiResponse::ok([
            'application' => config('cyclo.club.name'),
            'api_version' => 'v1',
            'environment' => app()->environment(),
            'laravel' => app()->version(),
            // Version tronquée hors local : le numéro de correctif exact
            // désigne à un visiteur la liste précise des failles connues qui
            // s'appliquent. Le majeur.mineur suffit à diagnostiquer.
            'php' => app()->environment('local')
                ? PHP_VERSION
                : PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
            'status' => match (true) {
                ! $vital => 'unhealthy',
                ! $complet => 'degraded',
                default => 'healthy',
            },
            'checks' => [
                'database' => $database,
                'storage' => $storage,
                'queue' => $queue,
                'scheduler' => $scheduler,
            ],
        ], status: $vital ? 200 : 503);
    }

    /**
     * @return array{ok: bool, driver: string, message: string, latency_ms?: float}
     */
    private function checkDatabase(): array
    {
        $driver = (string) config('database.default');

        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            DB::select('select 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'ok' => true,
                'driver' => $driver,
                'message' => 'Connexion établie ('.DB::connection()->getDatabaseName().')',
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            // Le message d'exception peut contenir des identifiants : on ne le
            // renvoie qu'en local.
            return [
                'ok' => false,
                'driver' => $driver,
                'message' => app()->environment('local')
                    ? $e->getMessage()
                    : 'Base de données injoignable.',
            ];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkStorage(): array
    {
        $writable = is_writable(storage_path('app'))
            && is_writable(storage_path('logs'))
            && is_writable(storage_path('framework'));

        return [
            'ok' => $writable,
            'message' => $writable
                ? 'Répertoires de stockage accessibles en écriture.'
                : 'storage/ n\'est pas accessible en écriture.',
        ];
    }

    /**
     * La file d'attente avance-t-elle ?
     *
     * ON NE TESTE PAS SI LA FILE EST VIDE — une file pleine va très bien tant
     * qu'elle se vide. Ce qui trahit un ouvrier mort, c'est un travail DÉJÀ
     * EXIGIBLE qui attend depuis longtemps : personne ne l'a pris.
     *
     * Sans cela la panne est parfaitement silencieuse. Les notifications de
     * rappel de cotisation cesseraient de partir, et on ne le découvrirait
     * qu'en s'étonnant, des semaines plus tard, que plus personne ne paie.
     *
     * @return array{ok: bool, pending: int, failed: int, message: string}
     */
    private function checkQueue(): array
    {
        try {
            $enAttente = DB::table('jobs')->count();
            $echoues = DB::table('failed_jobs')->count();

            $plusAncien = DB::table('jobs')
                ->where('available_at', '<=', now()->getTimestamp())
                ->min('available_at');

            $retard = $plusAncien === null
                ? 0
                : now()->getTimestamp() - (int) $plusAncien;

            $ok = $retard <= self::TOLERANCE_S;

            return [
                'ok' => $ok,
                'pending' => $enAttente,
                'failed' => $echoues,
                'message' => $ok
                    ? ($echoues > 0
                        // Un travail échoué ne bloque pas la file : on le
                        // signale sans crier à la panne, car il demande un
                        // examen humain et non un redémarrage.
                        ? $echoues.' travail(aux) en échec, à examiner.'
                        : 'File saine.')
                    : "Aucun ouvrier ne consomme la file depuis {$retard} s "
                        .'— `queue:work` est probablement arrêté.',
            ];
        } catch (Throwable) {
            // La base est déjà signalée en panne par ailleurs : inutile de
            // répéter, mais on ne prétend pas non plus que la file va bien.
            return [
                'ok' => false,
                'pending' => 0,
                'failed' => 0,
                'message' => 'File d\'attente inaccessible.',
            ];
        }
    }

    /**
     * Le planificateur bat-il encore ?
     *
     * Un planificateur arrêté ne peut pas signaler son propre arrêt. C'est
     * donc l'ABSENCE de battement récent qui le trahit — le seul signal qui ne
     * dépend pas du rouage surveillé.
     *
     * Ce qu'on perd sans lui : le contrôle nocturne du solde de caisse, les
     * instantanés de classement, les rappels. Rien ne casse visiblement, et
     * c'est bien le problème.
     *
     * @return array{ok: bool, last_beat: string|null, message: string}
     */
    private function checkScheduler(): array
    {
        try {
            $battement = Cache::get(self::HEARTBEAT);
        } catch (Throwable) {
            $battement = null;
        }

        if (! is_string($battement)) {
            return [
                'ok' => false,
                'last_beat' => null,
                'message' => "Le planificateur n'a jamais battu "
                    .'— `schedule:run` n\'est pas installé.',
            ];
        }

        $age = CarbonImmutable::parse($battement)->diffInSeconds(now());
        $ok = $age <= self::TOLERANCE_S;

        return [
            'ok' => $ok,
            'last_beat' => $battement,
            'message' => $ok
                ? 'Planificateur actif.'
                : "Aucun battement depuis {$age} s — `schedule:run` est arrêté.",
        ];
    }
}
