<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChallengeMetric;
use App\Enums\Sport;
use App\Http\Controllers\Controller;
use App\Services\Community\LeaderboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les classements du club.
 *
 * **Une sortie privée ne classe jamais son auteur.** C'est la règle qui
 * gouverne tout ce module, et elle est appliquée dans `LeaderboardService` —
 * en un seul endroit, précisément pour qu'elle ne puisse pas être oubliée dans
 * une variante.
 *
 * Ouvert à tous les membres : savoir qui roule est ce qui fait rouler. Ce n'est
 * pas une donnée sensible — les sorties classées sont, par construction, celles
 * que leurs auteurs ont accepté de partager.
 */
final class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'period' => ['nullable', Rule::in(['week', 'month', 'year'])],
            'metric' => ['nullable', Rule::in(ChallengeMetric::values())],
            'sport' => ['nullable', Rule::in(Sport::values())],
            // Une période passée, sous sa forme lisible : `2026-08`, `2026-W35`.
            'key' => ['nullable', 'string', 'max:12'],
        ]);

        $periode = $filtres['period'] ?? 'month';
        $mesure = ChallengeMetric::from($filtres['metric'] ?? 'distance');

        $resultat = $this->leaderboard->build(
            period: $periode,
            metric: $mesure,
            sport: $filtres['sport'] ?? null,
            viewer: $request->user()->member,
            key: $filtres['key'] ?? null,
        );

        return ApiResponse::ok($resultat['entries'], meta: [
            'period' => $periode,
            'period_key' => $resultat['period_key'],
            'metric' => $mesure->value,
            'metric_label' => $mesure->label(),
            'unit' => $mesure->unit(),
            'sport' => $filtres['sport'] ?? null,

            /*
             | `frozen` dit si ce classement est FIGÉ.
             |
             | Ce n'est pas un détail technique à cacher : un classement figé
             | ne bougera plus, un classement en cours peut encore changer
             | d'ici la fin de la période. Le membre qui regarde a le droit de
             | savoir lequel des deux il a sous les yeux.
             */
            'frozen' => $resultat['frozen'],

            // Le rang du lecteur, même hors du top affiché. Un classement qui
            // ne montre que les vingt premiers dit à tous les autres qu'ils ne
            // comptent pas.
            'me' => $resultat['me'],
        ]);
    }
}
