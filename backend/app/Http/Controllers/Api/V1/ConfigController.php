<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Configuration publique de l'application.
 *
 * Le mobile et le web récupèrent ici les paramètres métier plutôt que de les
 * dupliquer dans leur propre code. C'est notamment ce qui garantit que le
 * filtrage GPS du téléphone utilise exactement les mêmes seuils que le
 * recalcul serveur (voir docs/gps.md) : une seule source, pas deux.
 *
 * Aucune donnée sensible ici — ni secret, ni clé privée, ni solde.
 */
final class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::ok([
            'club' => config('cyclo.club'),

            'currency' => config('cyclo.currency'),

            // Liste des sports avec leurs paramètres de capture GPS.
            'sports' => collect(config('cyclo.sports'))
                ->map(fn (array $sport, string $code) => [
                    'code' => $code,
                    'label' => $sport['label'],
                    'icon' => $sport['icon'],
                    'uses_pace' => $sport['uses_pace'],
                    'sample_interval_s' => $sport['sample_interval_s'],
                    'min_distance_m' => $sport['min_distance_m'],
                    'max_accuracy_m' => $sport['max_accuracy_m'],
                    'max_speed_mps' => $sport['max_speed_mps'],
                ])
                ->values()
                ->all(),

            // Seuils de l'algorithme de filtrage, partagés client/serveur.
            'gps' => config('cyclo.gps'),

            'map' => [
                'provider' => config('cyclo.map.provider'),
                'default_center' => config('cyclo.map.default_center'),
                'default_zoom' => config('cyclo.map.default_zoom'),
                // Le token Mapbox n'est exposé que s'il est effectivement le
                // fournisseur actif (c'est un token public, restreint par
                // domaine côté Mapbox).
                'mapbox_token' => config('cyclo.map.provider') === 'mapbox'
                    ? config('cyclo.map.mapbox_token')
                    : null,
            ],

            'payment_methods' => collect(config('cyclo.finance.payment_methods'))
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values()
                ->all(),

            'roles' => collect(config('cyclo.roles'))
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values()
                ->all(),

            'video' => config('cyclo.video'),

            'uploads' => [
                'max_size_kb' => config('cyclo.uploads.max_size_kb'),
                'image_mimes' => config('cyclo.uploads.image_mimes'),
                'document_mimes' => config('cyclo.uploads.document_mimes'),
            ],
        ]);
    }
}
