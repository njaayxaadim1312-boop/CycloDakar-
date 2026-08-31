<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit.
 *
 * Introduit dès la phase 3 parce que le changement de rôle en a besoin
 * immédiatement : donner l'accès à la caisse est l'acte le plus sensible du
 * module membres, et il doit pouvoir s'expliquer six mois plus tard en
 * assemblée générale.
 *
 * La table est dimensionnée dès maintenant pour ce qu'exige le module
 * financier (phase 13) : auteur, entité, valeurs avant/après, motif.
 * La consultation depuis l'interface arrive en phase 19.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nul si l'action vient d'une commande ou d'un traitement planifié.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // « member.role_changed », « payment.created », « expense.approved »...
            $table->string('action', 60);

            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 255)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Pas d'`updated_at` : une ligne d'audit ne se modifie jamais.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
