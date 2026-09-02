<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les encaissements. Une ligne = un franc reçu d'un membre, une fois.
 *
 * IDEMPOTENCE — `idempotency_key`, unique, vient du CLIENT.
 *
 * C'est la colonne la plus importante de cette table. Un collecteur encaisse
 * dans une zone sans réseau : la requête part, la réponse se perd, le
 * téléphone réessaie. Sans cette clé, le membre est débité deux fois et la
 * caisse affiche 10 000 FCFA là où 5 000 sont entrés. Avec elle, la seconde
 * requête retrouve le paiement existant et le renvoie tel quel — l'unicité
 * étant tenue par la BASE, deux requêtes vraiment simultanées ne peuvent pas
 * passer toutes les deux.
 *
 * ANNULATION — par contre-passation, jamais par suppression.
 *
 * `cancelled_at` n'efface rien : le montant reste, la ligne reste, et une
 * écriture de sens inverse est ajoutée au grand livre. Le tampon sert à
 * exclure le paiement de la somme encaissée, et il pointe la contre-passation
 * qui l'a neutralisé. Un reçu remis à un membre reste donc toujours
 * retrouvable, même annulé — ce qui est exactement ce qu'on veut quand
 * quelqu'un se présente avec son papier.
 *
 * `receipt_number` est le numéro de reçu, attribué en séquence dans l'année.
 * Il n'est pas décoratif : c'est ce qu'un membre montre quand il conteste, et
 * ce qui permet de rapprocher un carnet à souches d'une base de données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('receipt_number', 24)->unique();

            $table->foreignId('participation_member_id')->constrained()->restrictOnDelete();
            // Dénormalisés pour que « tous les paiements d'un membre » et
            // « tout ce qu'a encaissé cette collecte » ne demandent pas de
            // jointure. Ils ne sont jamais reçus du client : le service les
            // déduit de la ligne de dette.
            $table->foreignId('participation_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('method', 20);
            // Identifiant Wave / Orange Money / bordereau de virement.
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();

            // La clé d'idempotence : voir l'en-tête. UNIQUE, sans exception.
            $table->string('idempotency_key', 80)->unique();

            // Qui a encaissé — pris dans la SESSION, jamais dans la requête
            // (règle I3). C'est ce qui rend le rapport « collectes par
            // collecteur » digne de foi.
            $table->foreignId('collected_by')->constrained('users');

            // Date métier de l'encaissement, distincte du jour de la saisie.
            $table->date('paid_on');

            $table->foreignId('financial_transaction_id')->nullable()
                ->constrained()->restrictOnDelete();

            // Annulation : tampon + motif + contre-passation. Rien n'est effacé.
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('reversal_transaction_id')->nullable()
                ->constrained('financial_transactions')->restrictOnDelete();

            $table->timestamps();

            // Pas de `softDeletes` : un encaissement ne se supprime pas.

            $table->index(['participation_id', 'cancelled_at']);
            $table->index(['member_id', 'cancelled_at']);
            $table->index(['collected_by', 'paid_on']);
            $table->index('participation_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
