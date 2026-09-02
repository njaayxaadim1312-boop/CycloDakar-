<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'image de fond du compte — le « fond d'écran » de chaque membre.
 *
 * ELLE EST PERSONNELLE, ET C'EST TOUT L'INTÉRÊT.
 *
 * La photo de profil dit qui l'on est aux autres ; le fond d'écran dit à
 * quoi ressemble SON application quand on l'ouvre. Un membre qui met la
 * corniche au lever du jour derrière ses anneaux de la semaine s'approprie
 * l'outil — et un outil qu'on s'approprie s'ouvre plus souvent.
 *
 * SUR `members`, PAS SUR `users`.
 *
 * La fiche club porte déjà la photo, et c'est elle que voient les écrans du
 * membre. Un adhérent sans compte de connexion peut d'ailleurs avoir une
 * fiche : mettre le fond sur `users` le priverait d'une image que quelqu'un
 * aurait pu choisir pour lui.
 *
 * DISQUE PUBLIC, contrairement aux justificatifs de dépense.
 *
 * Une image de fond n'est pas une pièce confidentielle : c'est un décor, servi
 * à chaque chargement d'écran. La faire passer par une route authentifiée
 * coûterait une requête PHP par affichage pour protéger une photo de paysage.
 * La photo de profil suit déjà cette règle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('cover_path', 255)->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
};
