<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La caisse du club.
 *
 * **`current_balance` est un CACHE DE LECTURE, pas la vérité** (règle I1 de
 * `docs/finance.md`). La vérité est :
 *
 *     solde = opening_balance + Σ(entrées) − Σ(sorties)
 *
 * La colonne existe pour ne pas agréger tout le grand livre à chaque
 * affichage d'un tableau de bord. `php artisan finance:recompute-balance`
 * recalcule depuis zéro et **échoue bruyamment** en cas d'écart : un cache qui
 * dérive en silence est pire que pas de cache du tout.
 *
 * Aucune route de l'API n'accepte un solde en entrée. Il n'existe pas de
 * `PUT /finance/balance`, et il ne doit jamais en exister.
 *
 * `opening_balance` est le seul montant que le club saisit lui-même : ce que
 * contenait la caisse le jour où l'application a été mise en service. Sans
 * lui, il faudrait ressaisir dix ans d'historique pour afficher un solde juste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // Entiers de FCFA, toujours (règle I5). Le solde d'ouverture peut
            // être négatif — un club qui démarre avec une dette existe.
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('current_balance')->default(0);

            // La caisse utilisée par défaut à l'encaissement. Une seule à la
            // fois : l'unicité est tenue par le service, pas par un index
            // partiel — MySQL n'en a pas.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_accounts');
    }
};
