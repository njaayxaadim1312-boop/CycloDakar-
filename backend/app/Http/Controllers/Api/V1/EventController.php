<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Sport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Requests\Event\UpdateEventStatusRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sorties officielles du club.
 *
 * La liste par défaut montre **ce qui arrive**, pas tout l'historique : un
 * membre qui ouvre l'écran cherche la prochaine sortie, pas celle de mars.
 * L'historique reste accessible avec `scope=past`.
 */
final class EventController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['upcoming', 'past', 'all'])],
            'sport' => ['nullable', Rule::in(Sport::values())],
            'status' => ['nullable', Rule::in(EventStatus::values())],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $scope = $filters['scope'] ?? 'upcoming';

        $query = Event::query()
            ->visibleTo($user)
            ->with('creator')
            // Le compte des inscrits en une seule requête agrégée : sans lui,
            // vingt sorties affichées déclencheraient vingt comptages.
            ->withCount(['participants as registered_count' => fn ($q) => $q
                ->where('registration_status', RegistrationStatus::Registered)]);

        match ($scope) {
            // Les sorties à venir se lisent de la plus proche à la plus
            // lointaine ; le passé, de la plus récente à la plus ancienne.
            'past' => $query->where('starts_at', '<', now())->orderByDesc('starts_at'),
            'all' => $query->orderByDesc('starts_at'),
            default => $query->where('starts_at', '>=', now())->orderBy('starts_at'),
        };

        if (isset($filters['sport'])) {
            $query->where('sport', $filters['sport']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // « Mes sorties » : celles où je suis inscrit, pas celles que j'ai
        // créées — c'est la question que se pose un membre, pas un organisateur.
        if (($filters['mine'] ?? false) && $user->member !== null) {
            $query->whereHas('participants', fn ($q) => $q
                ->where('member_id', $user->member->id)
                ->where('registration_status', '!=', RegistrationStatus::Cancelled));
        }

        // La relation `participants` sert à calculer `my_registration`. On la
        // restreint au membre connecté : charger les 200 inscrits de chaque
        // sortie pour n'y chercher qu'une ligne serait absurde.
        if ($user->member !== null) {
            $query->with(['participants' => fn ($q) => $q->where('member_id', $user->member->id)]);
        }

        $events = $query->paginate($filters['per_page'] ?? self::PER_PAGE);

        return ApiResponse::paginated($events, EventResource::class);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = Event::create($request->validated() + [
            // `created_by` vient de la SESSION, jamais du corps de la requête.
            'created_by' => $request->user()->id,
            'status' => $request->validated('status', EventStatus::Draft->value),
        ]);

        return ApiResponse::resource(
            new EventResource($event->load('creator')),
            status: 201,
        );
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $event->load([
            'creator',
            'participants' => fn ($q) => $q
                ->where('registration_status', '!=', RegistrationStatus::Cancelled)
                // Les inscrits d'abord, la file d'attente ensuite, chacun dans
                // son ordre d'arrivée.
                ->orderBy('registration_status')
                ->orderBy('queue_position')
                ->orderBy('registered_at'),
            'participants.member',
            'participants.checkedInBy',
        ]);

        return ApiResponse::resource(new EventResource($event));
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $event->update($request->validated());

        return ApiResponse::resource(new EventResource($event->fresh()->load('creator')));
    }

    /**
     * Publication, démarrage, clôture, annulation.
     *
     * Route distincte de `update` : ce sont des actes, pas des champs. Une
     * transition interdite est refusée explicitement plutôt que silencieusement
     * appliquée — « ce brouillon est-il annoncé ou non ? » doit avoir une
     * réponse nette.
     */
    public function updateStatus(UpdateEventStatusRequest $request, Event $event): JsonResponse
    {
        $target = EventStatus::from($request->validated('status'));

        if ($event->status === $target) {
            return ApiResponse::resource(new EventResource($event->load('creator')));
        }

        if (! $event->status->canTransitionTo($target)) {
            return ApiResponse::error(
                message: "Une sortie « {$event->status->label()} » ne peut pas passer à « {$target->label()} ».",
                status: 422,
                code: 'INVALID_TRANSITION',
            );
        }

        $event->update(['status' => $target]);

        // PHASE 17 — prévenir les inscrits d'une publication ou d'une
        // annulation. C'est ici que l'envoi se branchera : les destinataires
        // sont déjà connus (`$event->participants`).

        return ApiResponse::resource(new EventResource($event->fresh()->load('creator')));
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        // Suppression douce : les sorties GPS rattachées et la liste des
        // présents restent en base. Une sortie effacée du calendrier ne doit
        // pas effacer ce que les membres ont réellement fait ce jour-là.
        $event->delete();

        return ApiResponse::ok(['deleted' => true]);
    }
}
