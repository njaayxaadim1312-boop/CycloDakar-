<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les défis du club : « 500 km en septembre », « 8 sorties ce mois-ci ».
 *
 * LA CIBLE EST DANS L'UNITÉ SI, COMME PARTOUT AILLEURS.
 *
 * `target` est en mètres, en secondes ou en nombre de sorties selon `metric`.
 * « 500 km » se stocke donc 500 000. La conversion vers les kilomètres se fait
 * à l'affichage, comme pour les activités — un champ nommé `target` qui
 * contiendrait tantôt des kilomètres, tantôt des minutes, produirait tôt ou
 * tard un défi mille fois trop court.
 *
 * `sport` est facultatif : un défi peut porter sur tous les sports confondus
 * (« 500 km, à pied ou à vélo ») ou sur un seul. Le laisser NULL est le cas le
 * plus fréquent et ne doit rien coûter à écrire.
 *
 * POURQUOI PAS DE SUPPRESSION FRANCHE
 *
 * `softDeletes` : un défi auquel des membres ont participé fait partie de leur
 * histoire. L'effacer ferait disparaître les badges de gens qui les avaient
 * gagnés — ce qui est, pour un club, exactement le contraire du but.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('title', 160);
            $table->text('description')->nullable();

            // distance · activities · duration · elevation
            $table->string('metric', 20);
            // En unité SI : mètres, secondes, ou nombre de sorties.
            $table->unsignedBigInteger('target');

            // NULL = tous sports confondus.
            $table->string('sport', 20)->nullable();

            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status', 20)->default('DRAFT');

            // Une couleur d'accent et une icône, choisies par l'auteur : un
            // défi se reconnaît d'abord à son allure dans une liste.
            $table->string('icon', 40)->default('trophy');

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // La liste se lit « ce qui est en cours, par échéance ».
            $table->index(['status', 'ends_on']);
            $table->index('sport');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
