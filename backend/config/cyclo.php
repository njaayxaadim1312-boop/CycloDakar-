<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration métier Cyclo Dakar
|--------------------------------------------------------------------------
|
| Regroupe tout ce qui est propre au club et aux règles de gestion, pour
| qu'aucune valeur métier ne soit codée en dur dans les contrôleurs ou les
| services. Les valeurs modifiables par l'administrateur en cours de vie de
| l'application vivent en base (table `settings`, phase 3) et prennent le pas
| sur celles-ci, qui servent de valeurs par défaut.
|
*/

return [

    'club' => [
        'name' => 'Cyclo Dakar',
        'founded_year' => 2025,
        'city' => 'Dakar',
        'country' => 'SN',
        'motto' => 'Ensemble, plus loin, plus forts !',
        // Fuseau d'AFFICHAGE. Le stockage reste en UTC (config/app.php).
        'timezone' => env('CLUB_TIMEZONE', 'Africa/Dakar'),
    ],

    /*
    | Matricule membre : CD-000001, CD-000002, ...
    | La génération est faite sous verrou de table pour garantir l'unicité
    | même en cas d'inscriptions simultanées (voir MemberService, phase 3).
    */
    'matricule' => [
        'prefix' => env('CLUB_MATRICULE_PREFIX', 'CD'),
        'padding' => 6,
        'separator' => '-',
    ],

    /*
    | Monnaie : le franc CFA n'a pas de subdivision en usage courant.
    | Tous les montants transitent et sont stockés en ENTIERS de FCFA.
    | Aucun flottant ne doit jamais toucher un montant. Voir docs/finance.md.
    */
    'currency' => [
        'code' => env('CLUB_CURRENCY', 'XOF'),
        'symbol' => 'FCFA',
        'decimals' => 0,
    ],

    /*
    | Sports supportés. La clé est la valeur stockée en base ; l'ajout d'un
    | sport ne demande aucune migration.
    |
    | - sample_interval_s : cadence de capture GPS visée
    | - min_distance_m    : déplacement minimal avant d'enregistrer un point
    | - max_accuracy_m    : au-delà, le point est rejeté (filtre n°2)
    | - max_speed_mps     : au-delà, c'est un saut GPS (filtre n°5)
    | - uses_pace         : afficher l'allure (min/km) plutôt que la vitesse
    */
    'sports' => [
        'CYCLING' => [
            'label' => 'Cyclisme',
            'icon' => 'bike',
            'sample_interval_s' => 1,
            'min_distance_m' => 5,
            'max_accuracy_m' => 25,
            'max_speed_mps' => 25.0,
            'uses_pace' => false,
            'met' => 8.0,
        ],
        'RUNNING' => [
            'label' => 'Course',
            'icon' => 'run',
            'sample_interval_s' => 1,
            'min_distance_m' => 3,
            'max_accuracy_m' => 20,
            'max_speed_mps' => 12.0,
            'uses_pace' => true,
            'met' => 9.8,
        ],
        'HIKING' => [
            'label' => 'Randonnée',
            'icon' => 'hike',
            'sample_interval_s' => 3,
            'min_distance_m' => 3,
            'max_accuracy_m' => 30,
            'max_speed_mps' => 6.0,
            'uses_pace' => true,
            'met' => 6.0,
        ],
    ],

    /*
    | Paramètres de l'algorithme GPS. Documentés dans docs/gps.md ; le mobile
    | récupère ces valeurs via GET /api/v1/config afin que client et serveur
    | filtrent exactement de la même façon.
    */
    'gps' => [
        // Acquisition : nombre de points nets consécutifs avant de démarrer.
        'warmup_points' => 3,
        'warmup_accuracy_m' => 20,
        // Un point plus vieux que cela au démarrage vient du cache de l'OS.
        'stale_fix_max_age_s' => 10,
        // Filtre n°6 : accélération humainement impossible.
        'max_acceleration_mps2' => 5.0,
        // Sous cette vitesse pendant `auto_pause_after_s`, on est à l'arrêt.
        'idle_speed_mps' => 0.8,
        'auto_pause_after_s' => 10,
        // Segment plus court que cela : bruit à l'arrêt, non compté.
        'min_segment_m' => 1.0,
        // Dénivelé : hystérésis anti-bruit barométrique/GPS.
        'elevation_threshold_m' => 3.0,
        'elevation_smoothing_window' => 5,
        // Simplification Douglas-Peucker de la trace stockée.
        'simplify_tolerance_m' => 5.0,
        'polyline_precision' => 5,
        // Taille des lots envoyés par le mobile.
        'sync_batch_size' => 100,
        // Grille de clustering pour le reverse-geocoding (≈ 2,2 km).
        'zone_grid_degrees' => 0.02,
    ],

    /*
    | Règles financières. Voir docs/finance.md — ces valeurs sont des
    | garde-fous, pas des suggestions.
    */
    'finance' => [
        // Au-delà : approbation explicite obligatoire par un TREASURER/ADMIN.
        'expense_approval_threshold' => (int) env('EXPENSE_APPROVAL_THRESHOLD', 25000),
        // Le solde de la caisse est-il visible de tous les membres ?
        'public_balance' => filter_var(env('FINANCE_PUBLIC_BALANCE', false), FILTER_VALIDATE_BOOL),
        // Un approbateur ne peut pas approuver sa propre dépense.
        'self_approval_allowed' => false,
        // Un paiement ne peut jamais dépasser le reste dû.
        'allow_overpayment' => false,
        'payment_methods' => [
            'CASH' => 'Espèces',
            'WAVE' => 'Wave',
            'ORANGE_MONEY' => 'Orange Money',
            'FREE_MONEY' => 'Free Money',
            'TRANSFER' => 'Virement',
            'OTHER' => 'Autre',
        ],
    ],

    /*
    | Cartographie — fournisseur interchangeable (ADR-004).
    */
    'map' => [
        'provider' => env('MAP_PROVIDER', 'osm'),
        'mapbox_token' => env('MAPBOX_TOKEN'),
        'default_center' => ['lat' => 14.6928, 'lng' => -17.4467], // Dakar
        'default_zoom' => 12,
        'nominatim' => [
            'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
            'user_agent' => env('NOMINATIM_USER_AGENT', 'CycloDakar/1.0'),
            // Politique d'usage Nominatim : 1 requête par seconde maximum.
            'min_interval_ms' => 1100,
        ],
    ],

    /*
    | Service Node.js (rendu vidéo, WebSocket). Les échanges Laravel <-> Node
    | sont signés en HMAC-SHA256 avec ce secret : Node n'écrit jamais en base,
    | il rappelle Laravel.
    */
    'node_service' => [
        'url' => rtrim((string) env('NODE_SERVICE_URL', 'http://localhost:4000'), '/'),
        'secret' => env('NODE_SERVICE_SECRET'),
        'timeout_s' => 10,
    ],

    'video' => [
        'formats' => ['16:9', '9:16', '1:1'],
        'durations_s' => [15, 30, 60],
        'default_format' => '9:16',
        'default_duration_s' => 30,
        'themes' => ['classic', 'night', 'sunset'],
    ],

    /*
    | Uploads : la validation MIME est faite côté serveur sur le contenu
    | réel du fichier, jamais sur l'extension ni sur l'en-tête client.
    */
    'uploads' => [
        'max_size_kb' => (int) env('UPLOAD_MAX_SIZE_KB', 10240),
        'image_mimes' => explode(',', (string) env('UPLOAD_ALLOWED_IMAGE_MIMES', 'image/jpeg,image/png,image/webp')),
        'document_mimes' => explode(',', (string) env('UPLOAD_ALLOWED_DOC_MIMES', 'application/pdf')),
        // Les justificatifs financiers ne sont JAMAIS servis depuis public/.
        'private_disk' => 'local',
        'public_disk' => 'public',
    ],

    'roles' => [
        'MEMBER' => 'Membre',
        'COLLECTOR' => 'Collecteur',
        'TREASURER' => 'Trésorier',
        'ADMIN' => 'Administrateur',
        'SUPER_ADMIN' => 'Super administrateur',
    ],

];
