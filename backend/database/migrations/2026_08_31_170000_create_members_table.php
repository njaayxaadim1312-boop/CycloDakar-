<?php

declare(strict_types=1);

use App\Enums\MemberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Lien vers le compte de connexion — VOLONTAIREMENT FACULTATIF.
             *
             * Un membre du club n'a pas forcément de smartphone. Il doit
             * pourtant avoir un matricule, un QR Code, apparaître dans les
             * collectes et figurer dans l'effectif. Le compte pourra lui être
             * rattaché plus tard, sans rien recréer.
             *
             * (Écart assumé par rapport au schéma de la phase 1, qui rendait
             * ce lien obligatoire — voir docs/database.md.)
             */
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained()->nullOnDelete();

            /*
             * Matricule club : CD-000001.
             * Généré sous verrou (voir App\Services\MatriculeGenerator) et
             * jamais réattribué, même après le départ d'un membre.
             */
            $table->string('matricule', 16)->unique();

            $table->string('first_name', 80);
            $table->string('last_name', 80);

            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 180)->nullable();

            $table->string('photo_path', 255)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();

            $table->date('joined_at');
            $table->string('status', 20)->default(MemberStatus::Active->value);

            /*
             * Jeton du QR Code personnel.
             *
             * Aléatoire et OPAQUE : il ne contient ni nom, ni téléphone, ni
             * matricule. Un QR photographié par un tiers ne révèle donc rien,
             * et il peut être révoqué (rotation) sans changer l'identité du
             * membre. Le rendu et le scan arrivent en phase 11.
             */
            $table->char('qr_token', 43)->unique();
            $table->timestamp('qr_rotated_at')->nullable();

            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('last_name');
            // Recherche « Kha » sur le nom complet : l'index composite couvre
            // le tri alphabétique de l'annuaire.
            $table->index(['last_name', 'first_name']);
            $table->index('joined_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
