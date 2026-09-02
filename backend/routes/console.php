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

/**
 * Fige les classements des périodes closes (phase 16).
 *
 * 3 h 20 : après la vérification de caisse, et à une heure où personne
 * n'enregistre de sortie — une lecture des activités ne croise ainsi aucune
 * écriture.
 *
 * La commande ne fige jamais une période en cours, et refiger une période déjà
 * figée la réécrit à l'identique. Elle peut donc tourner tous les jours sans
 * qu'on ait à raisonner sur le calendrier.
 */
Schedule::command('cyclo:snapshot-leaderboards')
    ->dailyAt('03:20')
    ->withoutOverlapping();
