<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement d'une sortie GPS à un événement du club.
 *
 * La colonne était prévue dès la conception (`docs/database.md`) mais ne
 * pouvait pas exister avant la table `events`. Elle reste NULLABLE : la
 * plupart des sorties sont individuelles, et c'est très bien ainsi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('event_id')
                ->nullable()
                ->after('member_id')
                // `nullOnDelete` et non `cascade` : supprimer un événement ne
                // doit surtout pas effacer les sorties de ses participants.
                ->constrained()
                ->nullOnDelete();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropIndex(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};
