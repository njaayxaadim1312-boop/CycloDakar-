<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les justificatifs d'une dépense : facture, reçu, bordereau.
 *
 * **LE FICHIER N'EST JAMAIS SERVI DEPUIS `public/`.**
 *
 * Un justificatif porte le nom d'un fournisseur, un montant, parfois un numéro
 * de compte. Le déposer dans `public/storage` le rendrait accessible à qui
 * devine l'URL, sans authentification et sans trace. Il vit donc sur le disque
 * privé, et une route contrôlée le renvoie — c'est elle qui vérifie le rôle.
 *
 * `path` est un chemin interne, jamais exposé tel quel : le client reçoit une
 * URL de téléchargement construite à partir de l'uuid. Sans cela, la
 * structure du stockage fuiterait dans l'API, et changer d'arborescence
 * casserait des clients.
 *
 * On garde `original_name` : « facture-transport-lac-rose.pdf » aide bien plus
 * un trésorier qu'un identifiant aléatoire, et on ne peut pas se fier au nom
 * d'origine pour nommer le fichier sur le disque — il peut contenir n'importe
 * quoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();

            // Chemin sur le disque PRIVÉ. Ne sort jamais de l'API.
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->foreignId('uploaded_by')->constrained('users');

            $table->timestamps();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
    }
};
