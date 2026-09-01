<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service de l'application web
|--------------------------------------------------------------------------
|
| En developpement, le web tourne sur Vite (port 5173) et relaie /api vers
| Laravel. Pour une demonstration accessible depuis un telephone, on veut au
| contraire UNE SEULE adresse : Laravel sert alors le web deja construit,
| depose dans `public/app/`.
|
| L'interet n'est pas cosmetique. Avec deux origines, il faut configurer CORS,
| exposer deux tunnels et tenir deux URL a jour dans trois fichiers. Avec une
| seule, `VITE_API_URL=/api/v1` fonctionne tel quel et il n'y a rien a
| configurer.
|
| `php artisan cyclo:build-web` construit et depose le tout.
|
*/

/**
 * Repli SPA.
 *
 * Toute adresse qui n'est ni `/api/...`, ni un fichier reel, renvoie
 * `index.html` : c'est React Router qui decide quoi afficher. Sans cela,
 * ouvrir `/events` directement — ou rafraichir la page — donnerait un 404,
 * alors que la route existe cote navigateur.
 *
 * La contrainte `^(?!api).*$` est essentielle : sans elle, cette route
 * capterait aussi les appels d'API et l'application recevrait du HTML la ou
 * elle attend du JSON.
 */
Route::get('/{path?}', function (?string $path = null) {
    $index = public_path('app/index.html');

    if (! File::exists($index)) {
        // Message utile plutot qu'une page blanche : c'est l'erreur la plus
        // probable la premiere fois.
        return response(
            "<h1>Cyclo Dakar</h1><p>L'application web n'est pas encore construite.</p>"
            .'<p>Lancez <code>php artisan cyclo:build-web</code> depuis <code>backend/</code>.</p>',
            503,
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    return response(File::get($index))
        ->header('Content-Type', 'text/html; charset=utf-8')
        // L'index ne doit JAMAIS etre mis en cache : il porte les noms des
        // fichiers JS et CSS, qui changent a chaque construction. Un index
        // en cache renverrait vers des fichiers qui n'existent plus.
        ->header('Cache-Control', 'no-store, must-revalidate');
})->where('path', '^(?!api).*$')->name('spa');
