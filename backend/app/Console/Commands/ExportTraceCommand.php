<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fige une trace GPS réelle en fixture de test.
 *
 * POURQUOI UNE COMMANDE, ET PAS UN EXPORT FAIT UNE FOIS À LA MAIN
 *
 * Chaque défaut GPS signalé jusqu'ici — le sur-comptage à la marche, les 67 m à
 * l'arrêt, la marche non comptée — a d'abord été reproduit sur des traces
 * SYNTHÉTIQUES. Elles ont bien servi : elles isolent un phénomène et se
 * règlent à volonté. Mais elles ressemblent à ce qu'on croit que fait un GPS,
 * pas à ce qu'il fait vraiment.
 *
 * Une vraie trace, elle, porte les irrégularités qu'on n'aurait pas pensé à
 * simuler : une précision qui change en cours de route, un point qui manque,
 * un intervalle irrégulier parce que le téléphone a hésité. Le jour où le club
 * signale un nouveau défaut, la première chose à faire est de figer la trace
 * qui l'a produit — avant qu'elle ne soit recalculée, corrigée, ou perdue.
 *
 * LES COORDONNÉES SONT CONSERVÉES TELLES QUELLES.
 *
 * Une trace GPS dit où quelqu'un est allé : c'est une donnée sensible, et
 * `docs/risques.md` la traite comme telle. Ces fixtures-ci sont des essais
 * faits volontairement pour la mise au point, sur la voie publique, par les
 * personnes qui développent l'application. Une trace de membre ne doit JAMAIS
 * être exportée ainsi sans son accord explicite.
 */
final class ExportTraceCommand extends Command
{
    protected $signature = 'cyclo:export-trace
        {uuid : L\'uuid de la sortie}
        {--name= : Le nom du fichier, sans extension}';

    protected $description = 'Exporte une trace GPS réelle en fixture JSON, pour les tests.';

    public function handle(): int
    {
        $activity = Activity::query()->where('uuid', $this->argument('uuid'))->first();

        if ($activity === null) {
            $this->error('  Sortie introuvable.');

            return self::FAILURE;
        }

        $points = DB::table('activity_points')
            ->where('activity_id', $activity->id)
            ->orderBy('seq')
            ->get([
                'seq', 'lat', 'lng', 'altitude_m', 'speed_mps',
                'accuracy_m', 'heading_deg', 'recorded_at', 'is_paused',
            ]);

        if ($points->isEmpty()) {
            $this->error("  Cette sortie n'a aucun point brut.");

            return self::FAILURE;
        }

        $nom = $this->option('name')
            ?? \Illuminate\Support\Str::slug((string) ($activity->title ?? $activity->uuid));

        $fixture = [
            // Ce que la fixture EST, pour qui la relira dans deux ans sans
            // savoir d'où elle vient.
            'source' => 'Enregistrement réel, Cyclo Dakar',
            'sport' => $activity->sport->value,
            'recorded_at' => $activity->started_at?->toIso8601String(),
            'points_count' => $points->count(),
            'points' => $points->map(fn ($p) => [
                'seq' => (int) $p->seq,
                // Les coordonnées restent en chaîne : elles sont en DECIMAL en
                // base, et les passer par un flottant JSON leur ferait perdre
                // des décimales — donc des mètres.
                'lat' => (string) $p->lat,
                'lng' => (string) $p->lng,
                'altitude_m' => $p->altitude_m === null ? null : (float) $p->altitude_m,
                'speed_mps' => $p->speed_mps === null ? null : (float) $p->speed_mps,
                'accuracy_m' => $p->accuracy_m === null ? null : (float) $p->accuracy_m,
                'heading_deg' => $p->heading_deg === null ? null : (float) $p->heading_deg,
                'recorded_at' => (string) $p->recorded_at,
                'is_paused' => (bool) $p->is_paused,
            ])->all(),
        ];

        $dossier = base_path('tests/Fixtures/traces');

        if (! is_dir($dossier)) {
            mkdir($dossier, recursive: true);
        }

        $chemin = "{$dossier}/{$nom}.json";

        file_put_contents(
            $chemin,
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->newLine();
        $this->line("  <fg=green>✔</> {$points->count()} point(s) écrits dans tests/Fixtures/traces/{$nom}.json");
        $this->line('     '.number_format(filesize($chemin) / 1024, 1).' Ko');
        $this->newLine();

        return self::SUCCESS;
    }
}
