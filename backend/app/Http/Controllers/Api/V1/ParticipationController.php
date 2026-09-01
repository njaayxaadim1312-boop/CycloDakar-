<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participation\AssignMembersRequest;
use App\Http\Requests\Participation\StoreParticipationRequest;
use App\Http\Requests\Participation\UpdateLineRequest;
use App\Http\Requests\Participation\UpdateParticipationRequest;
use App\Http\Requests\Participation\UpdateParticipationStatusRequest;
use App\Http\Resources\ParticipationLineResource;
use App\Http\Resources\ParticipationResource;
use App\Models\Event;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\User;
use App\Services\ParticipationAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Campagnes de collecte.
 *
 * Réservé aux collecteurs et au-dessus (`ParticipationPolicy`). Un membre
 * verra SA dette dans son espace personnel, avec les encaissements — PHASE 12.
 *
 * Tous les montants entrent et sortent en **entiers de FCFA**. Aucun flottant
 * ne traverse ce contrôleur.
 */
final class ParticipationController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ParticipationAssignmentService $assignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Participation::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ParticipationStatus::values())],
            'scope' => ['nullable', Rule::in(['open', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Participation::query()
            ->visibleTo($request->user())
            ->with(['creator', 'event'])
            // L'échéance la plus proche d'abord : c'est l'ordre dans lequel
            // le bureau doit s'en occuper.
            ->orderBy('due_on');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } elseif (($filters['scope'] ?? 'open') === 'open') {
            // Par défaut, ce qui demande une action. Les collectes closes
            // encombreraient la liste sans rien appeler.
            $query->whereIn('status', [ParticipationStatus::Draft, ParticipationStatus::Open]);
        }

        $participations = $query->paginate($filters['per_page'] ?? self::PER_PAGE);

        return ApiResponse::paginated($participations, ParticipationResource::class);
    }

    public function store(StoreParticipationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // L'événement est désigné par son uuid côté API ; la clé étrangère est
        // interne et ne sort jamais.
        if (isset($data['event_id'])) {
            $data['event_id'] = Event::where('uuid', $data['event_id'])->value('id');
        }

        $participation = Participation::create($data + [
            // `created_by` vient de la SESSION, jamais du corps de la requête.
            'created_by' => $request->user()->id,
            'status' => $data['status'] ?? ParticipationStatus::Draft->value,
        ]);

        return ApiResponse::resource(
            new ParticipationResource($participation->load(['creator', 'event'])),
            status: 201,
        );
    }

    public function show(Request $request, Participation $participation): JsonResponse
    {
        $this->authorize('view', $participation);

        $participation->load([
            'creator',
            'event',
            // Les impayés d'abord : c'est ce qu'un collecteur vient chercher,
            // les lignes soldées ne demandent plus rien. `CASE WHEN` et non
            // `FIELD()`, qui est propre à MySQL — la même prudence que pour
            // `DATE_FORMAT` en phase 4.
            'lines' => fn ($q) => $q
                ->orderByRaw(
                    'CASE status'
                    ." WHEN 'NON_PAYE' THEN 1"
                    ." WHEN 'PARTIELLEMENT_PAYE' THEN 2"
                    ." WHEN 'PAYE' THEN 3"
                    .' ELSE 4 END'
                ),
            'lines.member',
            'lines.collector',
        ]);

        return ApiResponse::resource(new ParticipationResource($participation));
    }

    public function update(UpdateParticipationRequest $request, Participation $participation): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('event_id', $data)) {
            $data['event_id'] = $data['event_id'] === null
                ? null
                : Event::where('uuid', $data['event_id'])->value('id');
        }

        $participation->update($data);

        return ApiResponse::resource(
            new ParticipationResource($participation->fresh()->load(['creator', 'event'])),
        );
    }

    /**
     * Ouverture, clôture, annulation.
     *
     * Route distincte : ce sont des actes soumis à des transitions. Ouvrir une
     * collecte engage le club auprès de ses membres ; la clôturer arrête des
     * comptes.
     */
    public function updateStatus(
        UpdateParticipationStatusRequest $request,
        Participation $participation,
    ): JsonResponse {
        $target = ParticipationStatus::from($request->validated('status'));

        if ($participation->status === $target) {
            return ApiResponse::resource(new ParticipationResource($participation));
        }

        if (! $participation->status->canTransitionTo($target)) {
            return ApiResponse::error(
                message: "Une collecte « {$participation->status->label()} » ne peut pas passer à « {$target->label()} ».",
                status: 422,
                code: 'INVALID_TRANSITION',
            );
        }

        $participation->update(['status' => $target]);

        // PHASE 17 — prévenir les membres concernés à l'ouverture, et leur
        // rappeler l'échéance. Les destinataires sont déjà connus.

        return ApiResponse::resource(
            new ParticipationResource($participation->fresh()->load(['creator', 'event'])),
        );
    }

    public function destroy(Request $request, Participation $participation): JsonResponse
    {
        $this->authorize('delete', $participation);

        $encaisse = $participation->lines()->where('paid_amount', '>', 0)->exists();

        if ($encaisse) {
            // Supprimer laisserait des paiements sans dette : de l'argent
            // encaissé qui ne se rattache plus à rien. On annule, ce qui
            // conserve la trace et reste explicable en assemblée générale.
            return ApiResponse::error(
                message: 'Cette collecte a déjà reçu des paiements : annulez-la au lieu de la supprimer.',
                status: 422,
                code: 'HAS_PAYMENTS',
            );
        }

        $participation->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    /* ---------------------------------------------------------------------- */
    /* Affectation des membres                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * Rattache des membres à la collecte.
     *
     * Sans liste, ce sont **tous les membres actifs** : le geste le plus
     * fréquent, celui d'une cotisation annuelle. On ne demande pas au bureau
     * de cocher 250 cases pour dire « tout le monde ».
     */
    public function assign(AssignMembersRequest $request, Participation $participation): JsonResponse
    {
        $collector = $request->filled('collector')
            ? User::where('uuid', $request->validated('collector'))->first()
            : null;

        try {
            $result = $this->assignments->assign(
                $participation,
                $request->validated('members'),
                $request->validated('amount'),
                $collector,
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422,
                code: 'ASSIGNMENTS_CLOSED',
            );
        }

        return ApiResponse::ok(
            new ParticipationResource($participation->fresh()->load(['creator', 'event'])),
            meta: $result,
        );
    }

    /** Modifie une ligne : montant individualisé, collecteur, dispense. */
    public function updateLine(
        UpdateLineRequest $request,
        Participation $participation,
        ParticipationMember $line,
    ): JsonResponse {
        if ($line->participation_id !== $participation->id) {
            return ApiResponse::error(
                message: "Cette ligne n'appartient pas à cette collecte.",
                status: 404,
                code: 'LINE_NOT_FOUND',
            );
        }

        $data = $request->validated();

        try {
            if (isset($data['expected_amount'])) {
                $this->assignments->setAmount($line, (int) $data['expected_amount']);
            }

            if (array_key_exists('collector', $data)) {
                $this->assignments->setCollector(
                    $line,
                    $data['collector'] === null
                        ? null
                        : User::where('uuid', $data['collector'])->first(),
                );
            }

            if (($data['exempt'] ?? false) === true) {
                $this->assignments->exempt($line, $data['note'] ?? null);
            } elseif (array_key_exists('note', $data)) {
                $line->update(['note' => $data['note']]);
            }
        } catch (RuntimeException $e) {
            return ApiResponse::error(message: $e->getMessage(), status: 422, code: 'INVALID_AMOUNT');
        }

        return ApiResponse::ok(
            new ParticipationLineResource($line->fresh()->load(['member', 'collector'])),
        );
    }

    /** Retire un membre. Conservé en ANNULÉ dès qu'un franc a été encaissé. */
    public function removeLine(
        Request $request,
        Participation $participation,
        ParticipationMember $line,
    ): JsonResponse {
        $this->authorize('assign', $participation);

        if ($line->participation_id !== $participation->id) {
            return ApiResponse::error(
                message: "Cette ligne n'appartient pas à cette collecte.",
                status: 404,
                code: 'LINE_NOT_FOUND',
            );
        }

        $outcome = $this->assignments->remove($line);

        return ApiResponse::ok([
            'outcome' => $outcome,
            'message' => $outcome === 'cancelled'
                ? 'Des paiements existent : la ligne est annulée, pas supprimée.'
                : 'Membre retiré de la collecte.',
        ]);
    }

    /**
     * Ce qu'un collecteur a sur le terrain, toutes collectes confondues.
     *
     * C'est la vraie question du jour J : « qui dois-je aller voir ? », et non
     * « que contient telle campagne ». Sans cette route, un collecteur devrait
     * ouvrir chaque collecte et y chercher son nom.
     */
    public function myAssignments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Participation::class);

        $lines = ParticipationMember::query()
            ->where('assigned_collector_id', $request->user()->id)
            ->whereIn('status', [
                ParticipationMemberStatus::Unpaid,
                ParticipationMemberStatus::Partial,
            ])
            ->whereHas('participation', fn ($q) => $q->where('status', ParticipationStatus::Open))
            ->with(['member', 'collector', 'participation'])
            ->orderBy('status')
            ->get();

        return ApiResponse::ok(
            ParticipationLineResource::collection($lines),
            meta: [
                'lines' => $lines->count(),
                'remaining_amount' => $lines->sum(fn (ParticipationMember $line) => $line->remaining()),
            ],
        );
    }
}
