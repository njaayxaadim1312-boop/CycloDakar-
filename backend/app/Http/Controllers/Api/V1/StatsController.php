<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::ok([
            'members' => $this->memberStats(),

            // Modules à venir. `null` et non 0 : voir le commentaire de classe.
            'activities' => $this->pending(8),
            'events' => $this->pending(9),
            'participations' => $this->pending(10),
            'finance' => $this->financeStats($request),

            'generated_at' => now()->toIso8601String(),
        ]);
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
