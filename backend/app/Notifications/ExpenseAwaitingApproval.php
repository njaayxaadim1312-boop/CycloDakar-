<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Expense;

/**
 * « Une dépense attend votre décision. »
 *
 * Envoyée aux trésoriers et aux administrateurs, SAUF au demandeur : on
 * n'approuve pas sa propre dépense, et lui envoyer une invitation à le faire
 * serait au mieux inutile, au pire une tentation.
 *
 * C'est cette notification qui fait vivre le circuit de validation. Sans elle,
 * une dépense au-dessus du seuil attendrait que quelqu'un pense à ouvrir
 * l'écran — et le fournisseur, lui, attend d'être payé.
 */
final class ExpenseAwaitingApproval extends ClubNotification
{
    public function __construct(private readonly Expense $expense) {}

    public function code(): string
    {
        return 'expense.pending';
    }

    public function title(object $notifiable): string
    {
        return 'Dépense à valider';
    }

    public function body(object $notifiable): string
    {
        $montant = number_format($this->expense->amount, 0, ',', "\u{00A0}");

        return "{$this->expense->label} — {$montant} FCFA, demandé par "
            .($this->expense->requester->name ?? 'un responsable').'.';
    }

    public function url(object $notifiable): string
    {
        return '/finance/expenses';
    }

    public function icon(): string
    {
        return 'receipt';
    }
}
