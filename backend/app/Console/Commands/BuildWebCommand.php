<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Construit l'application web et la dépose dans `public/app/`.
 *
 * But : servir le web et l'API depuis **une seule adresse**, pour qu'une
 * démonstration accessible depuis un téléphone ne demande qu'un seul tunnel et
 * aucune configuration de CORS.
 *
 *   php artisan cyclo:build-web
 *
 * Le web est construit avec `VITE_API_URL=/api/v1` — la valeur relative par
 * défaut. C'est elle qui rend le résultat portable : la même construction
 * fonctionne sur `localhost`, sur une adresse de tunnel ou sur le domaine du
 * club, sans être reconstruite.
 */
final class BuildWebCommand extends Command
{
    protected $signature = 'cyclo:build-web {--skip-install : Ne pas relancer npm install}';

    protected $description = "Construit l'application web et la sert depuis Laravel";

    public function handle(): int
    {
        $webDir = base_path('../web');
        $target = public_path('app');

        if (! File::isDirectory($webDir)) {
            $this->error("  Dossier web introuvable : {$webDir}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <fg=black;bg=yellow> CYCLO DAKAR </> Construction du web');
        $this->newLine();

        if (! $this->option('skip-install') && ! File::isDirectory($webDir.'/node_modules')) {
            $this->line('  Installation des dépendances…');

            if (! $this->runProcess(['npm', 'install'], $webDir)) {
                return self::FAILURE;
            }
        }

        $this->line('  Construction…');

        if (! $this->runProcess(['npm', 'run', 'build'], $webDir)) {
            return self::FAILURE;
        }

        /*
         | Les fichiers vont a la RACINE de `public/`, et non dans un
         | sous-dossier : la construction reference ses ressources en
         | `/assets/...`, et les deplacer casserait tous ces chemins.
         |
         | Seul `index.html` est mis a part, dans `public/app/` : a la racine
         | il ne masquerait pas `index.php` (Laravel passe en premier), mais
         | il tromperait la lecture du dossier. Le repli SPA de `routes/web.php`
         | va le chercher la.
         */
        $dist = $webDir.'/dist';

        // On ne vide QUE ce que l'on a soi-meme depose : effacer `public/`
        // emporterait `index.php`, `.htaccess` et le lien de stockage.
        foreach (['assets', 'app'] as $stale) {
            if (File::isDirectory(public_path($stale))) {
                File::deleteDirectory(public_path($stale));
            }
        }

        File::ensureDirectoryExists($target);

        foreach (File::directories($dist) as $directory) {
            File::copyDirectory($directory, public_path(basename($directory)));
        }

        foreach (File::files($dist) as $file) {
            $destination = $file->getFilename() === 'index.html'
                ? $target.'/index.html'
                : public_path($file->getFilename());

            File::copy($file->getPathname(), $destination);
        }

        $size = $this->directorySize($dist);

        $this->newLine();
        $this->line('  <fg=green>✔</> Web construit et déposé dans <options=bold>backend/public/</> ('.$size.').');
        $this->line('     Une seule adresse sert désormais le web ET l\'API.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): bool
    {
        // Sous Windows, `npm` est un script `.cmd` : sans passer par le
        // shell, Symfony ne le trouve pas.
        $process = Process::fromShellCommandline(implode(' ', $command), $cwd, timeout: 900);

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('  Échec de : '.implode(' ', $command));

            return false;
        }

        return true;
    }

    private function directorySize(string $path): string
    {
        $bytes = 0;

        foreach (File::allFiles($path) as $file) {
            $bytes += $file->getSize();
        }

        return round($bytes / 1024 / 1024, 1).' Mo';
    }
}
