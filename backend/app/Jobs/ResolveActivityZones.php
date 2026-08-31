<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Activity;
use App\Services\Gps\GpsPoint;
use App\Services\Gps\ZoneResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Résolution différée des zones traversées.
 *
 * **Pourquoi une file d'attente et non un calcul direct ?** Nominatim impose
 * une seconde entre deux requêtes. Une sortie traversant douze quartiers
 * demanderait donc douze secondes — pendant lesquelles le membre attendrait
 * son résumé, écran figé, après une sortie de trois heures. Inacceptable.
 *
 * Les zones sont un enrichissement : leur absence n'empêche rien, et elles
 * apparaîtront au prochain affichage de la fiche.
 */
final class ResolveActivityZones implements ShouldQueue
{
    use Queueable;

    /** Deux tentatives : le service externe peut être momentanément indisponible. */
    public int $tries = 2;

    /**
     * Une minute entre les tentatives, et un délai large : la file traite
     * plusieurs sorties, chacune pouvant attendre une seconde par cellule.
     */
    public int $backoff = 60;

    public int $timeout = 180;

    public function __construct(
        private readonly int $activityId,
    ) {}

    public function handle(ZoneResolver $resolver): void
    {
        $activity = Activity::find($this->activityId);

        if ($activity === null) {
            // La sortie a été supprimée entre-temps : ce n'est pas une erreur.
            return;
        }

        /** @var list<GpsPoint> $points */
        $points = [];

        // Un point sur vingt suffit largement : la grille fait 2,2 km de côté,
        // et à 6 m/s vingt points représentent 120 m. Relire les 10 000 points
        // ne changerait aucune cellule et coûterait vingt fois plus cher.
        $activity->points()
            ->orderBy('seq')
            ->where('is_paused', false)
            ->chunk(2000, function ($chunk) use (&$points): void {
                foreach ($chunk as $index => $point) {
                    if ($index % 20 === 0) {
                        $points[] = $point->toGpsPoint();
                    }
                }
            });

        if ($points === []) {
            return;
        }

        try {
            $zones = $resolver->resolve($points);
        } catch (Throwable $exception) {
            Log::warning('Résolution des zones impossible', [
                'activity' => $activity->uuid,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if ($zones !== []) {
            $activity->forceFill(['zones' => $zones])->save();
        }
    }

    /**
     * Un échec définitif est journalisé, sans plus.
     *
     * Une sortie sans zones reste une sortie parfaitement exploitable : la
     * distance, la durée et la trace sont là. Alerter le membre pour cela
     * serait disproportionné.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('Zones non résolues pour l\'activité '.$this->activityId, [
            'message' => $exception->getMessage(),
        ]);
    }
}
