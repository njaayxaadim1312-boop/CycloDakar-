<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le membre × la collecte. **Une ligne = une dette.**
 *
 * Deux colonnes méritent une explication.
 *
 * `expected_amount` est une COPIE FIGÉE du montant de la campagne. Relever le
 * tarif d'une collecte en cours ne doit pas réécrire ce que devaient les
 * membres déjà appelés — sinon on ne pourrait plus expliquer un encaissement
 * de 5 000 FCFA sur une dette affichée à 7 500.
 *
 * `paid_amount` est **dérivé** de la somme des paiements non annulés. Il est
 * stocké pour éviter d'agréger la table des paiements à chaque affichage de
 * liste, mais il n'est JAMAIS reçu du client : il se recalcule à chaque
 * mouvement (PHASE 12). L'accepter en entrée reviendrait à laisser quiconque
 * se déclarer à jour de cotisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participation_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('participation_id')->constrained()->cascadeOnDelete();
            // `restrictOnDelete` et non `cascade` : supprimer une fiche membre
            // ne doit pas effacer ses dettes en silence. On archive un membre,
            // on ne le fait pas disparaître des comptes.
            $table->foreignId('member_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('expected_amount');
            $table->unsignedBigInteger('paid_amount')->default(0);

            $table->string('status', 20)->default('NON_PAYE');

            // Le collecteur responsable de cette ligne. NULL = personne encore.
            $table->foreignId('assigned_collector_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->dateTime('last_payment_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // Un membre ne peut pas devoir deux fois la même collecte, quoi
            // que fasse le client.
            $table->unique(['participation_id', 'member_id']);
            $table->index(['participation_id', 'status']);
            $table->index('member_id');
            $table->index('assigned_collector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_members');
    }
};
