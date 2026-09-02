<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChallengeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Community\StoreChallengeRequest;
use App\Http\Resources\ChallengeResource;
use App\Models\Challenge;
use App\Services\Community\ChallengeService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les défis du club.
 *
 * Créer relève du **chef de groupe** : un défi est un acte d'animation
 * sportive, pas une opération financière. Participer est ouvert à tout membre,
 * sans validation — un défi qu'il faut demander à rejoindre n'est plus un défi.
 *
 * Les objectifs et les progressions circulent en **unité SI** : mètres,
 * secondes, nombre de sorties. La conversion est affaire d'affichage.
 */
final class ChallengeController extends Controller
{
    public function __construct(
        private readonly ChallengeService $challenges,
    ) {}

    /**
     * La liste des défis.
     *
     * Par défaut, **ceux qui sont en cours** : c'est ce qu'un membre vient
     * chercher. Les défis terminés restent à un filtre près — ils portent les
     * badges de ceux qui les ont réussis, et ne doivent pas disparaître.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Challenge::class);

        $filtres = $request->validate([
            'scope' => ['nullable', Rule::in(['running', 'upcoming', 'past', 'all'])],
        ]);

        $query = Challenge::query()
            ->visibleTo($request->user())
            ->with(['creator', 'participants'])
            // L'échéance la plus proche d'abord : c'est le défi qui demande
            // qu'on s'y mette maintenant.
            ->orderBy('ends_on');

        match ($filtres['scope'] ?? 'running') {
            'upcoming' => $query->whereDate('starts_on', '>', now()),
            'past' => $query->whereDate('ends_on', '<', now())->reorder('ends_on', 'desc'),
            'all' => null,
            default => $query
                ->whereDate('starts_on', '<=', now())
                ->whereDate('ends_on', '>=', now())
                ->where('status', ChallengeStatus::Published),
        };

        return ApiResponse::ok(ChallengeResource::collection($query->get()));
    }

    public function show(Request $request, Challenge $challenge): JsonResponse
    {
        $this->authorize('view', $challenge);

        return ApiResponse::resource(new ChallengeResource(
            $challenge->load(['creator', 'participants.member']),
        ));
    }

    public function store(StoreChallengeRequest $request): JsonResponse
    {
        $challenge = Challenge::create($request->validated() + [
            // `created_by` vient de la SESSION, jamais du corps de la requête.
            'created_by' => $request->user()->id,
            'status' => $request->validated('status') ?? ChallengeStatus::Draft->value,
        ]);

        return ApiResponse::resource(
            new ChallengeResource($challenge->load(['creator', 'participants'])),
            status: 201,
        );
    }

    public function update(StoreChallengeRequest $request, Challenge $challenge): JsonResponse
    {
        $challenge->update($request->validated());

        // L'objectif a pu changer : les progressions et les badges se
        // recalculent, sinon un défi ramené de 500 à 300 km laisserait ses
        // participants juste en dessous de la barre.
        $this->challenges->refreshAll($challenge->fresh()->load('participants'));

        return ApiResponse::resource(new ChallengeResource(
            $challenge->fresh()->load(['creator', 'participants']),
        ));
    }

    public function destroy(Request $request, Challenge $challenge): JsonResponse
    {
        $this->authorize('delete', $challenge);

        // Suppression douce : un défi auquel des membres ont participé fait
        // partie de leur histoire, et l'effacer ferait disparaître des badges
        // que des gens avaient gagnés.
        $challenge->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Rejoindre un défi.
     *
     * La progression est calculée **immédiatement**, sur toute la fenêtre du
     * défi : un membre qui roulait déjà voit sa barre remplie à l'inscription.
     * Repartir de zéro le pénaliserait d'avoir ouvert l'application plus tard.
     */
    public function join(Request $request, Challenge $challenge): JsonResponse
    {
        $this->authorize('join', $challenge);

        try {
            $this->challenges->join($challenge, $request->user()->member);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422, code: 'JOIN_REFUSED');
        }

        return ApiResponse::resource(new ChallengeResource(
            $challenge->fresh()->load(['creator', 'participants']),
        ));
    }

    /** Quitter un défi — sauf s'il est déjà réussi : le badge reste acquis. */
    public function leave(Request $request, Challenge $challenge): JsonResponse
    {
        $this->authorize('join', $challenge);

        try {
            $this->challenges->leave($challenge, $request->user()->member);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422, code: 'LEAVE_REFUSED');
        }

        return ApiResponse::resource(new ChallengeResource(
            $challenge->fresh()->load(['creator', 'participants']),
        ));
    }

    /**
     * Le classement d'un défi.
     *
     * Les participants triés par progression. Ceux qui ont réussi passent
     * devant, à leur date de réussite : un défi n'est pas un classement à la
     * performance mais un objectif, et celui qui l'a atteint le premier mérite
     * de se voir avant celui qui l'a atteint après, quel que soit son total.
     */
    public function standings(Request $request, Challenge $challenge): JsonResponse
    {
        $this->authorize('view', $challenge);

        $participants = $challenge->participants()
            ->with('member')
            ->get()
            /*
             | Un comparateur UNIQUE, et non une liste de tris successifs.
             |
             | `sortBy` avec un tableau attend des couples [colonne, sens] ; lui
             | passer des comparateurs semblait marcher et ne triait en réalité
             | que sur le dernier — le test a montré le second finisseur devant
             | le premier.
             |
             | L'ordre : les finisseurs d'abord, dans l'ordre où ils ont fini ;
             | puis les autres, par progression décroissante. À égalité de
             | seconde — deux membres peuvent finir dans la même — c'est l'ordre
             | d'inscription qui départage, ce qui reste déterministe.
             */
            ->sort(function ($a, $b) {
                $aFini = $a->completed_at !== null;
                $bFini = $b->completed_at !== null;

                if ($aFini !== $bFini) {
                    return $aFini ? -1 : 1;
                }

                if ($aFini) {
                    return [$a->completed_at->timestamp, $a->id]
                        <=> [$b->completed_at->timestamp, $b->id];
                }

                return [$b->progress, $a->id] <=> [$a->progress, $b->id];
            })
            ->values()
            ->map(fn ($ligne, $index) => [
                'rank' => $index + 1,
                'member' => [
                    'uuid' => $ligne->member->uuid,
                    'full_name' => $ligne->member->fullName(),
                    'initials' => $ligne->member->initials(),
                    'photo_url' => $ligne->member->photoUrl(),
                ],
                'progress' => (int) $ligne->progress,
                'percent' => $ligne->percent((int) $challenge->target),
                'completed_at' => $ligne->completed_at?->toIso8601String(),
            ])
            ->all();

        return ApiResponse::ok($participants, meta: [
            'target' => (int) $challenge->target,
            'unit' => $challenge->metric->unit(),
            'participants' => count($participants),
        ]);
    }

    /**
     * Les défis réussis d'un membre — ses badges.
     *
     * Un badge, ici, n'est pas une invention : c'est un défi réel, avec ses
     * règles, sa période et sa date de réussite. Créer une taxonomie de badges
     * détachée des défis aurait demandé d'inventer des récompenses que le club
     * n'a pas demandées.
     */
    public function badges(Request $request): JsonResponse
    {
        $membre = $request->user()->member;

        if ($membre === null) {
            return ApiResponse::ok([]);
        }

        $badges = \App\Models\ChallengeMember::query()
            ->where('member_id', $membre->id)
            ->whereNotNull('completed_at')
            ->with('challenge')
            ->orderByDesc('completed_at')
            ->get()
            ->filter(fn ($ligne) => $ligne->challenge !== null)
            ->map(fn ($ligne) => [
                'challenge' => [
                    'uuid' => $ligne->challenge->uuid,
                    'title' => $ligne->challenge->title,
                    'icon' => $ligne->challenge->icon,
                    'metric' => $ligne->challenge->metric->value,
                    'target' => (int) $ligne->challenge->target,
                    'unit' => $ligne->challenge->metric->unit(),
                ],
                'completed_at' => $ligne->completed_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return ApiResponse::ok($badges);
    }
}
