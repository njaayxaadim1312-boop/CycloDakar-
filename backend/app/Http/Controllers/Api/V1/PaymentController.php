<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ParticipationMemberStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CancelPaymentRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\ParticipationLineResource;
use App\Http\Resources\PaymentResource;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\Payment;
use App\Services\Finance\PaymentService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Encaissements.
 *
 * Tous les montants entrent et sortent en **entiers de FCFA** (règle I5). Rien
 * de ce que le serveur détermine — collecteur, montant déjà payé, statut,
 * solde — n'est lu dans la requête (règle I3).
 *
 * Le contrôleur reste mince : la logique tient dans `PaymentService`, qui est
 * le seul à savoir verrouiller la caisse et la dette dans le bon ordre. Un
 * contrôleur qui écrirait lui-même en base ferait un second chemin
 * d'écriture, et le solde figé sur chaque écriture cesserait d'être fiable.
 */
final class PaymentController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Encaisse un versement sur une collecte.
     *
     * Répond **201** pour un encaissement réel et **200** pour un rejeu : le
     * client distingue ainsi « c'est enregistré » de « c'était déjà
     * enregistré », sans avoir à comparer les identifiants. Dans les deux cas
     * le corps est le même reçu, ce qui laisse un client naïf fonctionner
     * correctement sans lire le code de statut.
     */
    public function store(StorePaymentRequest $request, Participation $participation): JsonResponse
    {
        $ligne = $this->findLine($participation, $request->validated('member'));

        if ($ligne === null) {
            return ApiResponse::error(
                message: "Ce membre n'est pas rattaché à cette collecte.",
                status: 404,
                code: 'LINE_NOT_FOUND',
            );
        }

        // L'autorisation porte sur la LIGNE : un collecteur n'encaisse que sur
        // les dettes qui lui sont assignées.
        $this->authorize('create', [Payment::class, $ligne]);

        try {
            $resultat = $this->payments->collect(
                line: $ligne,
                amount: (int) $request->validated('amount'),
                method: PaymentMethod::from($request->validated('method')),
                idempotencyKey: $request->validated('idempotency_key'),
                collector: $request->user(),
                reference: $request->validated('reference'),
                note: $request->validated('note'),
                paidOn: $request->validated('paid_on'),
            );
        } catch (DomainException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422,
                code: 'PAYMENT_REFUSED',
            );
        }

        $paiement = $resultat['payment']->load(['member', 'participation', 'collector']);

        return ApiResponse::ok(
            new PaymentResource($paiement),
            meta: [
                'replayed' => $resultat['replayed'],
                // Ce que le collecteur doit voir tout de suite : reste-t-il
                // quelque chose à percevoir sur cette ligne ?
                'line' => new ParticipationLineResource(
                    $ligne->fresh()->load(['member', 'collector']),
                ),
            ],
            status: $resultat['replayed'] ? 200 : 201,
        );
    }

    /** Les encaissements d'une collecte. */
    public function index(Request $request, Participation $participation): JsonResponse
    {
        $this->authorize('view', $participation);

        $filtres = $request->validate([
            'member' => ['nullable', 'uuid', 'exists:members,uuid'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'include_cancelled' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->where('participation_id', $participation->id)
            ->with(['member', 'collector', 'canceller'])
            // Le plus récent d'abord : c'est ce qu'on vient vérifier après
            // avoir encaissé.
            ->orderByDesc('id');

        if (isset($filtres['member'])) {
            $query->where('member_id', Member::where('uuid', $filtres['member'])->value('id'));
        }

        if (isset($filtres['method'])) {
            $query->where('method', $filtres['method']);
        }

        // Les annulations sont masquées par défaut mais restent atteignables :
        // les cacher tout à fait empêcherait de comprendre un écart de caisse.
        if (! ($filtres['include_cancelled'] ?? false)) {
            $query->whereNull('cancelled_at');
        }

        $paiements = $query->paginate($filtres['per_page'] ?? self::PER_PAGE);

        return ApiResponse::paginated($paiements, PaymentResource::class);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::resource(
            new PaymentResource($payment->load(['member', 'participation', 'collector', 'canceller'])),
        );
    }

    /**
     * Annule un encaissement — par contre-passation, jamais par suppression.
     *
     * Trésorier et administration seulement : celui qui a encaissé ne peut pas
     * être celui qui efface (`PaymentPolicy`).
     */
    public function cancel(CancelPaymentRequest $request, Payment $payment): JsonResponse
    {
        try {
            $annule = $this->payments->cancel(
                $payment,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (DomainException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422,
                code: 'CANCEL_REFUSED',
            );
        }

        return ApiResponse::ok(
            new PaymentResource($annule->load(['member', 'participation', 'collector', 'canceller'])),
            meta: [
                'line' => new ParticipationLineResource(
                    $payment->line->fresh()->load(['member', 'collector']),
                ),
            ],
        );
    }

    /**
     * Ce que JE dois et ce que J'AI payé.
     *
     * La seule route financière ouverte à un simple membre, et elle ne montre
     * que lui. C'est ce qui manquait pour qu'un membre puisse vérifier son
     * compte sans demander au trésorier — la première cause de friction dans
     * un club qui collecte en espèces.
     */
    public function mine(Request $request): JsonResponse
    {
        $membre = $request->user()->member;

        if ($membre === null) {
            // Un compte sans fiche club ne doit rien : répondre 404 laisserait
            // croire à une panne, alors que la situation est normale (un
            // administrateur système, par exemple).
            return ApiResponse::ok([
                'dues' => [],
                'payments' => [],
            ], meta: ['expected_amount' => 0, 'paid_amount' => 0, 'remaining_amount' => 0]);
        }

        $dettes = ParticipationMember::query()
            ->where('member_id', $membre->id)
            ->where('status', '!=', ParticipationMemberStatus::Cancelled)
            ->whereHas('participation', fn ($q) => $q->whereIn('status', ['OPEN', 'CLOSED']))
            ->with(['participation', 'collector', 'member'])
            ->orderByDesc('id')
            ->get();

        $paiements = Payment::query()
            ->where('member_id', $membre->id)
            ->with(['participation', 'collector'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ApiResponse::ok([
            'dues' => ParticipationLineResource::collection($dettes),
            'payments' => PaymentResource::collection($paiements),
        ], meta: [
            'expected_amount' => (int) $dettes->sum('expected_amount'),
            'paid_amount' => (int) $dettes->sum('paid_amount'),
            'remaining_amount' => (int) $dettes->sum(
                fn (ParticipationMember $ligne) => $ligne->remaining(),
            ),
        ]);
    }

    /**
     * Ce qu'un membre doit, vu par un collecteur.
     *
     * C'est ce qui donne son sens au scan du QR Code : on reconnait quelqu'un,
     * et on voit immediatement ce qu'il reste a percevoir — sans le chercher
     * dans une liste, au bord d'une route.
     *
     * Seules les collectes OUVERTES et les dettes non soldees apparaissent :
     * un collecteur n'a que faire de l'historique, il a besoin de savoir quoi
     * demander maintenant. Le droit d'encaisser est calcule ligne par ligne
     * par le serveur (`can_pay`).
     */
    public function memberDues(Request $request, Member $member): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $lignes = ParticipationMember::query()
            ->where('member_id', $member->id)
            ->whereIn('status', [
                ParticipationMemberStatus::Unpaid,
                ParticipationMemberStatus::Partial,
            ])
            ->whereHas('participation', fn ($q) => $q->where('status', 'OPEN'))
            ->with(['participation', 'collector', 'member'])
            ->orderBy('id')
            ->get();

        return ApiResponse::ok(
            ParticipationLineResource::collection($lignes),
            meta: [
                'member' => [
                    'uuid' => $member->uuid,
                    'matricule' => $member->matricule,
                    'full_name' => $member->fullName(),
                ],
                'remaining_amount' => (int) $lignes->sum(
                    fn (ParticipationMember $ligne) => $ligne->remaining(),
                ),
            ],
        );
    }

    private function findLine(Participation $participation, string $memberUuid): ?ParticipationMember
    {
        $memberId = Member::where('uuid', $memberUuid)->value('id');

        if ($memberId === null) {
            return null;
        }

        return ParticipationMember::query()
            ->where('participation_id', $participation->id)
            ->where('member_id', $memberId)
            ->first();
    }
}
