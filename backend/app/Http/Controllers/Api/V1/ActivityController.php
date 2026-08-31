<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\Sport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\StoreActivityRequest;
use App\Http\Requests\Activity\StorePointsRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Services\ActivitySyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Activités sportives.
 *
 * Le cycle complet d'une sortie :
 *
 *   POST /activities                    ouverture (idempotente sur l'uuid)
 *   POST /activities/{uuid}/points      lots de points, rejouables
 *   POST /activities/{uuid}/finalize    recalcul serveur et clôture
 *
 * Tout est pensé pour un réseau qui tombe : chaque étape peut être rejouée
 * sans conséquence. Voir docs/gps.md §12.
 */
final class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivitySyncService $sync,
    ) {}

    /**
     * Ouverture d'une activité.
     *
     * **Idempotent.** Si l'uuid est déjà connu, on renvoie l'activité
     * existante avec un 200 au lieu d'un 201. Le téléphone peut donc rejouer
     * la création après une coupure sans créer de doublon — et sans avoir à
     * distinguer « première tentative » et « nouvelle tentative ».
     */
    public function store(StoreActivityRequest $request): JsonResponse
    {
        $this->authorize('create', Activity::class);

        $member = $request->user()->member;
        $uuid = $request->string('uuid')->toString();

        $existing = Activity::where('uuid', $uuid)->first();

        if ($existing !== null) {
            // L'uuid appartient à quelqu'un d'autre : collision impossible en
            // pratique, mais on ne laisse pas un membre écrire dans la sortie
            // d'un autre pour autant.
            if ($existing->member_id !== $member->id) {
                return ApiResponse::error(
                    message: 'Cet identifiant est déjà utilisé.',
                    status: 409,
                    code: 'UUID_CONFLICT',
                );
            }

            return ApiResponse::ok(new ActivityResource($existing));
        }

        $activity = new Activity;
        $activity->forceFill([
            'uuid' => $uuid,
            'member_id' => $member->id,
            'sport' => Sport::from($request->string('sport')->toString()),
            'title' => $request->input('title'),
            'status' => ActivityStatus::Recording,
            'visibility' => ActivityVisibility::from(
                $request->input('visibility', ActivityVisibility::Club->value),
            ),
            'started_at' => $request->date('started_at'),
            'device_info' => $request->input('device_info'),
        ])->save();

        return ApiResponse::created(new ActivityResource($activity));
    }

    /**
     * Réception d'un lot de points.
     *
     * La réponse porte `last_seq` et `total_points` : c'est ce qui permet au
     * téléphone de savoir ce qui est arrivé et de ne supprimer localement que
     * ce dont il a la confirmation.
     */
    public function storePoints(StorePointsRequest $request, Activity $activity): JsonResponse
    {
        $this->authorize('sync', $activity);

        if (! $activity->status->acceptsPoints()) {
            return ApiResponse::error(
                message: 'Cette activité est terminée et n\'accepte plus de points.',
                status: 409,
                code: 'ACTIVITY_CLOSED',
            );
        }

        $result = $this->sync->ingest(
            $activity,
            $request->array('points'),
            $request->header('X-Device-Id'),
        );

        return ApiResponse::ok($result);
    }

    /**
     * Finalisation : recalcul complet et clôture.
     *
     * Si le client annonce un nombre de points que le serveur n'a pas reçu, on
     * refuse en 409 avec le décompte réel. Finaliser une trace incomplète
     * produirait une distance fausse — et le membre n'aurait aucun moyen de
     * s'en rendre compte.
     */
    public function finalize(Request $request, Activity $activity): JsonResponse
    {
        $this->authorize('sync', $activity);

        $validated = $request->validate([
            'ended_at' => ['nullable', 'date'],
            'expected_points_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $stored = $activity->points()->count();
        $expected = $validated['expected_points_count'] ?? null;

        if ($expected !== null && $stored < $expected) {
            return ApiResponse::error(
                message: sprintf(
                    'Synchronisation incomplète : %d point(s) reçu(s) sur %d annoncé(s).',
                    $stored,
                    $expected,
                ),
                status: 409,
                errors: ['points' => ['Renvoyez les lots manquants avant de finaliser.']],
                code: 'INCOMPLETE_SYNC',
            );
        }

        $activity = $this->sync->finalize(
            $activity,
            isset($validated['ended_at']) ? $request->date('ended_at') : null,
        );

        return ApiResponse::ok(new ActivityResource($activity->load('stats')));
    }

    /**
     * Historique, filtrable.
     *
     * `GET /activities?sport=CYCLING&from=2026-09-01&to=2026-09-30&mine=1`
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        $validated = $request->validate([
            'sport' => ['nullable', Rule::in(Sport::values())],
            'status' => ['nullable', Rule::in(ActivityStatus::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'member' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $viewer = $request->user()->member;

        $query = Activity::query()
            ->with('member')
            ->visibleTo($viewer)
            // Par défaut on ne montre que les sorties terminées : une activité
            // encore en cours n'a pas de statistiques fiables.
            ->where('status', $validated['status'] ?? ActivityStatus::Completed->value);

        if ($request->boolean('mine')) {
            $query->where('member_id', $viewer?->id);
        }

        if (isset($validated['member'])) {
            $query->whereHas('member', fn ($q) => $q->where('uuid', $validated['member']));
        }

        if (isset($validated['sport'])) {
            $query->where('sport', $validated['sport']);
        }

        if (isset($validated['from'])) {
            $query->where('started_at', '>=', $request->date('from')->startOfDay());
        }

        if (isset($validated['to'])) {
            $query->where('started_at', '<=', $request->date('to')->endOfDay());
        }

        $paginator = $query
            ->orderByDesc('started_at')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return ApiResponse::paginated($paginator, ActivityResource::class);
    }

    public function show(Activity $activity): JsonResponse
    {
        $this->authorize('view', $activity);

        return ApiResponse::ok(
            new ActivityResource($activity->load(['member', 'stats'])),
        );
    }

    /**
     * Modification du titre, des notes et de la visibilité.
     *
     * Rien d'autre : les statistiques viennent des points bruts.
     */
    public function update(Request $request, Activity $activity): JsonResponse
    {
        $this->authorize('update', $activity);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:140'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::in(ActivityVisibility::values())],
        ]);

        $activity->fill($validated)->save();

        return ApiResponse::ok(new ActivityResource($activity->fresh(['member', 'stats'])));
    }

    /**
     * Suppression douce.
     *
     * Les points bruts sont conservés : si le membre s'aperçoit d'une erreur,
     * la sortie peut être restaurée. Une suppression définitive détruirait un
     * enregistrement irremplaçable — on ne refait pas une sortie.
     */
    public function destroy(Activity $activity): JsonResponse
    {
        $this->authorize('delete', $activity);

        $activity->delete();

        return ApiResponse::ok(['message' => 'Activité supprimée.']);
    }
}
