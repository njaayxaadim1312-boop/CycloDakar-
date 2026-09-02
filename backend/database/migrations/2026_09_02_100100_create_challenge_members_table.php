<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un membre inscrit à un défi.
 *
 * `progress` EST UN CACHE, PAS LA VÉRITÉ.
 *
 * La vérité est la somme des sorties du membre sur la fenêtre du défi — c'est
 * elle qui fait foi, et elle se recalcule à la lecture. La colonne existe pour
 * classer cent participants sans agréger cent fois la table des activités, et
 * `cyclo:challenges-refresh` la remet en phase.
 *
 * Le même raisonnement que pour le solde de caisse : un chiffre stocké qui
 * peut diverger doit être ANNONCÉ comme un cache, et une commande doit pouvoir
 * le recalculer depuis zéro. La différence, ici, c'est qu'un écart de
 * progression est sans gravité — on ne perd pas d'argent, seulement un rang.
 *
 * `completed_at` N'EST PAS DÉRIVÉ, ET C'EST VOULU.
 *
 * Une fois l'objectif atteint, il l'est pour toujours : la date est figée. Si
 * elle était recalculée, une sortie supprimée ou repassée en privé ferait
 * disparaître un badge déjà obtenu — et reprendre une récompense est le plus
 * sûr moyen de faire quitter un club.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            // `restrictOnDelete` : on archive un membre, on ne le fait pas
            // disparaître de l'histoire du club.
            $table->foreignId('member_id')->constrained()->restrictOnDelete();

            // Cache de la progression, dans l'unité de la mesure du défi.
            $table->unsignedBigInteger('progress')->default(0);

            // Figée. Voir l'en-tête : un badge obtenu ne se reprend pas.
            $table->dateTime('completed_at')->nullable();

            $table->dateTime('joined_at');

            $table->timestamps();

            // Un membre ne s'inscrit qu'une fois, quoi que fasse le client.
            $table->unique(['challenge_id', 'member_id']);
            $table->index(['challenge_id', 'progress']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_members');
    }
};
