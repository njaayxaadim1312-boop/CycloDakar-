<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();

            /*
             * L'uuid est GÉNÉRÉ PAR LE CLIENT, contrairement à toutes les
             * autres entités du projet.
             *
             * C'est ce qui rend la synchronisation idempotente : le téléphone
             * crée l'identifiant au démarrage de la sortie, hors ligne, et
             * peut ensuite rejouer l'envoi autant de fois qu'il le faut sans
             * jamais créer de doublon. Sans cela, une coupure réseau au
             * mauvais moment produirait deux fois la même sortie.
             */
            $table->uuid('uuid')->unique();

            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('sport', 20);
            $table->string('title', 140)->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default(ActivityStatus::Recording->value);
            $table->string('visibility', 15)->default(ActivityVisibility::Club->value);

            /*
             * `dateTime` et NON `timestamp`.
             *
             * Sous MySQL/MariaDB avec `explicit_defaults_for_timestamp` a OFF
             * (le defaut de MariaDB 10.4), la PREMIERE colonne TIMESTAMP NOT
             * NULL d'une table recoit implicitement
             * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
             *
             * Consequence concrete : chaque mise a jour de l'activite --
             * c'est-a-dire chaque finalisation -- reecrivait l'heure de
             * depart. Un membre synchronisant chez lui trois heures apres sa
             * sortie aurait vu celle-ci commencer a l'heure ou il a ouvert
             * l'application.
             *
             * DATETIME n'a pas ce comportement, et pas non plus la limite de
             * 2038 -- ce qui compte pour un club qui archivera ses sorties.
             */
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            /*
             * UNE SEULE UNITÉ EN BASE : mètres, secondes, m/s. Toute
             * conversion (km, km/h, min/km) est faite à l'affichage. Cela
             * supprime une classe entière de bugs — celle où l'on stocke des
             * kilomètres dans un champ nommé « distance_m ».
             *
             * Toutes ces valeurs sont RECALCULÉES par le serveur à la
             * finalisation, à partir des points bruts. Ce que le client
             * envoie n'est jamais cru.
             */
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('duration_s')->default(0);
            $table->unsignedInteger('moving_time_s')->default(0);
            $table->unsignedInteger('paused_time_s')->default(0);

            $table->decimal('avg_speed_mps', 6, 3)->default(0);
            $table->decimal('max_speed_mps', 6, 3)->default(0);

            $table->integer('elevation_gain_m')->default(0);
            $table->integer('elevation_loss_m')->default(0);
            $table->integer('min_altitude_m')->nullable();
            $table->integer('max_altitude_m')->nullable();

            // Allure en secondes par kilomètre (course et randonnée).
            $table->unsignedInteger('avg_pace_s_per_km')->nullable();
            $table->unsignedInteger('best_pace_s_per_km')->nullable();

            $table->unsignedInteger('calories_kcal')->nullable();

            /*
             * Trace simplifiée (Douglas-Peucker) puis encodée au format
             * Google Encoded Polyline : ~1 Ko au lieu de ~500 Ko.
             *
             * C'est ELLE que consomment les listes, les miniatures et les
             * cartes. Les points bruts ne sont relus que pour l'export GPX et
             * le rendu vidéo. Voir docs/architecture.md, ADR-002.
             */
            $table->mediumText('polyline')->nullable();
            $table->json('bounds')->nullable();

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 11, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 11, 7)->nullable();

            // Zones traversées, résolues par reverse-geocoding groupé (phase 7).
            $table->json('zones')->nullable();

            $table->unsignedInteger('points_count')->default(0);
            // Nombre de points AVANT filtrage : c'est la mesure de la qualité
            // du signal, et le premier indice quand une trace paraît fausse.
            $table->unsignedInteger('raw_points_count')->default(0);

            $table->json('device_info')->nullable();
            $table->dateTime('synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'started_at']);
            $table->index('sport');
            $table->index('status');
            $table->index('started_at');
        });

        /*
         * Trace GPS brute — la table la plus volumineuse du système.
         * ~10 800 points pour une sortie de 3 h à 1 Hz.
         */
        Schema::create('activity_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();

            // Position dans la trace, décidée par le CLIENT. C'est elle qui
            // rend le rejeu d'un lot inoffensif (voir la contrainte unique).
            $table->unsignedInteger('seq');

            // DECIMAL et non FLOAT : précision d'environ 1 cm, exacte, sans
            // dérive d'arrondi sur des centaines de millions de lignes.
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 11, 7);

            $table->decimal('altitude_m', 7, 2)->nullable();
            $table->decimal('speed_mps', 6, 3)->nullable();
            $table->decimal('accuracy_m', 6, 2)->nullable();
            $table->decimal('heading_deg', 5, 2)->nullable();

            // Milliseconde : à 1 Hz, la seconde entière ne suffit pas à
            // ordonner deux points proches.
            // `dateTime` pour la même raison que ci-dessus.
            $table->dateTime('recorded_at', 3);

            $table->boolean('is_paused')->default(false);

            /*
             * LA contrainte qui rend la synchronisation sûre : rejouer un lot
             * déjà reçu ne crée aucun doublon, il est simplement ignoré.
             * Sans elle, une réémission après coupure réseau doublerait la
             * distance de la sortie.
             */
            $table->unique(['activity_id', 'seq']);
            $table->index(['activity_id', 'recorded_at']);

            // Pas de `timestamps` : deux colonnes de plus multipliées par des
            // centaines de millions de lignes, pour une information que
            // `recorded_at` porte déjà.
        });

        /*
         * Séries agrégées lourdes, calculées une fois à la finalisation.
         * Les recalculer à chaque affichage relirait les points bruts.
         */
        Schema::create('activity_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();

            $table->json('splits')->nullable();            // un objet par kilomètre
            $table->json('elevation_profile')->nullable(); // série réduite à ~200 points
            $table->json('speed_histogram')->nullable();

            $table->timestamp('computed_at');
        });

        /*
         * Traçabilité de la synchronisation mobile.
         *
         * Sans ce journal, une trace anormalement courte serait impossible à
         * expliquer : on ne saurait pas si le GPS était mauvais, si des lots
         * se sont perdus, ou si le filtre a été trop sévère.
         */
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('device_id', 120)->nullable();
            $table->unsignedInteger('points_received')->default(0);
            $table->unsignedInteger('points_accepted')->default(0);
            $table->unsignedInteger('points_rejected')->default(0);

            // Compteur par motif de rejet : precision, saut, duplicat...
            $table->json('rejection_reasons')->nullable();

            $table->dateTime('created_at');

            $table->index(['activity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('activity_stats');
        Schema::dropIfExists('activity_points');
        Schema::dropIfExists('activities');
    }
};
