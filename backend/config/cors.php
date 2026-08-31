<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS — Cyclo Dakar
|--------------------------------------------------------------------------
|
| Les origines autorisées sont énumérées explicitement (jamais '*') : l'API
| manipule des données financières et de géolocalisation. En développement,
| la liste couvre Vite (web) et Metro/Expo (mobile). En production, ne laisser
| que le domaine du club.
|
| Le mobile n'est pas soumis au CORS (pas de navigateur), mais Expo Web l'est.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    // Expo publie sur des ports variables en développement local.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}):\d{4,5}$#']
        : [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        // Anti-rejeu des paiements et de la synchronisation GPS.
        'Idempotency-Key',
        // Identifie l'appareil mobile dans les journaux de synchronisation.
        'X-Device-Id',
    ],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 3600,

    // true seulement si le web passe par les cookies Sanctum plutôt que par
    // un token Bearer. Le projet utilise les tokens : on reste à false.
    'supports_credentials' => false,

];
