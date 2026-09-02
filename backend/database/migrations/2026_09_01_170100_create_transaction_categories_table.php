<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les postes du grand livre : « Participations », « Transport », « Dons »…
 *
 * Une catégorie porte un SENS (`IN` ou `OUT`) et ne peut pas servir dans
 * l'autre : classer une dépense de transport en recette produirait un rapport
 * annuel faux sans qu'aucune règle ne s'en aperçoive.
 *
 * `code` est stable et sert au code applicatif (`PARTICIPATION` est la
 * catégorie d'un encaissement de collecte) ; `name` est ce que lit un humain
 * et peut être renommé sans rien casser.
 *
 * `is_system` protège les catégories dont le code est utilisé en dur : on peut
 * les renommer, jamais les supprimer, sinon un encaissement n'aurait plus de
 * poste où se ranger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('direction', 3);

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['direction', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_categories');
    }
};
