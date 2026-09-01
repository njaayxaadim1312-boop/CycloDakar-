<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectifs hebdomadaires du membre.
 *
 * Ils existent pour une raison précise : les anneaux d'activité de l'écran
 * d'accueil n'ont de sens que rapportés à un objectif. Sans objectif, un
 * anneau ne peut qu'inventer une référence — et le projet interdit d'inventer
 * un chiffre.
 *
 * Les valeurs par défaut sont volontairement MODESTES (20 km, 2 h, 3 sorties
 * par semaine) : un objectif inatteignable dès la première semaine décourage,
 * alors qu'un objectif atteint donne envie de le relever. Le membre les
 * ajuste lui-même.
 *
 * Unités SI, comme partout : mètres et secondes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedInteger('weekly_distance_goal_m')->default(20000)->after('notes');
            $table->unsignedInteger('weekly_moving_time_goal_s')->default(7200)->after('weekly_distance_goal_m');
            $table->unsignedSmallInteger('weekly_activities_goal')->default(3)->after('weekly_moving_time_goal_s');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn([
                'weekly_distance_goal_m',
                'weekly_moving_time_goal_s',
                'weekly_activities_goal',
            ]);
        });
    }
};
