<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes de collecte : « Sortie Lac Rose — 5 000 FCFA ».
 *
 * `expected_amount` est un **BIGINT de francs CFA**, jamais un decimal. Le XOF
 * n'a pas de subdivision en usage : un flottant n'apporterait qu'une classe de
 * bugs d'arrondi sur une monnaie qui n'en a pas besoin. Règle I5 de
 * `docs/finance.md`.
 *
 * `dateTime()` et non `timestamp()` — rappel du piège MariaDB où la première
 * colonne TIMESTAMP NOT NULL reçoit implicitement ON UPDATE CURRENT_TIMESTAMP.
 * Ici les dates sont des DATE, mais la règle vaut pour la suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Une collecte se rattache souvent à une sortie, mais pas toujours :
            // la cotisation annuelle n'a pas d'événement.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 160);
            $table->text('description')->nullable();

            // Montant unitaire attendu, en FCFA. Entier, toujours.
            $table->unsignedBigInteger('expected_amount');

            $table->date('starts_on');
            $table->date('due_on');

            $table->string('status', 20)->default('DRAFT');

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // La liste se lit « ce qui est ouvert, par échéance ».
            $table->index(['status', 'due_on']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participations');
    }
};
