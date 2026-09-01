<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscriptions et présences.
 *
 * `UNIQUE(event_id, member_id)` est la garantie de fond : un membre ne peut
 * pas figurer deux fois sur la même sortie, quoi que fasse le client. Une
 * inscription annulée puis reprise réutilise la même ligne — c'est ce qui
 * permet de distinguer « ne s'est jamais inscrit » de « s'est désisté ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('registration_status', 20)->default('REGISTERED');
            $table->string('attendance_status', 20)->default('UNKNOWN');

            // Ordre d'arrivée sur la liste d'attente. Il ne change JAMAIS,
            // même après un désistement : celui qui s'est inscrit en premier
            // reste devant. Le recalculer à chaque mouvement ferait remonter
            // ou descendre des membres sans qu'ils comprennent pourquoi.
            $table->unsignedInteger('queue_position')->nullable();

            $table->dateTime('registered_at')->nullable();
            $table->dateTime('checked_in_at')->nullable();

            // Qui a pointé. Vient de la session, jamais du client.
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            // La sortie GPS réellement enregistrée ce jour-là, s'il y en a une.
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['event_id', 'member_id']);
            $table->index(['event_id', 'registration_status']);
            $table->index(['member_id', 'registration_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participants');
    }
};
