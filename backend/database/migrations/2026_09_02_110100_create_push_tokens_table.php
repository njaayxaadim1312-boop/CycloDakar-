<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les jetons de notification push, un par appareil.
 *
 * UN MEMBRE, PLUSIEURS APPAREILS.
 *
 * Le même compte peut vivre sur un téléphone et une tablette. Stocker un seul
 * jeton par utilisateur ferait taire l'appareil précédent à chaque nouvelle
 * connexion — et personne ne comprendrait pourquoi les notifications se sont
 * arrêtées sur son ancien téléphone.
 *
 * LE JETON EST UNIQUE, PAS LE COUPLE (UTILISATEUR, JETON).
 *
 * Un appareil prêté, revendu ou partagé garde son jeton Expo. Si quelqu'un
 * d'autre s'y connecte, le jeton doit CHANGER DE PROPRIÉTAIRE, pas être
 * dupliqué : sans quoi l'ancien utilisateur continuerait de recevoir sur un
 * téléphone qui n'est plus le sien. L'unicité porte donc sur le jeton seul.
 *
 * PAS DE JETON, PAS DE PUSH.
 *
 * C'est aussi le réglage : se désabonner revient à supprimer son jeton. Une
 * table de préférences par catégorie serait plus fine, mais elle n'aurait de
 * sens qu'une fois qu'on saura quelles notifications gênent réellement — et
 * on ne le saura qu'en les envoyant. Les notifications EN BASE, elles,
 * arrivent toujours : elles ne réveillent personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // `ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]` — jusqu'à ~200
            // caractères selon la plateforme.
            $table->string('token', 255)->unique();

            // « iPhone de Khadim », « Redmi Note 12 ». Ce qui permet à un
            // membre de reconnaître l'appareil qu'il veut débrancher.
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 20)->nullable();

            // Dernière fois que ce jeton a servi. Un jeton silencieux depuis
            // six mois désigne un téléphone perdu ou une application
            // désinstallée : c'est ce qui permettra de faire le ménage.
            $table->dateTime('last_used_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
