<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les notifications, en base.
 *
 * Table de Laravel, mais RÉÉCRITE À LA MAIN pour deux raisons.
 *
 * `dateTime()` et non `timestamp()`. Sur MariaDB, la première colonne TIMESTAMP
 * d'une table reçoit implicitement `ON UPDATE CURRENT_TIMESTAMP` : `read_at`
 * se serait remise à jour toute seule à chaque écriture sur la ligne, et une
 * notification lue aurait changé de date de lecture sans que personne n'y
 * touche. Le piège est documenté depuis la phase 1 ; le générateur ne le
 * connaît pas.
 *
 * Un INDEX sur `(notifiable, read_at)`. La requête faite à chaque ouverture de
 * l'application est « combien de non-lues pour moi ». Sans index, elle balaie
 * toute la table — et cette table-là grossit plus vite que toutes les autres.
 *
 * POURQUOI LA TABLE DE LARAVEL PLUTÔT QU'UNE TABLE MAISON
 *
 * Elle apporte gratuitement `unreadNotifications`, `markAsRead`, la relation
 * polymorphe et la sérialisation. Une table maison aurait demandé de réécrire
 * tout cela, et surtout de le maintenir. Le prix payé est un `data` en JSON
 * plutôt que des colonnes typées : acceptable, puisque chaque type de
 * notification définit sa propre forme et que rien n'a besoin d'être filtré
 * dessus en SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // La classe de la notification : c'est elle qui décide de la forme
            // de `data` et du rendu côté client.
            $table->string('type');

            // Polymorphe : aujourd'hui des `User`, demain peut-être autre
            // chose. C'est la convention de Laravel, et la respecter garde
            // `Notifiable` utilisable tel quel.
            $table->morphs('notifiable');

            $table->text('data');

            $table->dateTime('read_at')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // La requête de chaque ouverture d'application : les non-lues de
            // quelqu'un, les plus récentes d'abord.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
