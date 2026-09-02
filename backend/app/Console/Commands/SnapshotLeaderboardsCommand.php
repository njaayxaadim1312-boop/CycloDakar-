<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChallengeMetric;
use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Services\Community\ChallengeService;
use App\Services\Community\LeaderboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fige les classements des périodes closes, et rafraîchit les défis.
 *
 * POURQUOI FIGER
 *
 * Les sorties bougent après coup : le mobile synchronise en différé, un membre
 * passe une sortie en privé une semaine plus tard, une trace est corrigée.
 * Recalculé, le classement de septembre changerait donc en octobre — après que
 * le club a félicité quelqu'un. Reprendre une première place déjà annoncée est
 * le plus sûr moyen de faire quitter un club.
 *
 * Une période close est un fait, comme une collecte clôturée : on l'arrête, on
 * la garde, on ne la retouche plus.
 *
 * CE QUI EST FIGÉ, ET QUAND
 *
 * La semaine et le mois qui viennent de s'achever, et l'année au 1er janvier.
 * La commande tourne chaque nuit ; elle ne fige jamais une période en cours, et
 * refiger une période déjà figée la réécrit à l'identique — c'est sans effet,
 * mais cela permet de rattraper une synchronisation vraiment tardive en
 * relançant à la main.
 */
final class SnapshotLeaderboardsCommand extends Command
{
    protected $signature = 'cyclo:snapshot-leaderboards
        {--period=* : week, month ou year — toutes par défaut}
        {--key= : Une période précise, par exemple 2026-08}';

    protected $description = 'Fige les classements des périodes closes et met les défis à jour.';

    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly ChallengeService $challenges,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=black;bg=yellow> CYCLO DAKAR </> Classements et défis');
        $this->newLine();

        $periodes = $this->option('period') ?: ['week', 'month', 'year'];
        $lignes = 0;

        foreach ($periodes as $periode) {
            $cle = $this->option('key') ?? $this->previousKey($periode);

            foreach (ChallengeMetric::cases() as $mesure) {
                // Le classement « tous sports » ET un classement par sport :
                // un marcheur n'a rien à faire dans le même tableau qu'un
                // cycliste, et se comparer à plus fort que soi sur la mauvaise
                // échelle décourage au lieu d'entraîner.
                foreach ($this->sports() as $sport) {
                    $lignes += $this->leaderboard->freeze($periode, $cle, $mesure, $sport);
                }
            }

            $this->line("  <fg=green>✔</> {$periode} {$cle} figé.");
        }

        $this->line("     {$lignes} ligne(s) de classement au total.");

        $defis = $this->refreshChallenges();

        $this->line("  <fg=green>✔</> {$defis} défi(s) mis à jour.");
        $this->newLine();

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * La période qui vient de s'achever.
     *
     * On recule d'une unité depuis aujourd'hui : lancée le 1er octobre, la
     * commande fige septembre. Lancée le 15, elle refige septembre à
     * l'identique — sans effet, et c'est voulu : une commande nocturne ne doit
     * pas dépendre du jour où elle tourne pour être correcte.
     */
    private function previousKey(string $periode): string
    {
        $date = match ($periode) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            default => now()->subYear(),
        };

        return LeaderboardService::periodKey($periode, Carbon::parse($date));
    }

    /**
     * Les sports à figer : tous confondus, puis chacun séparément.
     *
     * @return list<string|null>
     */
    private function sports(): array
    {
        return array_merge([null], \App\Enums\Sport::values());
    }

    /**
     * Remet les défis en cours à jour.
     *
     * Les défis TERMINÉS sont rafraîchis une dernière fois eux aussi, mais
     * seulement s'ils viennent de se clore : une synchronisation tardive
     * arrivée après la fin doit encore pouvoir donner son badge à quelqu'un qui
     * l'avait mérité sur le terrain.
     */
    private function refreshChallenges(): int
    {
        $defis = Challenge::query()
            ->where('status', ChallengeStatus::Published)
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now()->subDays(3))
            ->with('participants')
            ->get();

        foreach ($defis as $defi) {
            $this->challenges->refreshAll($defi);
        }

        return $defis->count();
    }
}
