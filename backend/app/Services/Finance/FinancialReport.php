<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ExpenseStatus;
use App\Enums\ParticipationMemberStatus;
use App\Enums\TransactionDirection;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\ParticipationMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Le rapport financier d'une période.
 *
 * TOUT EST CALCULÉ, RIEN N'EST STOCKÉ.
 *
 * Un rapport qui conserverait ses totaux finirait par contredire le grand
 * livre — après une contre-passation, par exemple — et deux chiffres qui se
 * contredisent, sur de l'argent, c'est la confiance du bureau perdue. Elle ne
 * revient pas.
 *
 * LE SOLDE D'OUVERTURE EST CELUI DE LA DATE MÉTIER, PAS DE LA SAISIE.
 *
 * On additionne tout ce qui s'est passé AVANT le premier jour de la période,
 * quelle que soit la date à laquelle c'est entré dans le système. C'est la
 * seule définition qui rende un rapport de septembre stable : le ressortir en
 * décembre doit donner le même chiffre, même si une opération de septembre a
 * été saisie en octobre.
 *
 * C'est aussi pourquoi on ne lit PAS `balance_after` ici, alors que le journal
 * de caisse, lui, le fait : cette colonne suit l'ordre d'enregistrement (voir
 * `docs/finance.md` §2), et elle répondrait à une autre question.
 */
final class FinancialReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $from, string $to): array
    {
        $debut = Carbon::parse($from)->startOfDay();
        $fin = Carbon::parse($to)->endOfDay();

        $caisse = CashAccount::default();

        $ouverture = $this->balanceBefore($caisse, $debut);

        $periode = fn () => FinancialTransaction::query()
            ->where('cash_account_id', $caisse->id)
            ->whereBetween('occurred_on', [$debut->toDateString(), $fin->toDateString()]);

        $recettes = (int) $periode()->where('direction', TransactionDirection::In)->sum('amount');
        $depenses = (int) $periode()->where('direction', TransactionDirection::Out)->sum('amount');

        return [
            'period' => [
                'from' => $debut->toDateString(),
                'to' => $fin->toDateString(),
                'label' => $this->periodLabel($debut, $fin),
            ],

            'account' => ['name' => $caisse->name],

            'summary' => [
                'opening_balance' => $ouverture,
                'income' => $recettes,
                'expenses' => $depenses,
                'net' => $recettes - $depenses,
                'closing_balance' => $ouverture + $recettes - $depenses,
                // Engagé à la DATE DU RAPPORT, pas à la fin de la période : une
                // dépense en attente n'a pas de date de sortie, elle n'en a pas
                // encore eu lieu. La rattacher à la période donnerait un
                // chiffre qui changerait chaque fois qu'on ressort le rapport.
                'committed_today' => (int) Expense::query()->pending()->sum('amount'),
            ],

            'by_category' => $this->byCategory($caisse, $debut, $fin),
            'participations' => $this->participations(),
            'daily' => $this->daily($caisse, $debut, $fin, $ouverture),
            'entries' => $this->entries($caisse, $debut, $fin),

            'generated_at' => now()->toIso8601String(),
        ];
    }

    /* ---------------------------------------------------------------------- */

    /** Le solde à la veille du premier jour de la période. */
    private function balanceBefore(CashAccount $caisse, Carbon $debut): int
    {
        $anterieures = FinancialTransaction::query()
            ->where('cash_account_id', $caisse->id)
            ->whereDate('occurred_on', '<', $debut->toDateString());

        $entrees = (int) (clone $anterieures)
            ->where('direction', TransactionDirection::In)->sum('amount');
        $sorties = (int) (clone $anterieures)
            ->where('direction', TransactionDirection::Out)->sum('amount');

        return $caisse->opening_balance + $entrees - $sorties;
    }

    /**
     * Ventilation par poste, dans les deux sens.
     *
     * @return array{income: list<array<string, mixed>>, expenses: list<array<string, mixed>>}
     */
    private function byCategory(CashAccount $caisse, Carbon $debut, Carbon $fin): array
    {
        $lignes = FinancialTransaction::query()
            ->where('cash_account_id', $caisse->id)
            ->whereBetween('occurred_on', [$debut->toDateString(), $fin->toDateString()])
            ->leftJoin(
                'transaction_categories',
                'transaction_categories.id',
                '=',
                'financial_transactions.transaction_category_id',
            )
            ->groupBy(
                'transaction_categories.code',
                'transaction_categories.name',
                'financial_transactions.direction',
            )
            ->orderByDesc('total')
            ->get([
                'financial_transactions.direction',
                'transaction_categories.code',
                'transaction_categories.name',
                DB::raw('SUM(financial_transactions.amount) as total'),
                DB::raw('COUNT(*) as operations'),
            ]);

        $ranger = fn (TransactionDirection $sens) => $lignes
            ->filter(function ($ligne) use ($sens) {
                $direction = $ligne->direction instanceof TransactionDirection
                    ? $ligne->direction->value
                    : (string) $ligne->direction;

                return $direction === $sens->value;
            })
            ->map(fn ($ligne) => [
                'code' => $ligne->code ?? 'SANS_POSTE',
                'name' => $ligne->name ?? 'Sans poste',
                'amount' => (int) $ligne->total,
                'operations' => (int) $ligne->operations,
            ])
            ->values()
            ->all();

        return [
            'income' => $ranger(TransactionDirection::In),
            'expenses' => $ranger(TransactionDirection::Out),
        ];
    }

    /**
     * L'état des collectes : attendu, encaissé, restant dû.
     *
     * VOLONTAIREMENT HORS PÉRIODE. Une créance n'appartient pas à un mois :
     * elle existe tant qu'elle n'est pas réglée. La rattacher à la période
     * ferait disparaître d'un rapport annuel des impayés bien réels.
     *
     * @return array<string, int>
     */
    private function participations(): array
    {
        $lignes = ParticipationMember::query()
            ->where('status', '!=', ParticipationMemberStatus::Cancelled)
            ->whereHas('participation', fn ($q) => $q->whereIn('status', ['OPEN', 'CLOSED']));

        $attendu = (int) (clone $lignes)->sum('expected_amount');
        $encaisse = (int) (clone $lignes)->sum('paid_amount');

        return [
            'expected' => $attendu,
            'collected' => $encaisse,
            'remaining' => max(0, $attendu - $encaisse),
        ];
    }

    /**
     * L'évolution du solde, jour par jour.
     *
     * Seuls les jours où quelque chose s'est passé apparaissent : une courbe
     * qui répète cent fois le même point ne dit rien de plus et alourdit un
     * export d'autant.
     *
     * @return list<array<string, mixed>>
     */
    private function daily(CashAccount $caisse, Carbon $debut, Carbon $fin, int $ouverture): array
    {
        $jours = FinancialTransaction::query()
            ->where('cash_account_id', $caisse->id)
            ->whereBetween('occurred_on', [$debut->toDateString(), $fin->toDateString()])
            ->groupBy('occurred_on')
            ->orderBy('occurred_on')
            ->get([
                'occurred_on',
                DB::raw("SUM(CASE WHEN direction = 'IN' THEN amount ELSE 0 END) as entrees"),
                DB::raw("SUM(CASE WHEN direction = 'OUT' THEN amount ELSE 0 END) as sorties"),
            ]);

        $solde = $ouverture;
        $courbe = [];

        foreach ($jours as $jour) {
            $entrees = (int) $jour->entrees;
            $sorties = (int) $jour->sorties;
            $solde += $entrees - $sorties;

            $courbe[] = [
                'date' => Carbon::parse((string) $jour->occurred_on)->toDateString(),
                'income' => $entrees,
                'expenses' => $sorties,
                'balance' => $solde,
            ];
        }

        return $courbe;
    }

    /**
     * Les opérations de la période, dans l'ordre où elles se lisent.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(CashAccount $caisse, Carbon $debut, Carbon $fin): array
    {
        return FinancialTransaction::query()
            ->where('cash_account_id', $caisse->id)
            ->whereBetween('occurred_on', [$debut->toDateString(), $fin->toDateString()])
            ->with(['category', 'author'])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->map(fn (FinancialTransaction $ecriture) => [
                'date' => $ecriture->occurred_on->toDateString(),
                'label' => $ecriture->label,
                'category' => $ecriture->category?->name ?? 'Sans poste',
                'direction' => $ecriture->direction->value,
                'income' => $ecriture->direction === TransactionDirection::In
                    ? (int) $ecriture->amount
                    : 0,
                'expense' => $ecriture->direction === TransactionDirection::Out
                    ? (int) $ecriture->amount
                    : 0,
                'balance_after' => (int) $ecriture->balance_after,
                'author' => $ecriture->author?->name ?? '—',
                'reason' => $ecriture->reason,
            ])
            ->values()
            ->all();
    }

    /** « Septembre 2026 », « du 1er au 15 septembre 2026 »… */
    private function periodLabel(Carbon $debut, Carbon $fin): string
    {
        $debut = $debut->locale('fr');
        $fin = $fin->locale('fr');

        if ($debut->isSameDay($fin)) {
            return $debut->translatedFormat('j F Y');
        }

        if ($debut->isSameMonth($fin) && $debut->day === 1 && $fin->isLastOfMonth()) {
            return ucfirst($debut->translatedFormat('F Y'));
        }

        if ($debut->isSameYear($fin) && $debut->dayOfYear === 1 && $fin->dayOfYear === $fin->daysInYear) {
            return 'Année '.$debut->year;
        }

        return 'Du '.$debut->translatedFormat('j F Y').' au '.$fin->translatedFormat('j F Y');
    }

    /**
     * Résout une période nommée en deux dates.
     *
     * @return array{0: string, 1: string}
     */
    public static function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        $aujourdhui = now();

        return match ($period) {
            'day' => [$aujourdhui->toDateString(), $aujourdhui->toDateString()],
            // La semaine commence le lundi : c'est la convention au Sénégal
            // comme en France, et un rapport hebdomadaire qui commencerait le
            // dimanche couperait la sortie du dimanche matin en deux.
            'week' => [
                $aujourdhui->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $aujourdhui->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            'month' => [
                $aujourdhui->copy()->startOfMonth()->toDateString(),
                $aujourdhui->copy()->endOfMonth()->toDateString(),
            ],
            'year' => [
                $aujourdhui->copy()->startOfYear()->toDateString(),
                $aujourdhui->copy()->endOfYear()->toDateString(),
            ],
            default => [
                $from ?? $aujourdhui->copy()->startOfMonth()->toDateString(),
                $to ?? $aujourdhui->toDateString(),
            ],
        };
    }
}
