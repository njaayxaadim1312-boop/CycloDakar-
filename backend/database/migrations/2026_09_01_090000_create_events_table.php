<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements du club : les sorties officielles.
 *
 * Rappel du piège MariaDB (voir la migration des activités) : avec
 * `explicit_defaults_for_timestamp` à OFF — le défaut de MariaDB 10.4 — la
 * PREMIÈRE colonne `TIMESTAMP NOT NULL` reçoit implicitement
 * `ON UPDATE CURRENT_TIMESTAMP`. La date de départ d'un événement serait
 * réécrite à chaque modification de la ligne. On utilise donc `dateTime()`,
 * qui n'a pas ce comportement et pas de limite en 2038.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('sport', 20);

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();

            $table->string('location_name', 160);
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();

            // Mètres, comme partout ailleurs (règle des unités SI).
            $table->unsignedInteger('planned_distance_m')->nullable();
            $table->mediumText('route_polyline')->nullable();

            $table->string('difficulty', 20)->nullable();
            $table->string('cover_path', 255)->nullable();

            $table->string('status', 20)->default('DRAFT');

            // NULL = pas de limite. Un zéro voudrait dire « aucune place »,
            // ce qui n'est pas la même chose.
            $table->unsignedSmallInteger('max_participants')->nullable();

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // La liste des événements se lit presque toujours « à venir, par
            // date » : c'est cet index-là qui la sert.
            $table->index(['status', 'starts_at']);
            $table->index('starts_at');
            $table->index('sport');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
