<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
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
 * Route publique : elle ne divulgue aucune donnée sensible.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $storage = $this->checkStorage();

        $healthy = $database['ok'] && $storage['ok'];

        return ApiResponse::ok([
            'application' => config('cyclo.club.name'),
            'api_version' => 'v1',
            'environment' => app()->environment(),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => [
                'database' => $database,
                'storage' => $storage,
            ],
        ], status: $healthy ? 200 : 503);
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
}
