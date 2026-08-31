<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NodeServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnostic complet de l'installation.
 *
 * Une seule commande répond à « est-ce que tout est bien branché ? », ce qui
 * évite de chercher à l'aveugle entre XAMPP, PHP, MySQL, Laravel et Node.
 *
 *   php artisan cyclo:doctor
 */
final class DoctorCommand extends Command
{
    protected $signature = 'cyclo:doctor';

    protected $description = "Vérifie que l'environnement Cyclo Dakar est correctement configuré";

    /** @var list<array{0: string, 1: bool, 2: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=black;bg=yellow> CYCLO DAKAR </> Diagnostic de l\'environnement');
        $this->newLine();

        $this->checkPhp();
        $this->checkExtensions();
        $this->checkAppKey();
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkStorage();
        $this->checkNodeService();

        $this->newLine();

        $failures = array_filter($this->results, fn (array $row) => ! $row[1]);

        foreach ($this->results as [$label, $ok, $detail]) {
            $icon = $ok ? '<fg=green>✔</>' : '<fg=red>✘</>';
            $this->line(sprintf('  %s  %-34s %s', $icon, $label, $detail));
        }

        $this->newLine();

        if ($failures !== []) {
            $this->line('  <fg=red>'.count($failures).' point(s) à corriger.</> Voir docs/installation.md');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=green>Tout est opérationnel.</> Lancez « php artisan serve ».');
        $this->newLine();

        return self::SUCCESS;
    }

    private function add(string $label, bool $ok, string $detail): void
    {
        $this->results[] = [$label, $ok, $detail];
    }

    private function checkPhp(): void
    {
        // Laravel 13 exige PHP 8.3 minimum ; XAMPP livre souvent 8.1.
        $ok = version_compare(PHP_VERSION, '8.3.0', '>=');

        $this->add(
            'Version de PHP',
            $ok,
            $ok
                ? PHP_VERSION
                : PHP_VERSION.' — PHP 8.3+ requis (voir docs/installation.md)',
        );
    }

    private function checkExtensions(): void
    {
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'zip', 'gd', 'bcmath'];
        $missing = array_values(array_filter($required, fn (string $ext) => ! extension_loaded($ext)));

        $this->add(
            'Extensions PHP',
            $missing === [],
            $missing === []
                ? count($required).' extensions présentes'
                : 'manquantes : '.implode(', ', $missing),
        );
    }

    private function checkAppKey(): void
    {
        $key = (string) config('app.key');

        $this->add(
            'Clé applicative',
            $key !== '',
            $key !== '' ? 'définie' : 'absente — lancez « php artisan key:generate »',
        );
    }

    private function checkDatabase(): void
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $version = DB::selectOne('select version() as v')->v ?? '?';
            $latency = round((microtime(true) - $start) * 1000, 1);

            $this->add(
                'Base de données',
                true,
                sprintf('%s (%s) — %s ms', DB::connection()->getDatabaseName(), $version, $latency),
            );
        } catch (Throwable $e) {
            $this->add(
                'Base de données',
                false,
                'injoignable — démarrez MySQL dans XAMPP ('.$e->getMessage().')',
            );
        }
    }

    private function checkMigrations(): void
    {
        try {
            if (! Schema::hasTable('migrations')) {
                $this->add('Migrations', false, 'jamais exécutées — lancez « php artisan migrate »');

                return;
            }

            $ran = DB::table('migrations')->count();
            $files = count(glob(database_path('migrations/*.php')) ?: []);
            $ok = $ran >= $files;

            $this->add(
                'Migrations',
                $ok,
                $ok
                    ? "{$ran} migration(s) appliquée(s)"
                    : sprintf('%d/%d appliquées — lancez « php artisan migrate »', $ran, $files),
            );
        } catch (Throwable) {
            $this->add('Migrations', false, 'vérification impossible (base injoignable)');
        }
    }

    private function checkStorage(): void
    {
        $paths = [
            'app' => storage_path('app'),
            'logs' => storage_path('logs'),
            'framework' => storage_path('framework'),
        ];

        $unwritable = array_keys(array_filter($paths, fn (string $path) => ! is_writable($path)));

        $this->add(
            'Stockage',
            $unwritable === [],
            $unwritable === []
                ? 'accessible en écriture'
                : 'non inscriptible : storage/'.implode(', storage/', $unwritable),
        );
    }

    private function checkNodeService(): void
    {
        $result = NodeServiceClient::fromConfig()->ping();

        // Le service Node n'est pas indispensable avant la phase 15 : on le
        // signale sans faire échouer le diagnostic global.
        $this->add(
            'Service Node (optionnel)',
            true,
            $result['ok']
                ? '<fg=green>joignable</> — secret partagé valide'
                : '<fg=yellow>'.$result['message'].'</>',
        );
    }
}
