<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le classement d'une période RÉVOLUE, figé.
 *
 * POURQUOI FIGER, PLUTÔT QUE DE RECALCULER À CHAQUE FOIS
 *
 * Ce n'est pas d'abord une question de performance. C'est une question
 * d'honnêteté envers les membres.
 *
 * Un classement mensuel se recalcule à l'identique tant que rien ne bouge —
 * mais les sorties, elles, bougent : le mobile synchronise en différé, un
 * membre passe une sortie en privé une semaine plus tard, une trace est
 * corrigée. Recalculé, le classement de septembre changerait donc en octobre,
 * après que le club a félicité quelqu'un. Reprendre une première place est le
 * plus sûr moyen de faire quitter un club.
 *
 * Une période close est un fait, comme une collecte clôturée. On l'arrête, on
 * la garde, et on ne la retouche plus.
 *
 * LA PÉRIODE EN COURS N'EST JAMAIS FIGÉE : elle se calcule en direct, puisque
 * par définition elle n'est pas finie. `cyclo:snapshot-leaderboards` fige une
 * période le jour où elle s'achève.
 *
 * `period_key` porte la période sous forme lisible et triable :
 * `2026-W36`, `2026-09`, `2026`. Un texte plutôt que deux dates : c'est ce
 * qu'on cherche — « le classement de septembre » — et cela rend l'unicité
 * évidente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();

            // week · month · year
            $table->string('period', 10);
            // `2026-W36`, `2026-09`, `2026`
            $table->string('period_key', 12);

            // distance · activities · duration · elevation
            $table->string('metric', 20);
            // NULL = tous sports confondus.
            $table->string('sport', 20)->nullable();

            $table->foreignId('member_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('value');
            // Le nombre de sorties qui composent ce total : un classement à la
            // distance sans le nombre de sorties ne dit pas si c'est une
            // grosse sortie ou de la régularité.
            $table->unsignedInteger('activities')->default(0);

            $table->dateTime('captured_at');

            // Un membre n'apparaît qu'une fois par classement. L'index sert
            // aussi la lecture, qui se fait toujours par ces quatre colonnes.
            $table->unique(
                ['period', 'period_key', 'metric', 'sport', 'member_id'],
                'leaderboard_unique_entry',
            );
            $table->index(['period', 'period_key', 'metric', 'sport', 'rank'], 'leaderboard_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_snapshots');
    }
};
