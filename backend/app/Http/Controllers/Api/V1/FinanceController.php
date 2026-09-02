<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreIncomeRequest;
use App\Http\Resources\TransactionResource;
use App\Models\CashAccount;
use App\Models\Event;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\ParticipationMember;
use App\Models\Payment;
use App\Models\TransactionCategory;
use App\Services\AuditLogger;
use App\Services\Finance\CashLedger;
use App\Services\Finance\FinancialReport;
use App\Services\Finance\ReportExporter;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contrôle des encaissements.
 *
 * LE RAPPORT « COLLECTES PAR COLLECTEUR » N'EST PAS UNE STATISTIQUE.
 *
 * C'est le contrôle prévu par `docs/finance.md` §6 contre le risque F7 — un
 * collecteur qui encaisse et garde. Dans un club qui collecte en espèces sur
 * le bord de la route, c'est le risque numéro un, et il ne se traite pas par
 * la confiance : il se traite en rendant visible, chaque semaine, qui a
 * encaissé combien et combien d'opérations ont été annulées.
 *
 * Les annulations sont comptées **à part et bien en vue**. Un collecteur dont
 * les annulations sortent du lot n'est pas nécessairement malhonnête — il peut
 * être mal formé, ou avoir un téléphone qui renvoie deux fois — mais c'est
 * exactement le genre d'écart qu'il faut voir tôt.
 *
 * Depuis la phase 13, ce contrôleur porte aussi le tableau de bord de caisse,
 * le journal du grand livre et la saisie des recettes manuelles. Les rapports
 * exportables arrivent en phase 14.
 */
final class FinanceController extends Controller
{
    public function __construct(
        private readonly CashLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ce que chaque collecteur a encaissé sur une période.
     *
     * Par défaut : les trente derniers jours. C'est l'horizon d'un point
     * hebdomadaire de bureau ; « depuis toujours » noierait l'anomalie
     * récente dans la masse.
     */
    public function collections(Request $request): JsonResponse
    {
        $this->authorize('viewBalance', Payment::class);

        $filtres = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $du = isset($filtres['from'])
            ? Carbon::parse($filtres['from'])->toDateString()
            : now()->subDays(30)->toDateString();

        $au = isset($filtres['to'])
            ? Carbon::parse($filtres['to'])->toDateString()
            : now()->toDateString();

        // Une seule requête, groupée en base : agréger en PHP obligerait à
        // charger tous les paiements de la période, ce qui finirait par
        // s'écrouler sur une année complète.
        $lignes = Payment::query()
            ->join('users', 'users.id', '=', 'payments.collected_by')
            ->whereBetween('payments.paid_on', [$du, $au])
            ->groupBy('users.id', 'users.uuid', 'users.name')
            ->orderByDesc('collected_amount')
            ->get([
                'users.uuid as collector_uuid',
                'users.name as collector_name',
                // Les annulés sont exclus du montant encaissé et comptés à
                // part : les mélanger masquerait précisément ce qu'on cherche.
                DB::raw('SUM(CASE WHEN payments.cancelled_at IS NULL THEN payments.amount ELSE 0 END) as collected_amount'),
                DB::raw('SUM(CASE WHEN payments.cancelled_at IS NULL THEN 1 ELSE 0 END) as collected_count'),
                DB::raw('SUM(CASE WHEN payments.cancelled_at IS NOT NULL THEN payments.amount ELSE 0 END) as cancelled_amount'),
                DB::raw('SUM(CASE WHEN payments.cancelled_at IS NOT NULL THEN 1 ELSE 0 END) as cancelled_count'),
            ]);

        $collecteurs = $lignes->map(fn ($ligne) => [
            'collector' => [
                'uuid' => $ligne->collector_uuid,
                'name' => $ligne->collector_name,
            ],
            // Entiers de FCFA. Les agrégats SQL reviennent en chaîne selon le
            // pilote : le transtypage explicite évite qu'un « 15000 » textuel
            // se retrouve concaténé côté client.
            'collected_amount' => (int) $ligne->collected_amount,
            'collected_count' => (int) $ligne->collected_count,
            'cancelled_amount' => (int) $ligne->cancelled_amount,
            'cancelled_count' => (int) $ligne->cancelled_count,
        ])->all();

        return ApiResponse::ok($collecteurs, meta: [
            'from' => $du,
            'to' => $au,
            'total_amount' => array_sum(array_column($collecteurs, 'collected_amount')),
            'total_count' => array_sum(array_column($collecteurs, 'collected_count')),
            'cancelled_amount' => array_sum(array_column($collecteurs, 'cancelled_amount')),
            'cancelled_count' => array_sum(array_column($collecteurs, 'cancelled_count')),
        ]);
    }

    /**
     * L'état de la caisse.
     *
     * DEUX SOLDES, ET LE SECOND N'EST PAS DÉCORATIF.
     *
     * `balance` est le cache lu sur la caisse ; `derived_balance` est le même
     * solde recalculé depuis le grand livre. Les exposer tous les deux permet
     * au trésorier de voir un écart sans attendre la vérification nocturne —
     * et un écart, ici, signifie qu'une écriture est passée hors du seul
     * chemin autorisé.
     *
     * L'ENGAGÉ EST MONTRÉ À PART, ET C'EST LA RÈGLE I4.
     *
     * Une dépense en attente n'a aucune ligne au grand livre : ce n'est pas de
     * l'argent sorti, c'est une intention. La confondre avec le solde ferait
     * décider le trésorier sur un chiffre faux — dans un sens ou dans l'autre.
     * Il voit donc trois nombres : ce qu'il a, ce qui est engagé, et ce qui
     * restera si tout est approuvé.
     */
    public function cash(Request $request): JsonResponse
    {
        $this->authorize('viewBalance', Payment::class);

        $caisse = CashAccount::default();

        $engage = (int) Expense::query()->pending()->sum('amount');
        $solde = $caisse->current_balance;

        return ApiResponse::ok([
            'name' => $caisse->name,
            'opening_balance' => $caisse->opening_balance,
            'balance' => $solde,
            'derived_balance' => $caisse->derivedBalance(),

            // Règle I4 : informatif, JAMAIS déduit du solde en base.
            'committed' => $engage,
            'balance_after_commitments' => $solde - $engage,
            'pending_expenses' => Expense::query()->pending()->count(),

            // Le module est complet depuis la phase 13 : recettes et dépenses
            // passent toutes par le grand livre.
            'complete' => true,
            'incomplete_reason' => null,
        ]);
    }

    /**
     * Tableau de bord de la caisse.
     *
     * Ce que le bureau regarde en réunion, sur une période. Tout est
     * **calculé** depuis le grand livre — aucun total n'est stocké : deux
     * chiffres qui se contrediraient un jour, c'est la confiance perdue.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('viewBalance', Payment::class);

        $filtres = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $du = isset($filtres['from'])
            ? Carbon::parse($filtres['from'])->toDateString()
            // Le mois en cours : c'est la maille d'un point de bureau.
            : now()->startOfMonth()->toDateString();

        $au = isset($filtres['to'])
            ? Carbon::parse($filtres['to'])->toDateString()
            : now()->toDateString();

        $caisse = CashAccount::default();

        $periode = FinancialTransaction::query()
            ->where('cash_account_id', $caisse->id)
            ->whereBetween('occurred_on', [$du, $au]);

        $recettes = (int) (clone $periode)
            ->where('direction', TransactionDirection::In)->sum('amount');
        $depenses = (int) (clone $periode)
            ->where('direction', TransactionDirection::Out)->sum('amount');

        // Ventilation par poste, dans les deux sens. Groupée en base : agréger
        // en PHP obligerait à charger toutes les écritures de la période.
        $parCategorie = (clone $periode)
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
            ->get([
                'financial_transactions.direction',
                'transaction_categories.code',
                'transaction_categories.name',
                DB::raw('SUM(financial_transactions.amount) as total'),
                DB::raw('COUNT(*) as operations'),
            ])
            ->map(fn ($ligne) => [
                'direction' => $ligne->direction instanceof TransactionDirection
                    ? $ligne->direction->value
                    : (string) $ligne->direction,
                'code' => $ligne->code ?? 'SANS_POSTE',
                'name' => $ligne->name ?? 'Sans poste',
                'amount' => (int) $ligne->total,
                'operations' => (int) $ligne->operations,
            ])
            ->values()
            ->all();

        $engage = (int) Expense::query()->pending()->sum('amount');

        return ApiResponse::ok([
            'balance' => $caisse->current_balance,
            'committed' => $engage,
            'balance_after_commitments' => $caisse->current_balance - $engage,

            'income' => $recettes,
            'expenses' => $depenses,
            'net' => $recettes - $depenses,

            'by_category' => $parCategorie,

            // Ce qui reste à percevoir sur les collectes ouvertes. Ce n'est PAS
            // de l'argent en caisse, et le champ porte un autre nom pour qu'on
            // ne soit jamais tenté de l'y ajouter.
            'receivable' => (int) ParticipationMember::query()
                ->whereIn('status', ['NON_PAYE', 'PARTIELLEMENT_PAYE'])
                ->whereHas('participation', fn ($q) => $q->where('status', 'OPEN'))
                ->sum(DB::raw('expected_amount - paid_amount')),
        ], meta: ['from' => $du, 'to' => $au]);
    }

    /**
     * Le journal de caisse.
     *
     * Trié par date métier puis par identifiant, comme il s'imprime. Attention
     * à la lecture de la colonne « Solde » : `balance_after` suit l'ordre
     * d'ENREGISTREMENT, si bien qu'une saisie antidatée la rend non monotone.
     * Ce n'est pas un défaut à masquer — voir `docs/finance.md` §2.
     */
    public function transactions(Request $request): JsonResponse
    {
        $this->authorize('viewBalance', Payment::class);

        $filtres = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'direction' => ['nullable', Rule::in(TransactionDirection::values())],
            'category' => ['nullable', 'string', 'exists:transaction_categories,code'],
            'event' => ['nullable', 'uuid', 'exists:events,uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = FinancialTransaction::query()
            ->where('cash_account_id', CashAccount::default()->id)
            ->with(['category', 'event', 'author', 'reverses'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id');

        if (isset($filtres['from'])) {
            $query->whereDate('occurred_on', '>=', $filtres['from']);
        }

        if (isset($filtres['to'])) {
            $query->whereDate('occurred_on', '<=', $filtres['to']);
        }

        if (isset($filtres['direction'])) {
            $query->where('direction', $filtres['direction']);
        }

        if (isset($filtres['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('code', $filtres['category']));
        }

        if (isset($filtres['event'])) {
            $query->where('event_id', Event::where('uuid', $filtres['event'])->value('id'));
        }

        $ecritures = $query->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::paginated($ecritures, TransactionResource::class);
    }

    /**
     * Recette manuelle : don, sponsoring, vente de maillots.
     *
     * Elle entre DIRECTEMENT au grand livre, sans circuit de validation —
     * contrairement aux dépenses. L'asymétrie est voulue : de l'argent qui
     * entre ne peut pas appauvrir le club, et exiger un double regard pour
     * enregistrer un don ferait perdre la trace du don.
     */
    public function storeIncome(StoreIncomeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $categorie = TransactionCategory::byCode($data['category']);

        if ($categorie === null || $categorie->direction !== TransactionDirection::In) {
            return ApiResponse::error(
                message: "Ce poste n'est pas un poste de recette.",
                status: 422,
                code: 'INVALID_CATEGORY',
            );
        }

        $ecriture = DB::transaction(fn () => $this->ledger->record(
            account: CashAccount::default(),
            direction: TransactionDirection::In,
            amount: (int) $data['amount'],
            label: $data['label'],
            sourceType: TransactionSource::Manual,
            category: $categorie,
            eventId: isset($data['event'])
                ? Event::where('uuid', $data['event'])->value('id')
                : null,
            occurredOn: $data['occurred_on'] ?? now()->toDateString(),
        ));

        $this->audit->log(
            action: 'income.created',
            entity: $ecriture,
            new: [
                'amount' => (int) $data['amount'],
                'label' => $data['label'],
                'category' => $categorie->code,
            ],
        );

        return ApiResponse::resource(
            new TransactionResource($ecriture->load(['category', 'event', 'author'])),
            status: 201,
        );
    }

    /**
     * Rapport financier d'une période, dans le format demandé.
     *
     * QUATRE FORMATS, QUATRE USAGES — ce n'est pas de la redondance.
     *
     * `json` alimente l'écran. `pdf` est la pièce qu'on signe et qu'on
     * distribue en assemblée : elle ne se retouche pas. `xlsx` se retravaille —
     * le trésorier y ajoute une colonne, trie, refait ses totaux. `csv`
     * s'importe ailleurs, et c'est le format qu'on regrette de ne pas avoir le
     * jour où il faut sortir des données d'une application.
     *
     * LA PÉRIODE EST BORNÉE À DEUX ANS, ET C'EST DÉLIBÉRÉ.
     *
     * Un rapport « depuis toujours » se génère en mémoire, ligne par ligne, et
     * finirait par faire tomber la requête au moment précis où l'on en a le
     * plus besoin — la veille d'une assemblée. `docs/finance.md` prévoit une
     * génération asynchrone avec notification de disponibilité : elle attend
     * la phase 17, qui livre les notifications. D'ici là, mieux vaut une borne
     * claire qu'un échec obscur.
     */
    public function reports(
        Request $request,
        FinancialReport $rapports,
        ReportExporter $exporteur,
    ): mixed {
        $this->authorize('viewBalance', Payment::class);

        $filtres = $request->validate([
            'period' => ['nullable', Rule::in(['day', 'week', 'month', 'year', 'custom'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'pdf', 'xlsx', 'csv'])],
        ]);

        [$du, $au] = FinancialReport::resolvePeriod(
            $filtres['period'] ?? 'month',
            $filtres['from'] ?? null,
            $filtres['to'] ?? null,
        );

        if (Carbon::parse($du)->diffInDays(Carbon::parse($au)) > 800) {
            return ApiResponse::error(
                message: 'La période demandée dépasse deux ans. Découpez-la : un rapport '
                    .'trop large ne se génère pas de façon fiable.',
                status: 422,
                code: 'PERIOD_TOO_WIDE',
            );
        }

        $rapport = $rapports->build($du, $au);

        return match ($filtres['format'] ?? 'json') {
            'pdf' => $exporteur->pdf($rapport),
            'xlsx' => $exporteur->xlsx($rapport),
            'csv' => $exporteur->csv($rapport),
            default => ApiResponse::ok($rapport),
        };
    }

    /** Les postes du grand livre, pour alimenter les listes déroulantes. */
    public function categories(Request $request): JsonResponse
    {
        $this->authorize('viewBalance', Payment::class);

        $postes = TransactionCategory::query()
            ->where('is_active', true)
            ->orderBy('direction')
            ->orderBy('position')
            ->get(['code', 'name', 'direction']);

        return ApiResponse::ok($postes->map(fn (TransactionCategory $poste) => [
            'code' => $poste->code,
            'name' => $poste->name,
            'direction' => $poste->direction->value,
        ])->all());
    }
}
