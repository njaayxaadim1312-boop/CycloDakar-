<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\DecideExpenseRequest;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Event;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\TransactionCategory;
use App\Services\Finance\ExpenseService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dépenses du club.
 *
 * Tous les montants sont des **entiers de FCFA**. Rien de ce que le serveur
 * détermine — statut, approbateur, écriture au grand livre, solde — n'est lu
 * dans la requête (règle I3 de `docs/finance.md`).
 *
 * La règle centrale vit dans `ExpenseService` : une dépense `PENDING` n'a
 * aucune ligne au grand livre, et l'écriture naît dans la même transaction SQL
 * que l'approbation.
 */
final class ExpenseController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ExpenseService $expenses,
    ) {}

    /**
     * Liste des dépenses.
     *
     * Par défaut, **celles qui attendent une décision** : c'est ce qu'un
     * trésorier vient faire. L'historique complet reste à un filtre près, mais
     * il n'a pas à encombrer l'écran de ce qui n'appelle plus d'action.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $filtres = $request->validate([
            'status' => ['nullable', Rule::in(ExpenseStatus::values())],
            'scope' => ['nullable', Rule::in(['pending', 'all'])],
            'category' => ['nullable', 'string', 'exists:transaction_categories,code'],
            'event' => ['nullable', 'uuid', 'exists:events,uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Expense::query()
            ->with(['category', 'event', 'requester', 'approver', 'attachments'])
            // La plus récente d'abord ; à date métier égale, la dernière saisie.
            ->orderByDesc('spent_on')
            ->orderByDesc('id');

        if (isset($filtres['status'])) {
            $query->where('status', $filtres['status']);
        } elseif (($filtres['scope'] ?? 'all') === 'pending') {
            $query->where('status', ExpenseStatus::Pending);
        }

        if (isset($filtres['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('code', $filtres['category']));
        }

        if (isset($filtres['event'])) {
            $query->where('event_id', Event::where('uuid', $filtres['event'])->value('id'));
        }

        if (isset($filtres['from'])) {
            $query->whereDate('spent_on', '>=', $filtres['from']);
        }

        if (isset($filtres['to'])) {
            $query->whereDate('spent_on', '<=', $filtres['to']);
        }

        $depenses = $query->paginate($filtres['per_page'] ?? self::PER_PAGE);

        return ApiResponse::paginated($depenses, ExpenseResource::class);
    }

    /**
     * Enregistre une dépense.
     *
     * Elle naît toujours `PENDING`. Le serveur décide seul si elle passe
     * immédiatement sous le seuil de validation — le client ne peut pas le
     * demander, sinon le seuil ne protégerait rien.
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $depense = $this->expenses->create([
                'transaction_category_id' => TransactionCategory::query()
                    ->where('code', $data['category'])->value('id'),
                'amount' => (int) $data['amount'],
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'supplier' => $data['supplier'] ?? null,
                'reference' => $data['reference'] ?? null,
                'event_id' => isset($data['event'])
                    ? Event::where('uuid', $data['event'])->value('id')
                    : null,
                'spent_on' => $data['spent_on'] ?? null,
            ], $request->user());
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422, code: 'EXPENSE_REFUSED');
        }

        return ApiResponse::resource(
            new ExpenseResource($depense->load(['category', 'event', 'requester', 'approver'])),
            status: 201,
        );
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return ApiResponse::resource(new ExpenseResource(
            $expense->load(['category', 'event', 'requester', 'approver', 'attachments']),
        ));
    }

    /** Approuve : c'est ICI que l'argent sort de la caisse. */
    public function approve(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('approve', $expense);

        try {
            $decidee = $this->expenses->approve($expense, $request->user());
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422, code: 'DECISION_REFUSED');
        }

        return ApiResponse::resource(new ExpenseResource(
            $decidee->load(['category', 'event', 'requester', 'approver']),
        ));
    }

    /** Refuse. Aucune écriture, et la ligne reste, avec son motif. */
    public function reject(DecideExpenseRequest $request, Expense $expense): JsonResponse
    {
        try {
            $decidee = $this->expenses->reject(
                $expense,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422, code: 'DECISION_REFUSED');
        }

        return ApiResponse::resource(new ExpenseResource(
            $decidee->load(['category', 'event', 'requester', 'approver']),
        ));
    }

    /* ---------------------------------------------------------------------- */
    /* Justificatifs                                                          */
    /* ---------------------------------------------------------------------- */

    /**
     * Joint un justificatif.
     *
     * Le fichier va sur le disque **privé**. Une facture porte un fournisseur,
     * un montant, parfois un numéro de compte : la déposer dans `public/`
     * la rendrait lisible par quiconque devine l'URL, sans authentification et
     * sans trace.
     */
    public function attach(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('attach', $expense);

        $mimes = array_merge(
            (array) config('cyclo.uploads.image_mimes'),
            (array) config('cyclo.uploads.document_mimes'),
        );

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('cyclo.uploads.max_size_kb'),
                'mimetypes:'.implode(',', $mimes),
            ],
        ], [
            'file.mimetypes' => 'Un justificatif est une image ou un PDF.',
            'file.max' => 'Ce fichier est trop lourd pour être joint.',
        ]);

        $fichier = $request->file('file');

        // Le nom sur le disque est généré : un nom d'origine peut contenir
        // n'importe quoi, y compris de quoi sortir du répertoire.
        $chemin = $fichier->store(
            "expenses/{$expense->uuid}",
            config('cyclo.uploads.private_disk'),
        );

        $piece = $expense->attachments()->create([
            'path' => $chemin,
            'original_name' => mb_substr((string) $fichier->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $fichier->getMimeType(),
            'size_bytes' => (int) $fichier->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return ApiResponse::ok([
            'uuid' => $piece->uuid,
            'name' => $piece->original_name,
            'mime_type' => $piece->mime_type,
            'size_bytes' => (int) $piece->size_bytes,
            'is_image' => $piece->isImage(),
            'url' => route('api.v1.expenses.attachments.show', [
                'expense' => $expense->uuid,
                'attachment' => $piece->uuid,
            ]),
        ], status: 201);
    }

    /**
     * Renvoie un justificatif.
     *
     * Route contrôlée : c'est elle qui remplace l'absence d'URL publique. Le
     * fichier est envoyé en flux, sans passer par la mémoire — un PDF de
     * plusieurs mégaoctets ne doit pas faire enfler le processus PHP.
     */
    public function attachment(
        Request $request,
        Expense $expense,
        ExpenseAttachment $attachment,
    ): StreamedResponse|JsonResponse {
        $this->authorize('view', $expense);

        if ($attachment->expense_id !== $expense->id) {
            return ApiResponse::error(
                message: "Ce justificatif n'appartient pas à cette dépense.",
                status: 404,
                code: 'ATTACHMENT_NOT_FOUND',
            );
        }

        $disque = Storage::disk(config('cyclo.uploads.private_disk'));

        if (! $disque->exists($attachment->path)) {
            return ApiResponse::error(
                message: 'Le fichier est introuvable sur le serveur.',
                status: 404,
                code: 'FILE_MISSING',
            );
        }

        return $disque->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                // Jamais mis en cache par un intermédiaire : une facture est
                // une pièce confidentielle.
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    /** Retire un justificatif. Le fichier suit la ligne. */
    public function detach(
        Request $request,
        Expense $expense,
        ExpenseAttachment $attachment,
    ): JsonResponse {
        $this->authorize('attach', $expense);

        if ($attachment->expense_id !== $expense->id) {
            return ApiResponse::error(
                message: "Ce justificatif n'appartient pas à cette dépense.",
                status: 404,
                code: 'ATTACHMENT_NOT_FOUND',
            );
        }

        $attachment->delete();

        return ApiResponse::ok(['deleted' => true]);
    }
}
