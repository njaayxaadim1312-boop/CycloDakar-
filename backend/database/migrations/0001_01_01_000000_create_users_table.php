<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Identifiant public : c'est lui qui circule dans les URL et les
            // réponses d'API, jamais l'auto-incrément, qui divulguerait le
            // nombre de comptes et permettrait de les énumérer.
            $table->uuid('uuid')->unique();

            $table->string('name', 120);

            // Deux identifiants de connexion possibles. Au Sénégal, beaucoup de
            // membres n'ont pas d'adresse email courante : le téléphone est
            // l'identifiant principal, l'email est facultatif.
            // Contrainte applicative : au moins l'un des deux est renseigné.
            $table->string('email', 180)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();

            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            $table->string('role', 20)->default(UserRole::Member->value);

            // Désactivation sans suppression : un ancien membre garde son
            // historique d'activités et ses paiements restent rattachés.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('role');
            $table->index('is_active');
        });

        /*
         * Réinitialisation de mot de passe.
         *
         * La colonne s'appelle `email` parce que c'est le nom qu'attend le
         * gestionnaire de mots de passe de Laravel, mais elle contient en
         * réalité l'IDENTIFIANT de connexion — email ou téléphone normalisé —
         * afin qu'un membre sans adresse email puisse tout de même être
         * traité par le même mécanisme le jour où l'envoi par SMS sera ajouté.
         */
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 180)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
