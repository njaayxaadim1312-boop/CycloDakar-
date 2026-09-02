<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ExpenseStatus;
use App\Models\Expense;

/**
 * « Votre dépense a été approuvée » ou « refusée, pour cette raison ».
 *
 * Le REFUS porte toujours son motif. Celui qui a demandé mérite de savoir
 * pourquoi on lui a dit non : un refus muet se conteste, se reformule à
 * l'identique, et use la patience de tout le monde.
 */
final class ExpenseDecided extends ClubNotification
{
    public function __construct(private readonly Expense $expense) {}

    public function code(): string
    {
        return 'expense.decided';
    }

    public function title(object $notifiable): string
    {
        return $this->expense->status === ExpenseStatus::Approved
            ? 'Dépense approuvée'
            : 'Dépense refusée';
    }

    public function body(object $notifiable): string
    {
        $montant = number_format($this->expense->amount, 0, ',', "\u{00A0}");

        if ($this->expense->status === ExpenseStatus::Approved) {
            return "{$this->expense->label} — {$montant} FCFA sont sortis de la caisse.";
        }

        return "{$this->expense->label} — {$montant} FCFA : "
            .($this->expense->decision_reason ?? 'sans motif précisé');
    }

    public function url(object $notifiable): string
    {
        return '/finance/expenses';
    }

    public function icon(): string
    {
        return $this->expense->status === ExpenseStatus::Approved ? 'check-circle' : 'x-circle';
    }
}
