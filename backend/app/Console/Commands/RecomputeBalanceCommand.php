<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TransactionDirection;
use App\Models\CashAccount;
use App\Models\FinancialTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vérifie — et répare — le cache du solde de caisse.
 *
 * LE SOLDE EST DÉRIVÉ (règle I1 de `docs/finance.md`) :
 *
 *     solde = solde d'ouverture + Σ(entrées) − Σ(sorties)
 *
 * `cash_accounts.current_balance` n'est qu'un cache de lecture. Cette commande
 * recalcule depuis zéro et **échoue bruyamment** en cas d'écart : un code de
 * sortie non nul, pas un avertissement dans un journal que personne ne lit.
 *
 * POURQUOI ÉCHOUER PLUTÔT QUE RÉPARER EN SILENCE
 *
 * Un écart ne se produit pas tout seul. Il signifie qu'une écriture est passée
 * hors de `CashLedger` — un import, un correctif appliqué à chaud, une
 * migration mal écrite. Réparer sans le dire masquerait la cause et la
 * laisserait agir. La commande signale d'abord ; `--fix` répare, et seulement
 * si on le lui demande.
 *
 * ELLE VÉRIFIE AUSSI `balance_after`, colonne par colonne. C'est elle que lit
 * le journal de caisse imprimé en assemblée générale : si la suite des soldes
 * ne se recompose pas, le journal est faux même quand le total final est bon.
 * Cette vérification-là n'a pas de `--fix` : réécrire un `balance_after`
 * reviendrait à modifier une écriture, ce que la règle I2 interdit. Un écart
 * ici demande une décision humaine.
 */
final class RecomputeBalanceCommand extends Command
{
    protected $signature = 'finance:recompute-balance
                            {--fix : Corrige le cache du solde au lieu de seulement signaler}';

    protected $description = 'Recalcule le solde de caisse depuis le grand livre et signale tout écart.';

    public function handle(): int
    {
        $comptes = CashAccount::all();

        if ($comptes->isEmpty()) {
            $this->warn('Aucune caisse configurée. Exécutez « php artisan db:seed --class=FinanceSeeder ».');

            return self::SUCCESS;
        }

        $anomalies = 0;

        foreach ($comptes as $compte) {
            $anomalies += $this->verifierCompte($compte);
        }

        if ($anomalies === 0) {
            $this->info('Caisse vérifiée : le solde et la suite des écritures sont cohérents.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("{$anomalies} anomalie(s) détectée(s) dans la comptabilité du club.");
        $this->line('Une écriture est passée hors de CashLedger. Cherchez la cause avant de corriger.');

        return self::FAILURE;
    }

    private function verifierCompte(CashAccount $compte): int
    {
        $this->line("<comment>{$compte->name}</comment>");

        $entrees = (int) $compte->transactions()
            ->where('direction', TransactionDirection::In)->sum('amount');
        $sorties = (int) $compte->transactions()
            ->where('direction', TransactionDirection::Out)->sum('amount');

        $derive = $compte->opening_balance + $entrees - $sorties;
        $ecart = $derive - $compte->current_balance;

        $this->table(['', 'FCFA'], [
            ['Solde d\'ouverture', number_format($compte->opening_balance, 0, ',', ' ')],
            ['Entrées', '+ '.number_format($entrees, 0, ',', ' ')],
            ['Sorties', '− '.number_format($sorties, 0, ',', ' ')],
            ['Solde recalculé', number_format($derive, 0, ',', ' ')],
            ['Solde en cache', number_format($compte->current_balance, 0, ',', ' ')],
        ]);

        $anomalies = 0;

        if ($ecart !== 0) {
            $anomalies++;
            $this->error('Écart de '.number_format($ecart, 0, ',', ' ').' FCFA entre le cache et le grand livre.');

            if ($this->option('fix')) {
                // Requête directe : le cache est une colonne, pas un modèle
                // dont l'instance en mémoire pourrait porter autre chose.
                DB::table('cash_accounts')
                    ->where('id', $compte->id)
                    ->update(['current_balance' => $derive, 'updated_at' => now()]);

                $this->info('Cache corrigé. Cherchez tout de même la cause de l\'écart.');
            } else {
                $this->line('Relancez avec --fix pour corriger le cache.');
            }
        }

        $anomalies += $this->verifierSuite($compte);

        return $anomalies;
    }

    /**
     * Recompose la suite des `balance_after`, écriture par écriture.
     *
     * L'ORDRE EST CELUI DE L'ENREGISTREMENT (`id`), ET NON LA DATE MÉTIER.
     *
     * `balance_after` est le solde de la caisse **au moment où l'écriture a
     * été passée**. Il ne peut donc se recomposer que dans cet ordre-là. Un
     * encaissement saisi le lundi pour une sortie du samedi précédent — cas
     * courant, un collecteur ressaisit rarement le soir même — s'insère avant
     * lui par `occurred_on` mais après lui par `id`. Vérifier dans l'ordre des
     * dates métier ferait donc crier la commande sur une comptabilité
     * parfaitement saine.
     *
     * Conséquence à connaître pour le journal de caisse (PHASE 14) : trié par
     * date métier, la colonne « Solde » n'est pas monotone dès qu'une saisie
     * a été antidatée. C'est la réalité d'une caisse tenue à la main, pas un
     * défaut à masquer.
     */
    private function verifierSuite(CashAccount $compte): int
    {
        $solde = $compte->opening_balance;
        $ecarts = 0;

        $compte->transactions()
            ->orderBy('id')
            ->chunk(500, function ($ecritures) use (&$solde, &$ecarts): void {
                foreach ($ecritures as $ecriture) {
                    /** @var FinancialTransaction $ecriture */
                    $solde += $ecriture->signedAmount();

                    if ($solde !== $ecriture->balance_after) {
                        $ecarts++;
                        $this->error(
                            "Écriture {$ecriture->uuid} : solde figé {$ecriture->balance_after}, "
                            ."recalculé {$solde}."
                        );
                    }
                }
            });

        if ($ecarts > 0) {
            $this->line(
                'Ces écarts ne sont PAS corrigés automatiquement : réécrire un solde figé '
                .'reviendrait à modifier une écriture (règle I2).'
            );
        }

        return $ecarts;
    }
}
