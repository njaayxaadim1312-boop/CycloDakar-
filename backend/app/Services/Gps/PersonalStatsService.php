<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Enums\ActivityStatus;
use App\Enums\Sport;
use App\Models\Activity;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cumuls et records personnels d'un membre.
 *
 * Deux principes de conception :
 *
 * 1. **Un seul balayage par bloc.** Les cumuls, la répartition par sport et
 *    les records se calculent en trois requêtes agrégées, pas en chargeant les
 *    activités. Un membre assidu en aura des centaines par an ; les instancier
 *    pour les additionner serait absurde.
 *
 * 2. **Un record vide n'est pas un record à zéro.** Un membre qui n'a encore
 *    rien enregistré n'a pas « 0 km de record » : il n'a pas de record. La
 *    distinction est renvoyée telle quelle au client, qui affiche un tiret.
 */
final class PersonalStatsService
{
    /** Nombre de semaines dans la courbe d'activité. */
    private const TREND_WEEKS = 12;

    /**
     * @param  'week'|'month'|'year'|'all'  $period
     * @return array<string, mixed>
     */
    public function forMember(Member $member, string $period = 'month'): array
    {
        [$from, $label] = $this->periodBounds($period);

        return [
            'period' => $period,
            'period_label' => $label,
            'period_from' => $from?->toDateString(),

            'totals' => $this->totals($member, $from),
            'goals' => $member->weeklyGoals(),
            'by_sport' => $this->bySport($member, $from),

            // Les records portent sur TOUTE la carrière du membre, jamais sur
            // la période : un record du mois n'est pas un record.
            'records' => $this->records($member),

            'trend' => $this->weeklyTrend($member),

            // Anneaux d'activite : toujours la SEMAINE en cours, quelle que
            // soit la periode demandee. Un objectif hebdomadaire compare aux
            // cumuls de l'annee ne voudrait rien dire, et un anneau rempli a
            // 900 % ne se lit pas.
            'rings' => $this->rings($member),
        ];
    }

    /**
     * Anneaux d'activite de la semaine en cours.
     *
     * Trois mesures, trois anneaux, a la maniere de l'application Forme :
     * distance, temps en mouvement, nombre de sorties. Chacun porte sa valeur
     * BRUTE et son objectif ; le pourcentage est calcule ici une fois pour
     * toutes plutot que dans chaque client, pour que le web et le mobile
     * remplissent exactement pareil.
     *
     * Le depassement n'est pas ecrete a 100 % : une semaine a 150 % merite de
     * se voir. C'est au client de decider s'il enroule l'anneau une seconde
     * fois ou s'il l'arrete au tour complet.
     *
     * @return array<string, mixed>
     */
    private function rings(Member $member): array
    {
        $from = CarbonImmutable::now()->startOfWeek();
        $goals = $member->weeklyGoals();

        $row = $this->scope($member, $from)
            ->selectRaw('
                COUNT(*) as activities,
                COALESCE(SUM(distance_m), 0) as distance_m,
                COALESCE(SUM(moving_time_s), 0) as moving_time_s
            ')
            ->first();

        $current = [
            'distance_m' => (int) ($row->distance_m ?? 0),
            'moving_time_s' => (int) ($row->moving_time_s ?? 0),
            'activities' => (int) ($row->activities ?? 0),
        ];

        $rings = [];

        foreach ($goals as $key => $goal) {
            $value = $current[$key];

            $rings[$key] = [
                'value' => $value,
                'goal' => $goal,
                // Un objectif a zero est desactive : on renvoie null plutot
                // qu'une division par zero ou un 100 % trompeur.
                'percent' => $goal > 0 ? round(($value / $goal) * 100, 1) : null,
                'completed' => $goal > 0 && $value >= $goal,
            ];
        }

        return [
            'week_start' => $from->toDateString(),
            'metrics' => $rings,
            // Jours de la semaine ou le membre a bouge : la trame de fond des
            // anneaux, qui montre la REGULARITE plutot que le seul volume.
            'days' => $this->weekDays($member, $from),
        ];
    }

    /**
     * Les sept jours de la semaine en cours, actifs ou non.
     *
     * Les jours vides sont presents : une semaine ou l'on a roule lundi et
     * dimanche ne se lit pas comme une semaine ou l'on a roule deux jours de
     * suite, et c'est precisement ce que le membre veut voir.
     *
     * @return list<array{date: string, label: string, distance_m: int, active: bool}>
     */
    private function weekDays(Member $member, CarbonImmutable $from): array
    {
        $rows = $this->scope($member, $from)->get(['started_at', 'distance_m']);

        $buckets = [];

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->started_at)->toDateString();
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $row->distance_m;
        }

        $labels = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $from->addDays($i);
            $key = $day->toDateString();

            $days[] = [
                'date' => $key,
                'label' => $labels[$i],
                'distance_m' => $buckets[$key] ?? 0,
                'active' => isset($buckets[$key]),
            ];
        }

        return $days;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * @return array{CarbonImmutable|null, string}
     */
    private function periodBounds(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            // Semaine ISO : elle commence le lundi, comme le calendrier du club.
            'week' => [$now->startOfWeek(), 'Cette semaine'],
            'year' => [$now->startOfYear(), 'Cette année'],
            'all' => [null, 'Depuis toujours'],
            default => [$now->startOfMonth(), 'Ce mois-ci'],
        };
    }

    /** @return Builder<Activity> */
    private function scope(Member $member, ?CarbonImmutable $from): Builder
    {
        $query = Activity::query()
            ->where('member_id', $member->id)
            // Seules les sorties terminées comptent : une activité en cours
            // n'a pas de statistiques fiables.
            ->where('status', ActivityStatus::Completed);

        if ($from !== null) {
            $query->where('started_at', '>=', $from);
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function totals(Member $member, ?CarbonImmutable $from): array
    {
        $row = $this->scope($member, $from)
            ->selectRaw('
                COUNT(*) as activities,
                COALESCE(SUM(distance_m), 0) as distance_m,
                COALESCE(SUM(moving_time_s), 0) as moving_time_s,
                COALESCE(SUM(duration_s), 0) as duration_s,
                COALESCE(SUM(elevation_gain_m), 0) as elevation_gain_m
            ')
            ->first();

        $distance = (int) ($row->distance_m ?? 0);
        $moving = (int) ($row->moving_time_s ?? 0);

        return [
            'activities' => (int) ($row->activities ?? 0),
            'distance_m' => $distance,
            'moving_time_s' => $moving,
            'duration_s' => (int) ($row->duration_s ?? 0),
            'elevation_gain_m' => (int) ($row->elevation_gain_m ?? 0),

            // Moyenne calculée sur les totaux, et non moyenne des moyennes :
            // une sortie de 2 km ne doit pas peser autant qu'une de 80 km.
            'avg_speed_mps' => $moving > 0 ? round($distance / $moving, 3) : 0.0,
        ];
    }

    /**
     * Répartition par sport.
     *
     * Tous les sports sont présents, même à zéro : un sport absent de la
     * réponse disparaîtrait de l'affichage et semblerait ne pas exister.
     *
     * @return array<string, array<string, mixed>>
     */
    private function bySport(Member $member, ?CarbonImmutable $from): array
    {
        $rows = $this->scope($member, $from)
            ->selectRaw('
                sport,
                COUNT(*) as activities,
                COALESCE(SUM(distance_m), 0) as distance_m,
                COALESCE(SUM(moving_time_s), 0) as moving_time_s
            ')
            ->groupBy('sport')
            ->get()
            ->keyBy('sport');

        $result = [];

        foreach (Sport::cases() as $sport) {
            $row = $rows->get($sport->value);

            $result[$sport->value] = [
                'label' => $sport->label(),
                'activities' => (int) ($row->activities ?? 0),
                'distance_m' => (int) ($row->distance_m ?? 0),
                'moving_time_s' => (int) ($row->moving_time_s ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Records personnels, sur toute la carrière.
     *
     * Chaque record pointe vers l'activité qui l'a établi : le membre veut
     * pouvoir revoir la sortie dont il est fier, pas seulement le chiffre.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function records(Member $member): array
    {
        return [
            'longest_distance' => $this->recordBy($member, 'distance_m'),
            'longest_duration' => $this->recordBy($member, 'moving_time_s'),
            'max_speed' => $this->recordBy($member, 'max_speed_mps'),
            'most_elevation' => $this->recordBy($member, 'elevation_gain_m'),

            // L'allure se lit à l'envers : la MEILLEURE est la plus basse.
            'best_pace' => $this->recordBy($member, 'best_pace_s_per_km', ascending: true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recordBy(Member $member, string $column, bool $ascending = false): ?array
    {
        $activity = Activity::query()
            ->where('member_id', $member->id)
            ->where('status', ActivityStatus::Completed)
            // Un zéro n'est pas un record : une sortie sans distance mesurée
            // ne doit pas devenir « le record de distance ».
            ->where($column, '>', 0)
            ->orderBy($column, $ascending ? 'asc' : 'desc')
            ->first();

        if ($activity === null) {
            return null;
        }

        return [
            'value' => is_numeric($activity->{$column})
                ? (float) $activity->{$column}
                : $activity->{$column},
            'activity_uuid' => $activity->uuid,
            'activity_title' => $activity->displayTitle(),
            'sport' => $activity->sport->value,
            'achieved_at' => $activity->started_at?->toIso8601String(),
        ];
    }

    /**
     * Distance par semaine sur les douze dernières.
     *
     * Le regroupement est fait en PHP : `WEEK()` est propre à MySQL,
     * `strftime` à SQLite. Le volume est borné par les sorties de trois mois —
     * quelques dizaines de lignes au plus, le gain d'un GROUP BY serait nul.
     *
     * @return list<array{week: string, label: string, distance_m: int, activities: int}>
     */
    private function weeklyTrend(Member $member): array
    {
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks(self::TREND_WEEKS - 1);

        $rows = Activity::query()
            ->where('member_id', $member->id)
            ->where('status', ActivityStatus::Completed)
            ->where('started_at', '>=', $start)
            ->get(['started_at', 'distance_m']);

        $buckets = [];

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->started_at)->startOfWeek()->toDateString();

            $buckets[$key] ??= ['distance_m' => 0, 'activities' => 0];
            $buckets[$key]['distance_m'] += (int) $row->distance_m;
            $buckets[$key]['activities']++;
        }

        $series = [];

        for ($i = 0; $i < self::TREND_WEEKS; $i++) {
            $week = $start->addWeeks($i);
            $key = $week->toDateString();

            $series[] = [
                'week' => $key,
                // « 25 août » : plus lisible qu'un numéro de semaine ISO, que
                // personne ne sait situer.
                'label' => $week->translatedFormat('j M'),
                'distance_m' => $buckets[$key]['distance_m'] ?? 0,
                'activities' => $buckets[$key]['activities'] ?? 0,
            ];
        }

        return $series;
    }
}
