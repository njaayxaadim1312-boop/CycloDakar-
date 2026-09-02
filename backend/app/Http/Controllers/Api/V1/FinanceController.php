<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\Payment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * Le journal de caisse complet, les dépenses et les rapports arrivent en
 * PHASE 13 et 14. Ici, on ne montre que ce qui existe réellement.
 */
final class FinanceController extends Controller
{
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
     * DEUX CHIFFRES, ET LE SECOND N'EST PAS DÉCORATIF.
     *
     * `balance` est le cache lu sur la caisse ; `derived_balance` est le même
     * solde recalculé depuis le grand livre. Les exposer tous les deux permet
     * au trésorier de voir un écart sans attendre la vérification nocturne —
     * et un écart, ici, signifie qu'une écriture est passée hors du seul
     * chemin autorisé.
     *
     * ⚠️ **Ce solde ne comprend pas encore les dépenses** : leur saisie arrive
     * en phase 13. Le champ `complete` le dit explicitement, pour qu'aucune
     * interface ne le présente comme le solde réel du club. Confondre « tout
     * ce qui est enregistré » et « tout ce qui existe » est exactement ce qui
     * ruine la confiance d'un bureau.
     */
    public function cash(Request $request): JsonResponse
    {
        $this->authorize('viewBalance', Payment::class);

        $caisse = CashAccount::default();

        return ApiResponse::ok([
            'name' => $caisse->name,
            'opening_balance' => $caisse->opening_balance,
            'balance' => $caisse->current_balance,
            'derived_balance' => $caisse->derivedBalance(),
            'complete' => false,
            'incomplete_reason' => 'Les dépenses ne sont pas encore saisies (phase 13) : '
                .'ce montant ne représente que les encaissements enregistrés.',
        ]);
    }
}
