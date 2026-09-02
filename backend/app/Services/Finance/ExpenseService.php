<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ExpenseStatus;
use App\Enums\UserRole;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Notifications\ExpenseAwaitingApproval;
use App\Notifications\ExpenseDecided;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Saisir, approuver, refuser une dépense.
 *
 * LA RÈGLE QUI GOUVERNE TOUT CE SERVICE
 *
 * Une dépense `PENDING` n'a **aucune** ligne au grand livre (règle I4 de
 * `docs/finance.md`). Elle n'est pas de l'argent sorti : c'est une intention.
 * L'écriture naît dans la MÊME transaction SQL que le passage à `APPROVED`,
 * jamais avant, jamais après.
 *
 * Cette règle a une conséquence visible pour le trésorier : le tableau de bord
 * montre le solde disponible et le montant « engagé » séparément. Les
 * confondre le ferait décider sur un chiffre faux, dans un sens ou dans
 * l'autre.
 *
 * LE SEUIL D'AUTO-APPROBATION
 *
 * Sous 25 000 FCFA (`cyclo.finance.expense_approval_threshold`), une dépense
 * saisie par un trésorier ou un administrateur est approuvée immédiatement.
 * Ce n'est pas un relâchement : c'est la reconnaissance qu'un circuit de
 * validation pour 3 000 FCFA d'eau minérale ne serait pas suivi, et qu'une
 * règle qu'on contourne protège moins qu'une règle proportionnée. Au-dessus du
 * seuil, le double regard est obligatoire — et il l'est aussi, quel que soit
 * le montant, pour qui n'est pas trésorier.
 *
 * LE DOUBLE REGARD
 *
 * Un approbateur ne peut pas approuver sa propre dépense. Cette règle vit dans
 * `ExpensePolicy` ; ici on ne fait que refuser ce qui aurait échappé à la
 * Policy — une commande d'import, par exemple.
 */
final class ExpenseService
{
    public function __construct(
        private readonly CashLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Enregistre une dépense.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): Expense
    {
        $category = TransactionCategory::findOrFail($data['transaction_category_id']);

        if ($category->direction !== TransactionDirection::Out) {
            // Classer une dépense sous un poste de recette produirait un
            // rapport annuel faux sans qu'aucune règle ne s'en aperçoive.
            throw new DomainException(
                "« {$category->name} » est un poste de recette : une dépense ne peut pas s'y ranger."
            );
        }

        return DB::transaction(function () use ($data, $author, $category): Expense {
            $expense = Expense::create([
                'transaction_category_id' => $category->id,
                'amount' => (int) $data['amount'],
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'event_id' => $data['event_id'] ?? null,
                'supplier' => $data['supplier'] ?? null,
                'reference' => $data['reference'] ?? null,
                'spent_on' => $data['spent_on'] ?? now()->toDateString(),
                // Règle I3 : l'auteur vient de la session, jamais de la requête.
                'requested_by' => $author->id,
                'status' => ExpenseStatus::Pending,
            ]);

            $this->audit->log(
                action: 'expense.created',
                entity: $expense,
                new: [
                    'amount' => $expense->amount,
                    'label' => $expense->label,
                    'category' => $category->code,
                ],
            );

            $automatique = $this->qualifiesForAutoApproval($expense, $author);

            if ($automatique) {
                // Approuvée dans la MÊME transaction : une dépense qui serait
                // créée puis approuvée en deux temps pourrait rester en
                // attente si le second appel échouait.
                $expense = $this->approve($expense, $author, auto: true);
            } else {
                /*
                 | Prévenir ceux qui peuvent décider — PHASE 17.
                 |
                 | C'est cette notification qui fait vivre le circuit de
                 | validation. Sans elle, une dépense au-dessus du seuil attend
                 | que quelqu'un pense à ouvrir l'écran, pendant que le
                 | fournisseur, lui, attend d'être payé.
                 |
                 | Le demandeur en est exclu : on n'approuve pas sa propre
                 | dépense, et l'inviter à le faire serait au mieux inutile.
                 */
                $this->notifyApprovers($expense, $author);
            }

            return $expense;
        });
    }

    /**
     * Approuve une dépense : c'est ICI que l'argent sort de la caisse.
     *
     * @param  bool  $auto  Approbation automatique sous le seuil — la Policy
     *                      a déjà tranché, on ne la rejoue pas.
     */
    public function approve(Expense $expense, User $approver, bool $auto = false): Expense
    {
        if ($expense->status !== ExpenseStatus::Pending) {
            throw new DomainException(
                "Cette dépense est déjà « {$expense->status->label()} » : elle ne peut plus être approuvée."
            );
        }

        if (! $auto && $expense->requested_by === $approver->id
            && ! config('cyclo.finance.self_approval_allowed')) {
            // Le double regard. La Policy le refuse déjà ; ce garde-fou couvre
            // les chemins qui ne passent pas par elle.
            throw new DomainException(
                "On n'approuve pas sa propre dépense : demandez à un autre responsable."
            );
        }

        return DB::transaction(function () use ($expense, $approver, $auto): Expense {
            $caisse = CashAccount::default();

            $ecriture = $this->ledger->record(
                account: $caisse,
                direction: TransactionDirection::Out,
                amount: $expense->amount,
                label: $expense->label,
                sourceType: TransactionSource::Expense,
                source: $expense,
                category: $expense->category,
                eventId: $expense->event_id,
                // La date MÉTIER de la dépense, pas celle de l'approbation :
                // c'est le jour où l'argent est sorti qui compte dans un
                // rapport mensuel.
                occurredOn: $expense->spent_on->toDateString(),
            );

            $expense->forceFill([
                'status' => ExpenseStatus::Approved,
                'approved_by' => $approver->id,
                'decided_at' => now(),
                'financial_transaction_id' => $ecriture->id,
            ])->save();

            /*
             | Le demandeur apprend la décision — SAUF s'il vient de la prendre
             | lui-même.
             |
             | Une approbation automatique sous le seuil est déclenchée par le
             | trésorier qui saisit. Lui notifier « votre dépense a été
             | approuvée » deux secondes après qu'il l'a saisie serait du bruit
             | pur — et c'est exactement ainsi qu'on apprend aux gens à ignorer
             | les notifications.
             */
            if (! $auto) {
                $expense->requester?->notify(new ExpenseDecided($expense->fresh()));
            }

            $this->audit->log(
                action: 'expense.approved',
                entity: $expense,
                old: ['status' => ExpenseStatus::Pending->value],
                new: [
                    'status' => ExpenseStatus::Approved->value,
                    'amount' => $expense->amount,
                    'transaction' => $ecriture->uuid,
                    'automatic' => $auto,
                ],
                reason: $auto
                    ? 'Sous le seuil de validation ('.config('cyclo.finance.expense_approval_threshold').' FCFA).'
                    : null,
            );

            return $expense->fresh();
        }, attempts: 3);
    }

    /**
     * Refuse une dépense. Aucune écriture, et la ligne RESTE.
     *
     * Le bureau doit pouvoir expliquer pourquoi 80 000 FCFA de transport n'ont
     * pas été engagés, et celui qui a demandé mérite de savoir pourquoi on lui
     * a dit non. Une ligne effacée ne répond à aucune des deux questions.
     */
    public function reject(Expense $expense, User $approver, string $reason): Expense
    {
        if ($expense->status !== ExpenseStatus::Pending) {
            throw new DomainException(
                "Cette dépense est déjà « {$expense->status->label()} » : elle ne peut plus être refusée."
            );
        }

        $expense->forceFill([
            'status' => ExpenseStatus::Rejected,
            'approved_by' => $approver->id,
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        // Un refus muet se conteste, se reformule à l'identique, et use la
        // patience de tout le monde. Le motif part avec.
        $expense->requester?->notify(new ExpenseDecided($expense->fresh()));

        $this->audit->log(
            action: 'expense.rejected',
            entity: $expense,
            old: ['status' => ExpenseStatus::Pending->value],
            new: ['status' => ExpenseStatus::Rejected->value],
            reason: $reason,
        );

        return $expense->fresh();
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Prévient ceux qui ont le pouvoir de décider — sauf le demandeur.
     */
    private function notifyApprovers(Expense $expense, User $author): void
    {
        $approbateurs = User::query()
            ->whereIn('role', [UserRole::Treasurer, UserRole::Admin, UserRole::SuperAdmin])
            ->where('id', '!=', $author->id)
            ->where('is_active', true)
            ->get();

        foreach ($approbateurs as $approbateur) {
            $approbateur->notify(new ExpenseAwaitingApproval($expense));
        }
    }

    /**
     * Une dépense se valide-t-elle toute seule ?
     *
     * Deux conditions cumulatives : sous le seuil, ET saisie par quelqu'un qui
     * aurait de toute façon le droit d'approuver. Un collecteur qui saisirait
     * 1 000 FCFA passe donc quand même par le circuit — sans quoi le seuil
     * deviendrait une porte ouverte pour qui n'a pas la responsabilité de la
     * caisse.
     */
    private function qualifiesForAutoApproval(Expense $expense, User $author): bool
    {
        if (! $author->role->canManageFinance()) {
            return false;
        }

        return $expense->amount < (int) config('cyclo.finance.expense_approval_threshold', 25000);
    }
}
