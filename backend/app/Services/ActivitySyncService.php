<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Jobs\ResolveActivityZones;
use App\Models\Activity;
use App\Models\ActivityStat;
use App\Services\Gps\ActivityStatsCalculator;
use App\Services\Gps\GpsFilter;
use App\Services\Gps\GpsPoint;
use Illuminate\Support\Facades\DB;

/**
 * Réception des points GPS et finalisation d'une activité.
 *
 * Trois exigences gouvernent ce service, toutes issues du terrain :
 *
 * 1. **Le rejeu doit être inoffensif.** Sur la Corniche, le réseau tombe et
 *    revient. Un lot part, la réponse se perd, le téléphone renvoie le même
 *    lot. Sans protection, la sortie ferait 60 km au lieu de 30. La contrainte
 *    `UNIQUE(activity_id, seq)` et l'insertion en `insertOrIgnore` rendent ce
 *    rejeu strictement sans effet.
 *
 * 2. **Le client n'est jamais cru.** Toutes les statistiques sont recalculées
 *    ici, à partir des points bruts stockés. Ce que le téléphone affichait
 *    pendant la sortie n'est qu'un confort d'affichage.
 *
 * 3. **Rien ne se perd silencieusement.** Chaque lot laisse une trace dans
 *    `sync_logs` : combien de points reçus, acceptés, rejetés et pourquoi.
 *    Sans ce journal, une trace anormalement courte serait inexplicable.
 */
final class ActivitySyncService
{
    public function __construct(
        private readonly ActivityStatsCalculator $calculator,
    ) {}

    /**
     * Enregistre un lot de points.
     *
     * @param  list<array<string, mixed>>  $rawPoints
     * @return array{received: int, accepted: int, rejected: int,
     *               rejection_reasons: array<string, int>, last_seq: int|null,
     *               total_points: int}
     */
    public function ingest(Activity $activity, array $rawPoints, ?string $deviceId = null): array
    {
        $received = count($rawPoints);

        $points = array_map(
            static fn (array $row) => GpsPoint::fromArray($row),
            $rawPoints,
        );

        /*
         * Le filtre est réappliqué ICI même si le mobile a déjà filtré.
         *
         * Non par défiance envers notre propre application, mais parce qu'un
         * téléphone peut tourner une version ancienne pendant des semaines —
         * on ne maîtrise pas le rythme des mises à jour sur les stores.
         * Le serveur est le seul endroit dont on garantit la version.
         */
        $filter = new GpsFilter($activity->sport);
        $accepted = $filter->apply($points);

        DB::transaction(function () use ($activity, $accepted, $received, $rawPoints, $filter, $deviceId): void {
            if ($accepted !== []) {
                $rows = array_map(
                    static fn (GpsPoint $point) => $point->toDatabaseRow($activity->id),
                    $accepted,
                );

                /*
                 * `insertOrIgnore` et non `insert` : c'est ce qui absorbe le
                 * rejeu d'un lot. Les points déjà présents (même activité,
                 * même `seq`) sont ignorés en silence, sans erreur.
                 *
                 * Découpage en tranches de 500 : au-delà, MySQL refuse la
                 * requête (nombre de paramètres liés) sur un lot volumineux.
                 */
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('activity_points')->insertOrIgnore($chunk);
                }
            }

            /*
             * `raw_points_count` mesure ce que L'APPAREIL a produit, pas le
             * nombre d'appels reçus.
             *
             * On prend donc le `seq` maximum vu et non un cumul : rejouer un
             * lot après une coupure réseau gonflerait sinon le compteur, et
             * ferait passer une trace parfaitement propre pour un signal de
             * mauvaise qualité. Le `seq` est attribué par le téléphone et
             * strictement croissant : c'est exactement le compteur voulu.
             */
            $maxSeq = 0;
            foreach ($rawPoints as $row) {
                $maxSeq = max($maxSeq, (int) ($row['seq'] ?? 0));
            }

            $activity->forceFill([
                'raw_points_count' => max((int) $activity->raw_points_count, $maxSeq),
                'synced_at' => now(),
            ])->save();

            DB::table('sync_logs')->insert([
                'activity_id' => $activity->id,
                'member_id' => $activity->member_id,
                'device_id' => $deviceId,
                'points_received' => $received,
                'points_accepted' => count($accepted),
                'points_rejected' => $filter->rejectedCount(),
                'rejection_reasons' => json_encode($filter->rejections()),
                'created_at' => now(),
            ]);
        });

        $totalStored = $activity->points()->count();

        return [
            'received' => $received,
            'accepted' => count($accepted),
            'rejected' => $filter->rejectedCount(),
            'rejection_reasons' => $filter->rejections(),
            'last_seq' => $activity->points()->max('seq'),
            'total_points' => $totalStored,
        ];
    }

    /**
     * Finalise l'activité : recalcule tout et fige la trace.
     *
     * Idempotent : refinaliser une activité déjà terminée recalcule les mêmes
     * chiffres et ne casse rien. C'est nécessaire, car le téléphone peut
     * réémettre la finalisation si la réponse s'est perdue.
     */
    public function finalize(Activity $activity, ?\DateTimeInterface $endedAt = null): Activity
    {
        return DB::transaction(function () use ($activity, $endedAt): Activity {
            // Les points sont relus par tranches : charger 10 000 modèles
            // Eloquent d'un coup consommerait plusieurs centaines de Mo.
            /** @var list<GpsPoint> $points */
            $points = [];

            $activity->points()
                ->orderBy('seq')
                ->chunk(2000, function ($chunk) use (&$points): void {
                    foreach ($chunk as $point) {
                        $points[] = $point->toGpsPoint();
                    }
                });

            $stats = $this->calculator->calculate($points, $activity->sport);

            $activity->forceFill([
                'status' => ActivityStatus::Completed,
                'ended_at' => $endedAt ?? $this->inferEnd($activity, $points),
                'distance_m' => $stats['distance_m'],
                'duration_s' => $stats['duration_s'],
                'moving_time_s' => $stats['moving_time_s'],
                'paused_time_s' => $stats['paused_time_s'],
                'avg_speed_mps' => $stats['avg_speed_mps'],
                'max_speed_mps' => $stats['max_speed_mps'],
                'elevation_gain_m' => $stats['elevation_gain_m'],
                'elevation_loss_m' => $stats['elevation_loss_m'],
                'min_altitude_m' => $stats['min_altitude_m'],
                'max_altitude_m' => $stats['max_altitude_m'],
                'avg_pace_s_per_km' => $stats['avg_pace_s_per_km'],
                'best_pace_s_per_km' => $stats['best_pace_s_per_km'],
                'calories_kcal' => $stats['calories_kcal'],
                'polyline' => $stats['polyline'],
                'bounds' => $stats['bounds'],
                'start_lat' => $stats['start_lat'],
                'start_lng' => $stats['start_lng'],
                'end_lat' => $stats['end_lat'],
                'end_lng' => $stats['end_lng'],
                'points_count' => $stats['points_count'],
                'synced_at' => now(),
            ])->save();

            ActivityStat::updateOrCreate(
                ['activity_id' => $activity->id],
                [
                    'splits' => $stats['splits'],
                    'elevation_profile' => $stats['elevation_profile'],
                    'speed_histogram' => $stats['speed_histogram'],
                    'computed_at' => now(),
                ],
            );

            /*
             * Les zones traversees sont resolues APRES coup, en file
             * d'attente. Nominatim impose une seconde entre deux requetes :
             * les calculer ici ferait attendre le membre une douzaine de
             * secondes devant un ecran fige, apres une sortie de trois heures.
             *
             * `afterCommit` : le job ne part qu'une fois la transaction
             * validee. Sans cela, il pourrait chercher une activite que la
             * base n'a pas encore.
             */
            ResolveActivityZones::dispatch($activity->id)->afterCommit();

            return $activity->fresh(['stats']);
        });
    }

    /**
     * Fin de l'activité quand le client ne l'a pas fournie.
     *
     * On prend l'horodatage du dernier point plutôt que « maintenant » : si la
     * synchronisation a lieu trois heures après la sortie (retour à la maison,
     * connexion au Wi-Fi), « maintenant » ajouterait trois heures fantômes à
     * la durée.
     *
     * @param  list<GpsPoint>  $points
     */
    private function inferEnd(Activity $activity, array $points): \DateTimeInterface
    {
        if ($points !== []) {
            return $points[count($points) - 1]->recordedAt;
        }

        return $activity->ended_at ?? $activity->started_at;
    }
}
