<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\EventStatus;
use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use App\Enums\RegistrationStatus;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Services\Gps\PersonalStatsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Statistiques du tableau de bord.
 *
 * Principe : on ne renvoie QUE ce qui est réellement mesurable aujourd'hui.
 * Les modules non livrés renvoient `null` accompagné de la phase qui les
 * apportera, plutôt qu'un zéro — un « 0 activité » et un « module pas encore
 * livré » ne veulent pas dire la même chose, et sur un tableau de bord qui
 * affichera un jour un solde de caisse, cette distinction est vitale pour la
 * confiance du bureau.
 */
final class StatsController extends Controller
{
    /** Nombre de mois d'historique dans la courbe des adhésions. */
    private const GROWTH_MONTHS = 12;

    /**
     * Cumuls et records personnels du membre connecté.
     *
     * `GET /stats/me?period=week|month|year|all`
     *
     * Les cumuls suivent la période demandée ; les **records portent toujours
     * sur toute la carrière** — un record du mois n'est pas un record.
     */
    public function me(Request $request, PersonalStatsService $stats): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['week', 'month', 'year', 'all'])],
        ]);

        $member = $request->user()->member;

        if ($member === null) {
            return ApiResponse::error(
                message: "Aucune fiche membre n'est associée à votre compte.",
                status: 404,
                code: 'NO_MEMBER_PROFILE',
            );
        }

        return ApiResponse::ok($stats->forMember($member, $validated['period'] ?? 'month'));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::ok([
            'members' => $this->memberStats(),

            // Les activités sont mesurables depuis la phase 6.
            'activities' => $this->activityStats(),

            // Les événements sont mesurables depuis la phase 9.
            'events' => $this->eventStats($request),

            // Les collectes sont mesurables depuis la phase 10.
            'participations' => $this->participationStats($request),

            // Modules à venir. `available: false` et non 0 : voir le
            // commentaire de classe.
            'finance' => $this->financeStats($request),

            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Activité sportive du club.
     *
     * Contrairement aux modules non livrés, ce bloc porte de vraies mesures :
     * il est marqué `available: true` pour que le client sache qu'un zéro
     * signifie ici « aucune sortie », et non « pas encore mesuré ».
     *
     * @return array<string, mixed>
     */
    private function activityStats(): array
    {
        $row = Activity::query()
            ->where('status', ActivityStatus::Completed)
            ->selectRaw('
                COUNT(*) as total,
                COALESCE(SUM(distance_m), 0) as distance_m,
                COALESCE(SUM(moving_time_s), 0) as moving_time_s
            ')
            ->first();

        $thisMonth = Activity::query()
            ->where('status', ActivityStatus::Completed)
            ->where('started_at', '>=', now()->startOfMonth())
            ->count();

        return [
            'available' => true,
            'total' => (int) ($row->total ?? 0),
            'distance_m' => (int) ($row->distance_m ?? 0),
            'moving_time_s' => (int) ($row->moving_time_s ?? 0),
            'this_month' => $thisMonth,
        ];
    }

    /**
     * Sorties officielles du club.
     *
     * Le compte des sorties à venir ne tient pas compte des brouillons : un
     * membre verrait « 3 sorties prévues » sans en trouver que deux, et ne
     * comprendrait pas où est passée la troisième.
     *
     * @return array<string, mixed>
     */
    private function eventStats(Request $request): array
    {
        $upcoming = Event::query()->upcoming()->count();

        $nextEvent = Event::query()
            ->upcoming()
            ->orderBy('starts_at')
            ->first(['uuid', 'title', 'starts_at', 'location_name']);

        $myUpcoming = null;
        $member = $request->user()->member;

        if ($member !== null) {
            $myUpcoming = EventParticipant::query()
                ->where('member_id', $member->id)
                ->where('registration_status', RegistrationStatus::Registered)
                ->whereHas('event', fn ($q) => $q
                    ->where('starts_at', '>=', now())
                    ->whereIn('status', [EventStatus::Published, EventStatus::Ongoing]))
                ->count();
        }

        return [
            'available' => true,
            'upcoming' => $upcoming,
            'my_upcoming' => $myUpcoming,
            'next' => $nextEvent === null ? null : [
                'uuid' => $nextEvent->uuid,
                'title' => $nextEvent->title,
                'starts_at' => $nextEvent->starts_at?->toIso8601String(),
                'location_name' => $nextEvent->location_name,
            ],
        ];
    }

    /**
     * Reste à collecter.
     *
     * Réservé aux collecteurs et au-dessus : un membre n'a pas à voir la
     * trésorerie du club depuis son tableau de bord. Sous ce rôle, le bloc
     * répond `visible: false` — et non un zéro, qui laisserait croire que le
     * club n'attend rien.
     *
     * @return array<string, mixed>
     */
    private function participationStats(Request $request): array
    {
        if (! $request->user()->role->canCollect()) {
            return ['visible' => false];
        }

        $row = ParticipationMember::query()
            ->where('status', '!=', ParticipationMemberStatus::Cancelled)
            ->whereHas('participation', fn ($q) => $q->where('status', ParticipationStatus::Open))
            // `lines` est un mot réservé de MariaDB : l'alias doit être
            // autre chose, sans quoi la requête ne se prépare même pas.
            ->selectRaw('
                COUNT(*) as line_count,
                COALESCE(SUM(expected_amount), 0) as expected,
                COALESCE(SUM(paid_amount), 0) as collected
            ')
            ->first();

        $expected = (int) ($row->expected ?? 0);
        $collected = (int) ($row->collected ?? 0);

        return [
            'available' => true,
            'visible' => true,
            'open_campaigns' => Participation::query()
                ->where('status', ParticipationStatus::Open)
                ->count(),
            // Entiers de FCFA, comme partout où il est question d'argent.
            'expected_amount' => $expected,
            'collected_amount' => $collected,
            'remaining_amount' => max(0, $expected - $collected),
            'lines' => (int) ($row->line_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberStats(): array
    {
        // Un seul balayage de la table pour tous les comptages : sur 250
        // membres c'est indifférent, mais une requête par statut deviendrait
        // visible dès que le club grandit, et le tableau de bord est la page
        // la plus consultée.
        $counts = Member::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];
        foreach (MemberStatus::cases() as $status) {
            $byStatus[$status->value] = [
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        $byRole = [];
        $roleCounts = Member::query()
            ->join('users', 'users.id', '=', 'members.user_id')
            ->whereNull('members.deleted_at')
            ->selectRaw('users.role, COUNT(*) as total')
            ->groupBy('users.role')
            ->pluck('total', 'role');

        foreach (UserRole::cases() as $role) {
            $byRole[$role->value] = [
                'label' => $role->label(),
                'count' => (int) ($roleCounts[$role->value] ?? 0),
            ];
        }

        $total = Member::count();
        $withAccount = Member::whereNotNull('user_id')->count();

        return [
            'total' => $total,
            'active' => (int) ($counts[MemberStatus::Active->value] ?? 0),
            'by_status' => $byStatus,
            'by_role' => $byRole,

            // Les adhérents sans smartphone : une réalité du club, pas une
            // anomalie. Le bureau doit savoir combien de QR Codes imprimer.
            'with_account' => $withAccount,
            'without_account' => $total - $withAccount,

            'joined_this_month' => Member::where('joined_at', '>=', now()->startOfMonth())->count(),
            'growth' => $this->growth(),
        ];
    }

    /**
     * Adhésions des douze derniers mois.
     *
     * Les mois sans adhésion sont explicitement à zéro : une courbe qui
     * sauterait les mois creux donnerait une impression de croissance continue
     * qui serait fausse.
     *
     * @return list<array{month: string, label: string, count: int}>
     */
    private function growth(): array
    {
        $start = now()->startOfMonth()->subMonths(self::GROWTH_MONTHS - 1);

        // Regroupement fait en PHP plutôt qu'en SQL : `DATE_FORMAT` est propre
        // à MySQL, `strftime` à SQLite, `to_char` à PostgreSQL. Le volume est
        // borné par les adhésions des douze derniers mois — quelques centaines
        // de lignes au plus — donc le gain d'un GROUP BY serait nul, et on
        // garde une requête qui fonctionne partout.
        $rows = Member::query()
            ->where('joined_at', '>=', $start->toDateString())
            ->pluck('joined_at')
            ->countBy(fn (Carbon $date) => $date->format('Y-m'));

        $series = [];

        for ($i = 0; $i < self::GROWTH_MONTHS; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'label' => $this->monthLabel($month),
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }

    /** « sept. 26 » — court, pour tenir sous une colonne de graphique. */
    private function monthLabel(Carbon $month): string
    {
        $names = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
            'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return $names[$month->month - 1].' '.$month->format('y');
    }

    /**
     * Situation de la caisse.
     *
     * Renvoyée seulement au trésorier et au-dessus — sauf si le club a choisi
     * la transparence (`settings.public_balance`). Le module arrive en phase 13 ;
     * en attendant, on n'invente aucun montant.
     *
     * @return array<string, mixed>
     */
    private function financeStats(Request $request): array
    {
        $user = $request->user();
        $public = (bool) config('cyclo.finance.public_balance');

        if (! $public && ! $user->role->canManageFinance()) {
            return ['visible' => false];
        }

        return $this->pending(13) + ['visible' => true];
    }

    /**
     * @return array{available: false, phase: int}
     */
    private function pending(int $phase): array
    {
        return ['available' => false, 'phase' => $phase];
    }
}
