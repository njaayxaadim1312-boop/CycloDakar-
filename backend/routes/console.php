<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
*/

/**
 * Vérification nocturne du solde de caisse (règle I1 de docs/finance.md).
 *
 * SANS `--fix`, ET C'EST VOLONTAIRE. Un écart signifie qu'une écriture est
 * passée hors de `CashLedger` ; le réparer automatiquement chaque nuit
 * masquerait la cause et la laisserait agir, jusqu'au jour où le trésorier
 * découvrirait un trou sans historique pour l'expliquer. La commande sort en
 * échec, ce qui se voit.
 *
 * 3 h du matin : personne n'encaisse à cette heure-là, la lecture du grand
 * livre ne croise donc aucune écriture.
 */
Schedule::command('finance:recompute-balance')
    ->dailyAt('03:00')
    ->withoutOverlapping();
