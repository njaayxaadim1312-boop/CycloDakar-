<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les dépenses du club.
 *
 * UNE DÉPENSE EN ATTENTE N'EST PAS DE L'ARGENT SORTI.
 *
 * C'est la règle I4 de `docs/finance.md`, et elle gouverne toute cette table :
 * tant que le statut est `PENDING`, il n'existe **aucune** ligne au grand
 * livre, et le solde ne bouge pas. L'écriture naît dans la même transaction
 * SQL que le passage à `APPROVED`, et `financial_transaction_id` la désigne.
 *
 * Une dépense en attente est une intention, pas un mouvement. Le tableau de
 * bord la montre séparément, sous le nom d'« engagé ». Les confondre ferait
 * croire au trésorier qu'il a moins d'argent qu'il n'en a — ou pire, qu'il en
 * a davantage.
 *
 * POURQUOI PAS DE SUPPRESSION, MAIS UN REFUS
 *
 * Une dépense refusée reste, avec son motif. Le bureau doit pouvoir expliquer
 * pourquoi 80 000 FCFA de transport n'ont pas été engagés — et celui qui a
 * demandé mérite de savoir pourquoi on lui a dit non. Une ligne effacée ne
 * répond à aucune de ces deux questions.
 *
 * `spent_on` est la date MÉTIER — le jour où l'argent a été dépensé — et
 * `created_at` le jour de la saisie. Elles diffèrent dès qu'un justificatif
 * remonte une semaine plus tard, ce qui est la règle plutôt que l'exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('transaction_category_id')->constrained()->restrictOnDelete();

            // Entier de FCFA, toujours positif : le sens est porté par la
            // nature même de la dépense, jamais par le signe (règle I5).
            $table->unsignedBigInteger('amount');

            $table->string('label', 200);
            $table->text('description')->nullable();

            // Rattachement facultatif à une sortie : c'est ce qui permet de
            // calculer le résultat d'un événement sans rien stocker en dur.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('PENDING');

            // Le fournisseur et la référence de la pièce, pour rapprocher un
            // justificatif papier d'une ligne de la base.
            $table->string('supplier', 160)->nullable();
            $table->string('reference', 120)->nullable();

            $table->date('spent_on');

            $table->foreignId('requested_by')->constrained('users');

            // Approbation. `approved_by` NE PEUT PAS être `requested_by` :
            // c'est la règle du double regard, tenue par la Policy.
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_reason')->nullable();

            // L'écriture au grand livre, créée SEULEMENT à l'approbation.
            $table->foreignId('financial_transaction_id')->nullable()
                ->constrained()->restrictOnDelete();

            $table->timestamps();

            // Pas de `softDeletes` : une dépense se refuse, elle ne s'efface
            // pas. Le bureau doit pouvoir expliquer un refus.

            $table->index(['status', 'spent_on']);
            $table->index('event_id');
            $table->index('transaction_category_id');
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
