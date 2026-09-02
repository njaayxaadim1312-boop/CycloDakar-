<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;

/**
 * Qui saisit une dépense, qui la décide.
 *
 * LE DOUBLE REGARD EST LA SEULE RÈGLE QUI COMPTE ICI.
 *
 * Un approbateur ne peut pas approuver sa propre dépense. Sans cela, un
 * trésorier pourrait sortir n'importe quelle somme de la caisse en deux clics,
 * seul, sans que personne n'ait rien vu. Ce n'est pas une supposition de
 * malhonnêteté : c'est la protection élémentaire de celui qui tient la caisse,
 * qui doit pouvoir montrer qu'il n'a jamais décidé seul.
 *
 * La conséquence pratique est assumée : un club avec un seul trésorier devra
 * faire approuver par un administrateur. C'est exactement ce qu'on veut, et le
 * réglage `self_approval_allowed` existe pour un club qui assumerait le
 * contraire par écrit.
 *
 * La saisie, elle, reste au trésorier et au-dessus. Un collecteur manie
 * l'argent qui ENTRE ; celui qui sort relève du bureau.
 */
final class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canManageFinance();
    }

    public function view(User $user, Expense $expense): bool
    {
        // Celui qui a demandé peut suivre sa demande, même sans être trésorier
        // — sinon il ne saurait jamais si elle a été acceptée.
        return $user->role->canManageFinance() || $expense->requested_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->role->canManageFinance();
    }

    /** Approuver : trésorier et au-dessus, et JAMAIS sa propre dépense. */
    public function approve(User $user, Expense $expense): bool
    {
        if (! $user->role->canManageFinance()) {
            return false;
        }

        if ($expense->status !== ExpenseStatus::Pending) {
            return false;
        }

        if ($expense->requested_by === $user->id) {
            return (bool) config('cyclo.finance.self_approval_allowed');
        }

        return true;
    }

    /**
     * Refuser suit les mêmes règles qu'approuver.
     *
     * Volontairement : si l'on pouvait refuser sa propre dépense sans pouvoir
     * l'approuver, il suffirait de saisir puis de refuser pour faire
     * disparaître une demande gênante sans laisser de décideur au journal.
     */
    public function reject(User $user, Expense $expense): bool
    {
        return $this->approve($user, $expense);
    }

    /**
     * Modifier une dépense EN ATTENTE : son auteur, ou l'administration.
     *
     * Une dépense décidée ne se modifie plus : elle a produit une écriture au
     * grand livre, et la retoucher rendrait le journal inexplicable.
     */
    public function update(User $user, Expense $expense): bool
    {
        if ($expense->status !== ExpenseStatus::Pending) {
            return false;
        }

        return $expense->requested_by === $user->id || $user->role->isAdmin();
    }

    /** Joindre un justificatif : comme modifier. */
    public function attach(User $user, Expense $expense): bool
    {
        // Une pièce peut arriver APRÈS l'approbation — un fournisseur qui
        // envoie sa facture la semaine suivante. On ne bloque donc pas sur le
        // statut : c'est le seul cas où une dépense décidée accepte encore un
        // ajout, et il n'affecte aucun montant.
        return $user->role->canManageFinance() || $expense->requested_by === $user->id;
    }
}
