<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\CashAccount;
use App\Models\FinancialTransaction;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le SEUL chemin d'écriture du grand livre.
 *
 * Rien d'autre dans l'application n'a le droit d'insérer une ligne dans
 * `financial_transactions` ni de toucher `cash_accounts.current_balance`. Ce
 * n'est pas une préférence de style : le solde après écriture (`balance_after`)
 * n'est juste que si toutes les écritures passent par le même verrou, dans le
 * même ordre. Deux chemins d'écriture, et deux encaissements simultanés
 * figeraient le même solde sur deux lignes différentes — le journal de caisse
 * deviendrait alors incohérent de façon invisible.
 *
 * LE VERROU
 *
 * `SELECT … FOR UPDATE` sur la ligne de la caisse sérialise les écritures.
 * C'est précisément pour cela que les tests tournent sur MySQL depuis la phase
 * 4 : SQLite ignore purement et simplement `FOR UPDATE`, et le bug ne serait
 * apparu qu'en production, un jour de collecte, à deux collecteurs.
 *
 * LA CORRECTION
 *
 * `reverse()` n'efface ni ne modifie l'écriture d'origine : elle en ajoute une
 * de sens inverse, même montant, qui la désigne. Le journal montre les deux
 * lignes. C'est la règle I2 de `docs/finance.md`, et le modèle
 * `FinancialTransaction` la fait respecter en levant une exception sur toute
 * tentative de mise à jour ou de suppression.
 */
final class CashLedger
{
    /**
     * Écrit une ligne au grand livre et met le cache du solde à jour.
     *
     * **À appeler obligatoirement dans une transaction SQL**, avec l'acte
     * qu'elle enregistre : une écriture sans son paiement, ou l'inverse, est
     * pire que rien.
     *
     * @param  Model|null  $source  L'acte d'origine (paiement, dépense).
     */
    public function record(
        CashAccount $account,
        TransactionDirection $direction,
        int $amount,
        string $label,
        TransactionSource $sourceType,
        ?Model $source = null,
        ?TransactionCategory $category = null,
        ?int $eventId = null,
        ?string $occurredOn = null,
        ?string $reason = null,
        ?FinancialTransaction $reverses = null,
    ): FinancialTransaction {
        $this->assertInTransaction();

        if ($amount <= 0) {
            // Un montant nul ou négatif n'est pas une écriture : c'est une
            // erreur d'appel. Le sens est porté par `direction`, jamais par le
            // signe du montant.
            throw new LogicException("Le montant d'une écriture doit être strictement positif.");
        }

        // Le sens de la catégorie doit correspondre à celui de l'écriture —
        // classer une dépense de transport en recette produirait un rapport
        // annuel faux sans qu'aucune règle ne s'en aperçoive.
        //
        // UNE SEULE EXCEPTION : LA CONTRE-PASSATION.
        //
        // Elle est de sens inverse par construction, et elle garde pourtant la
        // catégorie de l'écriture qu'elle annule. C'est indispensable : une
        // annulation d'encaissement doit venir SE RETRANCHER du poste
        // « Participations », faute de quoi le rapport annuel continuerait
        // d'afficher 165 000 FCFA de participations dont une partie est
        // ressortie de la caisse. La ranger ailleurs — ou nulle part —
        // laisserait un poste définitivement surévalué.
        if ($category !== null && $reverses === null && $category->direction !== $direction) {
            throw new LogicException(
                "La catégorie « {$category->name} » est une {$category->direction->label()} : "
                ."elle ne peut pas porter une {$direction->label()}."
            );
        }

        // Le verrou. Tout est là. La ligne est relue APRÈS verrouillage : le
        // solde lu avant ne vaudrait rien.
        $verrouille = CashAccount::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $soldeApres = $verrouille->current_balance + $direction->sign() * $amount;

        $transaction = FinancialTransaction::create([
            'cash_account_id' => $verrouille->id,
            'transaction_category_id' => $category?->id,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => $soldeApres,
            'label' => $label,
            'source_type' => $sourceType,
            'source_id' => $source?->getKey(),
            'event_id' => $eventId,
            'reverses_transaction_id' => $reverses?->id,
            'reason' => $reason,
            'occurred_on' => $occurredOn ?? now()->toDateString(),
            // Règle I3 : l'auteur vient de la session, jamais de la requête.
            'created_by' => auth()->id(),
        ]);

        // Le cache. Mis à jour par requête directe et non par `$model->save()` :
        // on écrit une colonne, pas un modèle dont l'instance en mémoire
        // pourrait porter d'autres modifications non voulues.
        CashAccount::query()->whereKey($verrouille->id)->update([
            'current_balance' => $soldeApres,
            'updated_at' => now(),
        ]);

        $account->current_balance = $soldeApres;

        return $transaction;
    }

    /**
     * Contre-passe une écriture : même montant, sens inverse.
     *
     * L'écriture d'origine n'est pas touchée. Le solde redevient juste, et le
     * journal garde les deux lignes — ce qui est exactement ce qu'un
     * commissaire aux comptes doit pouvoir lire.
     */
    public function reverse(FinancialTransaction $original, string $reason): FinancialTransaction
    {
        $this->assertInTransaction();

        if ($original->reversedBy()->exists()) {
            // Sans ce garde-fou, contre-passer deux fois enverrait le solde à
            // l'envers d'un montant complet. L'index unique sur
            // `reverses_transaction_id` le rattraperait, mais avec un message
            // de contrainte SQL illisible pour le trésorier.
            throw new LogicException('Cette écriture a déjà été contre-passée.');
        }

        return $this->record(
            account: $original->account,
            direction: $original->direction->opposite(),
            amount: $original->amount,
            label: 'Annulation — '.$original->label,
            sourceType: TransactionSource::Reversal,
            category: $original->category,
            eventId: $original->event_id,
            // La date métier de la correction est CELLE DU JOUR, pas celle de
            // l'écriture annulée : l'argent ressort de la caisse aujourd'hui.
            // Antidater rendrait faux tout rapport déjà présenté.
            occurredOn: now()->toDateString(),
            reason: $reason,
            reverses: $original,
        );
    }

    /**
     * Le solde recalculé depuis zéro, et l'écart avec le cache.
     *
     * @return array{derived: int, cached: int, drift: int}
     */
    public function audit(CashAccount $account): array
    {
        $derive = $account->derivedBalance();

        return [
            'derived' => $derive,
            'cached' => $account->current_balance,
            'drift' => $derive - $account->current_balance,
        ];
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Une écriture au grand livre doit être enveloppée dans une transaction SQL '
                ."avec l'acte qu'elle enregistre."
            );
        }
    }
}
