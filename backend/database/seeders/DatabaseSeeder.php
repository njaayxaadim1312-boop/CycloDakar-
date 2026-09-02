<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée des données initiales.
 *
 * Le trait `WithoutModelEvents` du squelette Laravel a été retiré
 * volontairement : il désactive les événements Eloquent, or `User::creating`
 * est ce qui génère l'`uuid` public. Avec le trait, tout enregistrement créé
 * par un seeder partirait sans identifiant public.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            // La caisse et les postes du grand livre. Idempotent : sans caisse
            // par defaut, aucun encaissement n'est possible.
            FinanceSeeder::class,
        ]);
    }
}
