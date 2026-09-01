<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateGoalsRequest;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Http\Requests\Member\UpdateRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Services\AuditLogger;
use App\Services\MemberService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Annuaire des membres.
 *
 * La recherche est le cœur du module : le cahier des charges demande
 * explicitement que le collecteur cesse d'écrire les noms à la main. Elle
 * accepte donc, en une seule saisie, le prénom, le nom, le matricule (complet
 * ou juste son numéro), le téléphone sous n'importe quelle forme, ou l'email.
 */
final class MemberController extends Controller
{
    public function __construct(
        private readonly MemberService $members,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Liste paginée et filtrable.
     *
     * `GET /members?search=Kha&status=ACTIVE&role=COLLECTOR&sort=name&per_page=20`
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Member::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(MemberStatus::values())],
            'role' => ['nullable', Rule::in(UserRole::values())],
            'has_account' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'matricule', 'recent', 'seniority'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Member::query()
            // `with` et non lazy loading : sans cela, afficher le rôle de
            // 50 membres déclencherait 50 requêtes supplémentaires.
            ->with('user')
            ->search($validated['search'] ?? null);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['role'])) {
            $query->whereHas('user', fn ($q) => $q->where('role', $validated['role']));
        }

        if (array_key_exists('has_account', $validated) && $validated['has_account'] !== null) {
            $request->boolean('has_account')
                ? $query->whereNotNull('user_id')
                : $query->whereNull('user_id');
        }

        match ($validated['sort'] ?? 'name') {
            'matricule' => $query->orderBy('matricule'),
            'recent' => $query->orderByDesc('created_at'),
            'seniority' => $query->orderBy('joined_at'),
            default => $query->orderBy('last_name')->orderBy('first_name'),
        };

        $paginator = $query->paginate($validated['per_page'] ?? 20)->withQueryString();

        return ApiResponse::paginated($paginator, MemberResource::class);
    }

    /**
     * Recherche rapide pour la collecte sur le terrain.
     *
     * Charge utile réduite au strict nécessaire et pas de pagination : le
     * collecteur tape trois lettres et choisit dans une courte liste. Une
     * réponse complète et paginée serait plus lente pour rien, sur un réseau
     * mobile parfois médiocre.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Member::class);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $members = Member::query()
            ->search($validated['q'])
            // Les anciens membres n'encombrent pas la recherche terrain.
            ->whereIn('status', [MemberStatus::Active->value, MemberStatus::Pending->value])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit($validated['limit'] ?? 10)
            ->get(['id', 'uuid', 'matricule', 'first_name', 'last_name', 'phone', 'photo_path', 'status']);

        return ApiResponse::ok(
            $members->map(fn (Member $member) => [
                'uuid' => $member->uuid,
                'matricule' => $member->matricule,
                'full_name' => $member->fullName(),
                'initials' => $member->initials(),
                'phone_formatted' => $member->formattedPhone(),
                'photo_url' => $member->photoUrl(),
                'status' => $member->status->value,
            ])->all(),
            meta: ['count' => $members->count()],
        );
    }

    /**
     * Objectifs hebdomadaires du membre connecte.
     *
     * Chacun ajuste les siens ; personne ne fixe ceux d'un autre. Un objectif
     * impose par le bureau serait une pression, pas un encouragement.
     *
     * Les trois champs sont independants : relever la distance sans toucher
     * au reste doit fonctionner.
     */
    public function updateGoals(UpdateGoalsRequest $request): JsonResponse
    {
        $member = $request->user()->member;

        $map = [
            'distance_m' => 'weekly_distance_goal_m',
            'moving_time_s' => 'weekly_moving_time_goal_s',
            'activities' => 'weekly_activities_goal',
        ];

        $changes = [];

        foreach ($request->validated() as $key => $value) {
            $changes[$map[$key]] = $value;
        }

        if ($changes !== []) {
            $member->update($changes);
        }

        return ApiResponse::ok($member->fresh()->weeklyGoals());
    }

    public function show(Member $member): JsonResponse
    {
        $this->authorize('view', $member);

        return ApiResponse::ok(new MemberResource($member->load('user')));
    }

    /** Fiche club de l'utilisateur connecté. */
    public function me(Request $request): JsonResponse
    {
        $member = Member::with('user')->where('user_id', $request->user()->id)->first();

        if ($member === null) {
            return ApiResponse::error(
                message: "Aucune fiche membre n'est associée à votre compte. Contactez un responsable du club.",
                status: 404,
                code: 'NO_MEMBER_PROFILE',
            );
        }

        return ApiResponse::ok(new MemberResource($member));
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $this->authorize('create', Member::class);

        $member = $this->members->create(
            $request->safe()->except('photo'),
            $request->file('photo'),
        );

        $this->audit->log('member.created', $member, new: [
            'matricule' => $member->matricule,
            'full_name' => $member->fullName(),
        ]);

        return ApiResponse::created(new MemberResource($member->load('user')));
    }

    public function update(UpdateMemberRequest $request, Member $member): JsonResponse
    {
        $this->authorize('update', $member);

        if ($request->has('status')) {
            $this->authorize('updateStatus', $member);
        }

        $previousStatus = $member->status;

        $member = $this->members->update(
            $member,
            $request->safe()->except('photo'),
            $request->file('photo'),
        );

        if ($member->status !== $previousStatus) {
            $this->audit->logChange(
                'member.status_changed',
                $member,
                'status',
                $previousStatus->value,
                $member->status->value,
            );
        }

        return ApiResponse::ok(new MemberResource($member->load('user')));
    }

    /**
     * Attribution d'un rôle au compte du membre.
     *
     * C'est l'opération la plus sensible du module : elle ouvre l'accès à la
     * caisse. Elle est donc systématiquement tracée dans le journal d'audit,
     * dans la même transaction que le changement lui-même — une trace qui
     * pourrait manquer ne servirait à rien.
     */
    public function updateRole(UpdateRoleRequest $request, Member $member): JsonResponse
    {
        $this->authorize('updateRole', $member);

        $user = $member->user;

        if ($user === null) {
            return ApiResponse::error(
                message: "Ce membre n'a pas de compte de connexion : aucun rôle ne peut lui être attribué.",
                status: 422,
                code: 'MEMBER_HAS_NO_ACCOUNT',
            );
        }

        $newRole = UserRole::from($request->string('role')->toString());
        $previousRole = $user->role;

        if ($newRole === $previousRole) {
            return ApiResponse::ok(new MemberResource($member->load('user')));
        }

        DB::transaction(function () use ($user, $member, $previousRole, $newRole, $request): void {
            $user->forceFill(['role' => $newRole])->save();

            // Les jetons existants portent les capacités de l'ANCIEN rôle.
            // Les révoquer force une reconnexion, et donc l'émission de
            // jetons cohérents. C'est aussi ce qu'on veut en cas de
            // rétrogradation : l'accès doit tomber tout de suite.
            $user->tokens()->delete();

            $this->audit->logChange(
                action: 'member.role_changed',
                entity: $member,
                attribute: 'role',
                from: $previousRole->value,
                to: $newRole->value,
                reason: $request->input('reason'),
            );
        });

        return ApiResponse::ok(new MemberResource($member->load('user')));
    }

    /**
     * Révoque le QR Code actuel et en émet un nouveau.
     * À utiliser quand un membre pense que son QR a été copié.
     */
    public function rotateQrCode(Member $member): JsonResponse
    {
        $this->authorize('manageQrCode', $member);

        $member->rotateQrToken();

        $this->audit->log('member.qr_rotated', $member);

        return ApiResponse::ok([
            'qr_token' => $member->qr_token,
            'qr_rotated_at' => $member->qr_rotated_at?->toIso8601String(),
        ]);
    }

    /**
     * Archivage.
     *
     * Suppression douce uniquement : les activités et les paiements du membre
     * y font référence. Dans la plupart des cas, passer le statut à « Ancien
     * membre » est le geste juste ; l'archivage est réservé aux fiches créées
     * par erreur.
     */
    public function destroy(Member $member): JsonResponse
    {
        $this->authorize('delete', $member);

        DB::transaction(function () use ($member): void {
            $this->audit->log('member.archived', $member, old: [
                'matricule' => $member->matricule,
                'full_name' => $member->fullName(),
            ]);

            // Le compte associé est désactivé, pas supprimé : ses écritures
            // financières doivent rester rattachées à quelqu'un.
            $member->user?->forceFill(['is_active' => false])->save();
            $member->user?->tokens()->delete();

            $member->delete();
        });

        return ApiResponse::ok(['message' => 'Membre archivé.']);
    }
}
