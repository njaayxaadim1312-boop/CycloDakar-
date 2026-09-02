<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le grand livre. **La seule table qui bouge le solde.**
 *
 * APPEND-ONLY, et ce n'est pas négociable (règle I2 de `docs/finance.md`) :
 * pas de `deleted_at`, pas de mise à jour du montant, pas de route `DELETE`.
 * Une erreur se corrige par **contre-passation** — une écriture de sens
 * inverse, même montant, `reverses_transaction_id` renseigné et un motif
 * obligatoire. Le journal montre les deux lignes, le solde redevient juste, et
 * l'historique reste vrai.
 *
 * L'absence de `deleted_at` est délibérée : une suppression douce donnerait
 * l'illusion d'un recours propre, et le premier réflexe en cas d'erreur serait
 * d'effacer. En assemblée générale, une ligne effacée est une ligne
 * suspecte ; une contre-passation est une correction assumée.
 *
 * DEUX COLONNES DEMANDENT UNE EXPLICATION
 *
 * `balance_after` fige le solde **au moment de l'écriture**, sous verrou sur
 * la ligne du compte. C'est la colonne « Solde » du journal de caisse : elle
 * est lue, jamais recalculée à l'affichage. C'est ce qui garantit qu'un
 * journal imprimé pour une AG se réimprime identique six mois plus tard, même
 * si une écriture antérieure a été contre-passée entre-temps.
 *
 * `occurred_on` est la date MÉTIER — le jour de la sortie au Lac Rose — et
 * `created_at` le jour de la saisie. Elles diffèrent dès qu'un collecteur
 * ressaisit le lendemain ce qu'il a encaissé la veille, ce qui est la règle
 * plutôt que l'exception sur le terrain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_category_id')->nullable()
                ->constrained()->restrictOnDelete();

            $table->string('direction', 3);
            // Entier de FCFA, TOUJOURS POSITIF : le signe est dans
            // `direction`. Un montant signé se prête aux erreurs de signe
            // silencieuses — un `abs()` oublié et une dépense crédite la
            // caisse.
            $table->unsignedBigInteger('amount');
            $table->bigInteger('balance_after');

            $table->string('label', 200);

            // D'où vient l'écriture : payment · expense · manual · reversal ·
            // opening. `source_id` reste nullable, une saisie manuelle
            // n'ayant pas de pièce d'origine.
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();

            // Rattachement facultatif à un événement : c'est ce qui permet de
            // calculer le résultat d'une sortie sans rien stocker en dur.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            // La contre-passation pointe l'écriture qu'elle annule. La
            // relation est UNIQUE : on ne contre-passe pas deux fois la même
            // écriture, sinon le solde partirait deux fois à l'envers.
            $table->foreignId('reverses_transaction_id')->nullable()
                ->constrained('financial_transactions')->restrictOnDelete();
            $table->text('reason')->nullable();

            $table->date('occurred_on');
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->unique('reverses_transaction_id');
            // Le journal de caisse se lit par date métier, et le tri secondaire
            // sur `id` rend l'ordre total et reproductible.
            $table->index(['cash_account_id', 'occurred_on', 'id']);
            $table->index(['source_type', 'source_id']);
            $table->index('event_id');
            $table->index('transaction_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
