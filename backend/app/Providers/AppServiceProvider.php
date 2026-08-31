<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureRateLimiting();
    }

    /**
     * Garde-fous appliqués à tous les modèles Eloquent du projet.
     */
    private function configureModels(): void
    {
        // Interdit l'assignation de masse non déclarée : sur une API qui
        // manipule des rôles et des montants, un `fillable` oublié est une
        // faille. On préfère une exception bruyante en développement.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Interdit d'accéder à une relation non chargée (détecte les N+1
        // dès le développement — critique avec des milliers de points GPS).
        Model::preventLazyLoading(! app()->isProduction());

        Model::unguard(false);
    }

    /**
     * Limiteurs de débit.
     *
     * Trois régimes très différents cohabitent :
     *  - la connexion, qu'il faut protéger du bourrinage ;
     *  - l'API courante ;
     *  - l'ingestion de points GPS, qui est légitimement intense à la fin
     *    d'une sortie (rattrapage d'une synchronisation hors ligne).
     */
    private function configureRateLimiting(): void
    {
        // Régime général de l'API.
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by($request->user()->id)
            : Limit::perMinute(30)->by($request->ip()));

        // Connexion : par identifiant ET par IP, pour ne pas qu'un attaquant
        // puisse verrouiller le compte d'un membre en épuisant sa limite.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('login', '')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // DEMANDE de réinitialisation : coûteuse (envoi de courriel), donc
        // très limitée.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // USAGE du lien reçu : compteur SÉPARÉ. Avec un compteur commun, un
        // membre qui a demandé cinq liens (parce qu'il ne les recevait pas)
        // se retrouverait incapable d'utiliser celui qui arrive enfin.
        // Reste limité : le jeton est aléatoire, mais on ne facilite pas
        // l'essai en force.
        RateLimiter::for('password-reset-confirm', fn (Request $request) => Limit::perHour(15)->by($request->ip()));

        // Synchronisation GPS : un membre rentrant d'une sortie de 3 h peut
        // envoyer 100 lots d'affilée. La limite est par utilisateur.
        RateLimiter::for('gps-sync', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        // Scan de QR Code sur le terrain : un collecteur enchaîne les scans.
        RateLimiter::for('qr-scan', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
