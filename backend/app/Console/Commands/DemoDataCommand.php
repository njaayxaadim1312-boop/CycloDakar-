<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Sport;
use App\Models\Activity;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Jeu de démonstration : des sorties et des événements plausibles.
 *
 * Une base fraîchement migrée ne contient ni sortie ni sortie officielle. Les
 * écrans qui font tout l'intérêt de l'application — anneaux d'activité,
 * régularité de la semaine, calendrier — s'affichent alors vides, et l'on
 * conclut à tort qu'ils ne fonctionnent pas.
 *
 *   php artisan cyclo:demo
 *
 * **Refuse de s'exécuter hors environnement local.** Ces données sont
 * inventées ; les mêler à de vraies sorties fausserait les cumuls, et à terme
 * les classements et les participations financières. Le garde-fou n'est pas
 * une précaution de principe : `--force` existe, mais il faut le vouloir.
 */
final class DemoDataCommand extends Command
{
    protected $signature = 'cyclo:demo
        {--force : Autoriser hors environnement local}
        {--fresh : Supprimer les données de démonstration existantes}';

    protected $description = 'Crée des sorties et des événements de démonstration';

    /** Le compte avec lequel on regardera le résultat. */
    private const SHOWCASE_EMAIL = 'membre@cyclodakar.sn';

    /**
     * La semaine du compte vitrine, écrite à la main.
     *
     * Tirée au hasard, elle donnait 800 % de l'objectif : quatre sorties vélo
     * de 40 km, ce qu'aucun membre ne fait en une semaine. Un jeu de
     * démonstration doit ressembler à la réalité du club, sinon il ne
     * démontre rien — et des anneaux à 800 % ne montrent même pas comment un
     * anneau se remplit.
     *
     * Le total vise LÉGÈREMENT AU-DESSUS de l'objectif par défaut (20 km,
     * 2 h, 3 sorties) : on voit ainsi des anneaux atteints ET un anneau
     * encore en cours, ce qui est exactement ce qu'il faut regarder.
     *
     * @var list<array{0: int, 1: string, 2: int, 3: int}> jour, sport, mètres, secondes
     */
    private const SHOWCASE_WEEK = [
        [0, 'CYCLING', 12_400, 2_520],
        [1, 'WALKING', 4_100, 3_060],
        [3, 'RUNNING', 6_200, 1_980],
        [5, 'CYCLING', 9_800, 2_040],
    ];

    /** Parcours réels du club, pour des titres crédibles. */
    private const ROUTES = [
        ['Corniche matin', 'Corniche Ouest', 14.6800, -17.4800],
        ['Boucle des Almadies', 'Almadies', 14.7450, -17.5230],
        ['Dakar — Yoff', 'Plage de Yoff', 14.7530, -17.4700],
        ['Tour de la Renaissance', 'Monument de la Renaissance', 14.7200, -17.4900],
        ['Sortie Ouakam', 'Ouakam', 14.7180, -17.4930],
    ];

    public function handle(): int
    {
        if (! app()->environment('local') && ! $this->option('force')) {
            $this->error('  Refusé : `cyclo:demo` ne tourne qu\'en local (--force pour passer outre).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <fg=black;bg=yellow> CYCLO DAKAR </> Jeu de démonstration');
        $this->newLine();

        if ($this->option('fresh')) {
            $this->removeExisting();
        }

        $members = Member::query()->whereNotNull('user_id')->take(6)->get();

        if ($members->isEmpty()) {
            $this->error('  Aucun membre relié à un compte. Lancez d\'abord `php artisan db:seed`.');

            return self::FAILURE;
        }

        // Le compte de demonstration passe en tete : c'est SA semaine qu'on
        // remplit, et c'est avec lui qu'on se connectera pour regarder les
        // anneaux. Se fier au premier membre par identifiant remplissait la
        // semaine de quelqu'un d'autre — anneaux a zero pour le testeur.
        $showcase = Member::query()
            ->whereHas('user', fn ($q) => $q->where('email', self::SHOWCASE_EMAIL))
            ->first();

        if ($showcase !== null) {
            $members = $members
                ->reject(fn (Member $m) => $m->id === $showcase->id)
                ->prepend($showcase)
                ->values();
        }

        $this->createActivities($members);
        $this->createEvents($members);

        $this->newLine();
        $this->line('  <fg=green>✔</> Jeu de démonstration en place.');
        $this->line('     Connectez-vous avec <options=bold>'.self::SHOWCASE_EMAIL.'</> pour voir les anneaux remplis.');
        $this->newLine();

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------------- */

    private function removeExisting(): void
    {
        // Les sorties de démonstration se reconnaissent à leur absence de
        // trace : elles n'ont aucun point brut. On ne touche donc jamais à une
        // sortie réellement enregistrée au GPS.
        $removed = Activity::query()->where('points_count', 0)->forceDelete();
        $events = Event::query()->forceDelete();

        $this->line("  <fg=gray>Supprimé : {$removed} sortie(s), {$events} événement(s).</>");
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Member>  $members
     */
    private function createActivities($members): void
    {
        $created = 0;

        foreach ($members as $index => $member) {
            $showcase = $index === 0;

            // La vitrine reçoit sa semaine écrite à la main, puis un
            // historique aléatoire pour que ses statistiques et ses records
            // aient de quoi se remplir.
            if ($showcase) {
                $created += $this->createShowcaseWeek($member);
            }

            $count = $showcase ? 10 : random_int(3, 8);

            for ($i = 0; $i < $count; $i++) {
                $sport = $this->pickSport();
                // Hors semaine vitrine : tout est dans le passé.
                $startedAt = now()->subDays(random_int(8, 300))
                    ->setTime(random_int(6, 18), random_int(0, 59));
                $route = self::ROUTES[array_rand(self::ROUTES)];

                [$distance, $moving] = $this->pickEffort($sport);

                $this->storeActivity($member, $sport, $startedAt, $route, $distance, $moving);

                $created++;
            }
        }

        $this->line("  <fg=green>✔</> {$created} sorties créées (vélo, course, marche, randonnée).");
    }

    /**
     * Ecrit une sortie de demonstration.
     *
     * `forceFill` et non `create` : le modele interdit l'assignation en masse
     * des statistiques, et c'est exactement ce qu'on veut — un client ne doit
     * jamais pouvoir s'inventer 200 km. Une commande locale a le droit de
     * contourner ce garde-fou, mais elle doit le faire EXPLICITEMENT plutot
     * que de l'affaiblir pour toute l'application.
     *
     * @param  array{0: string, 1: string, 2: float, 3: float}  $route
     */
    private function storeActivity(
        Member $member,
        Sport $sport,
        Carbon $startedAt,
        array $route,
        int $distance,
        int $moving,
    ): void {
        (new Activity)->forceFill([
            'uuid' => (string) Str::uuid(),
            'member_id' => $member->id,
            'sport' => $sport,
            'status' => ActivityStatus::Completed,
            'visibility' => ActivityVisibility::Club,
            'title' => $route[0],
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($moving + 600),

            'distance_m' => $distance,
            'duration_s' => $moving + 600,
            'moving_time_s' => $moving,
            'paused_time_s' => 600,
            'avg_speed_mps' => round($distance / $moving, 3),
            'max_speed_mps' => round(($distance / $moving) * 1.6, 3),
            'elevation_gain_m' => random_int(0, 90),
            'elevation_loss_m' => random_int(0, 90),

            // L'allure n'a de sens que pour les sports qui marchent ou
            // courent : une sortie velo garde ces colonnes a NULL, exactement
            // comme en production.
            'avg_pace_s_per_km' => $sport->usesPace()
                ? (int) round($moving / ($distance / 1000))
                : null,
            'best_pace_s_per_km' => $sport->usesPace()
                ? (int) round(($moving / ($distance / 1000)) * 0.92)
                : null,

            'zones' => [$route[1]],
            // Zero point brut : c'est la marque des donnees de demonstration,
            // et ce qui permet a `--fresh` de les distinguer d'une vraie trace.
            'points_count' => 0,
            'raw_points_count' => 0,
            'synced_at' => $startedAt,
        ])->save();
    }

    /**
     * La semaine du compte vitrine.
     *
     * Quatre sorties réparties sur la semaine, dans trois sports différents :
     * c'est ce qui permet de voir d'un coup d'œil que la répartition par
     * sport, la trame des sept jours et les anneaux fonctionnent.
     */
    private function createShowcaseWeek(Member $member): int
    {
        $created = 0;

        foreach (self::SHOWCASE_WEEK as $slot) {
            [$day, $sportCode, $distance, $moving] = $slot;

            $startedAt = now()->startOfWeek()->addDays($day)->setTime(6, 45);

            // Une sortie datée dans le futur n'a pas de sens : si la semaine
            // vient de commencer, on ne crée que les jours déjà passés. Le
            // compteur suit, sinon la commande annoncerait des sorties qui
            // n'existent pas.
            if ($startedAt->isFuture()) {
                continue;
            }

            $created++;

            $route = self::ROUTES[array_rand(self::ROUTES)];

            $this->storeActivity(
                $member,
                Sport::from($sportCode),
                $startedAt,
                $route,
                $distance,
                $moving,
            );
        }

        return $created;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Member>  $members
     */
    private function createEvents($members): void
    {
        $author = User::query()->whereNotNull('id')->orderBy('id')->firstOrFail();

        $planned = [
            ['Sortie du dimanche', Sport::Cycling, now()->next(Carbon::SUNDAY)->setTime(7, 0), 'Place de la Nation', 32_000, 'EASY', null],
            ['Grand Tour Cyclo Dakar', Sport::Cycling, now()->addDays(12)->setTime(7, 30), 'Place de la Nation', 35_000, 'HARD', 25],
            ['Marche de la Corniche', Sport::Walking, now()->addDays(4)->setTime(6, 30), 'Corniche Ouest', 8_000, 'EASY', null],
            ['10 km de Ouakam', Sport::Running, now()->addDays(20)->setTime(6, 30), 'Ouakam', 10_000, 'MEDIUM', 40],
        ];

        foreach ($planned as [$title, $sport, $startsAt, $place, $distance, $difficulty, $seats]) {
            $event = (new Event)->forceFill([
                'title' => $title,
                'description' => 'Départ groupé. Casque obligatoire, gilet conseillé.',
                'sport' => $sport,
                'status' => EventStatus::Published,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(3),
                'location_name' => $place,
                'start_lat' => 14.6928,
                'start_lng' => -17.4467,
                'planned_distance_m' => $distance,
                'difficulty' => $difficulty,
                'max_participants' => $seats,
                'created_by' => $author->id,
            ]);
            $event->save();

            // Quelques inscrits, en laissant la première sortie SANS le membre
            // de démonstration : il doit pouvoir essayer le bouton
            // « Je participe » lui-même.
            foreach ($members->skip(1)->take(random_int(2, 4)) as $member) {
                (new EventParticipant)->forceFill([
                    'event_id' => $event->id,
                    'member_id' => $member->id,
                    'registration_status' => RegistrationStatus::Registered,
                    'registered_at' => now()->subDays(random_int(1, 5)),
                ])->save();
            }
        }

        $this->line('  <fg=green>✔</> 4 sorties officielles annoncées.');
    }

    /* ---------------------------------------------------------------------- */

    /** Le cyclisme domine, la marche suit — la réalité du club. */
    private function pickSport(): Sport
    {
        return match (random_int(1, 10)) {
            1, 2, 3, 4, 5 => Sport::Cycling,
            6, 7 => Sport::Walking,
            8, 9 => Sport::Running,
            default => Sport::Hiking,
        };
    }

    /**
     * Distance et temps plausibles pour le sport.
     *
     * @return array{int, int}
     */
    private function pickEffort(Sport $sport): array
    {
        return match ($sport) {
            Sport::Cycling => [$d = random_int(15_000, 70_000), (int) round($d / random_int(5, 8))],
            Sport::Running => [$d = random_int(5_000, 15_000), (int) round($d / random_int(2, 3))],
            Sport::Hiking => [$d = random_int(6_000, 18_000), (int) round($d / 1.3)],
            Sport::Walking => [$d = random_int(3_000, 9_000), (int) round($d / 1.3)],
        };
    }
}
