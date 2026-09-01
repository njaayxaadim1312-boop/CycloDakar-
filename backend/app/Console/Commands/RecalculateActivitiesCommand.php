<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Services\ActivitySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcule les statistiques des sorties depuis leurs points bruts.
 *
 *   php artisan cyclo:recalculate
 *   php artisan cyclo:recalculate --since=2026-09-01
 *   php artisan cyclo:recalculate --uuid=…
 *
 * **À lancer après toute correction du filtre GPS.** Les statistiques sont
 * figées à la finalisation : une sortie enregistrée avant un correctif garde
 * les chiffres de l'ancienne formule, et affiche alors une distance différente
 * de celle que le rejeu ou une réimportation donneraient. Deux chiffres pour
 * la même sortie détruisent la confiance plus sûrement qu'un chiffre
 * approximatif.
 *
 * Rien n'est perdu ni inventé : **les points bruts sont la source de vérité**,
 * et ils ne sont jamais modifiés. Cette commande ne fait que rejouer le calcul
 * par-dessus.
 */
final class RecalculateActivitiesCommand extends Command
{
    protected $signature = 'cyclo:recalculate
        {--uuid= : Ne recalculer qu\'une sortie}
        {--since= : Seulement les sorties démarrées depuis cette date}
        {--dry-run : Montrer les écarts sans rien écrire}';

    protected $description = 'Recalcule distance, durées et allures depuis les points bruts';

    public function handle(ActivitySyncService $sync): int
    {
        $query = Activity::query()
            ->where('status', ActivityStatus::Completed)
            // Sans point brut, il n'y a rien à recalculer : ce sont les
            // sorties de démonstration, ou des traces déjà purgées.
            ->where('points_count', '>', 0);

        if ($uuid = $this->option('uuid')) {
            $query->where('uuid', $uuid);
        }

        if ($since = $this->option('since')) {
            $query->where('started_at', '>=', $since);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->line("\n  Aucune sortie à recalculer.\n");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('  <fg=black;bg=yellow> CYCLO DAKAR </> Recalcul des statistiques');
        $this->line($dryRun ? '  <fg=gray>Simulation : rien ne sera écrit.</>' : '');
        $this->newLine();

        $changed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Par lots : une sortie de trois heures porte des milliers de points,
        // et charger tout le club d'un coup épuiserait la mémoire.
        $query->orderBy('id')->chunkById(50, function ($activities) use ($sync, $dryRun, &$changed, $bar): void {
            foreach ($activities as $activity) {
                $avant = [
                    'distance_m' => (int) $activity->distance_m,
                    'moving_time_s' => (int) $activity->moving_time_s,
                ];

                /*
                 | La simulation passe par une transaction ANNULEE.
                 |
                 | Ma premiere version ecrivait puis restaurait deux champs a
                 | la main — elle laissait donc les autres (vitesse moyenne,
                 | splits, polyligne) ecrases, et une interruption au milieu
                 | aurait abime la base. Une transaction rendue au point de
                 | depart ne peut rien laisser derriere elle.
                 */
                if ($dryRun) {
                    DB::beginTransaction();

                    try {
                        $sync->finalize($activity, null);
                        $apres = $activity->fresh();
                    } finally {
                        DB::rollBack();
                    }
                } else {
                    $sync->finalize($activity, null);
                    $apres = $activity->fresh();
                }

                if ($apres !== null && (int) $apres->distance_m !== $avant['distance_m']) {
                    $changed++;

                    $this->newLine();
                    $this->line(sprintf(
                        '  %-28s %5d m → %5d m  (%+d)',
                        mb_substr($activity->displayTitle(), 0, 28),
                        $avant['distance_m'],
                        (int) $apres->distance_m,
                        (int) $apres->distance_m - $avant['distance_m'],
                    ));
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $this->newLine(2);
        $this->line("  <fg=green>✔</> {$total} sortie(s) passée(s) en revue, {$changed} corrigée(s).");

        if ($dryRun && $changed > 0) {
            $this->line('     Relancez sans <options=bold>--dry-run</> pour appliquer.');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
