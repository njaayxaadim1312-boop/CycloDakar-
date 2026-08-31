<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache des zones geographiques.
 *
 * Nominatim (OpenStreetMap) limite a UNE requete par seconde. Resoudre les
 * zones point par point serait donc a la fois impossible et impoli : une
 * sortie de 46 km compte 10 000 points.
 *
 * On projette la trace sur une grille de 0,02 degre (~2,2 km), on ne garde que
 * les cellules distinctes -- typiquement 3 a 15 pour une sortie -- et on met
 * le resultat en cache DEFINITIVEMENT. Dakar est un territoire fini : apres
 * quelques semaines de sorties, le cache couvre tout et plus aucun appel
 * externe n'est necessaire.
 *
 * Voir docs/gps.md §11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_zones_cache', function (Blueprint $table) {
            $table->id();

            // Cle de la cellule : « 14.68,-17.46 ». Deux points de la meme
            // cellule partagent donc la meme resolution.
            $table->string('cell_key', 32)->unique();

            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 11, 7);

            // Libelle affiche : « Ouakam », « Ngor », « Almadies ».
            $table->string('label', 120)->nullable();
            // Echelon superieur, pour desambiguiser deux quartiers homonymes.
            $table->string('city', 120)->nullable();
            $table->string('country_code', 2)->nullable();

            // Reponse brute conservee : si l'on change un jour de fournisseur
            // ou de facon d'extraire le libelle, on peut rejouer sans
            // reinterroger le service externe.
            $table->json('raw')->nullable();

            // Une cellule peut etre resolue SANS libelle : pleine mer, zone
            // non cartographiee. On l'enregistre quand meme, sinon on
            // reinterrogerait Nominatim a chaque sortie qui la traverse.
            $table->boolean('resolved')->default(false);

            $table->timestamps();

            $table->index('resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_zones_cache');
    }
};
