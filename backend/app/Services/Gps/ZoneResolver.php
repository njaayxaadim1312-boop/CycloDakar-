<?php

declare(strict_types=1);

namespace App\Services\Gps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zones traversées par une sortie : « Dakar · Ouakam · Ngor · Almadies ».
 *
 * Le problème à résoudre est le coût. Nominatim limite à **une requête par
 * seconde**, et une sortie de 46 km compte 10 000 points : les résoudre un par
 * un prendrait trois heures et constituerait un abus manifeste du service.
 *
 * La solution tient en trois étapes :
 *
 *  1. **Projection sur une grille** de 0,02° (~2,2 km). Tous les points d'une
 *     même cellule partagent la même réponse — un cycliste ne change pas de
 *     quartier tous les six mètres.
 *  2. **Déduplication** : une sortie traverse typiquement 3 à 15 cellules.
 *  3. **Cache définitif en base**. Dakar est un territoire fini : après
 *     quelques semaines, le cache couvre les parcours habituels du club et
 *     plus aucun appel externe n'est nécessaire.
 *
 * Une cellule sans libellé PARCE QUE LE SERVICE A RÉPONDU ainsi (pleine mer,
 * zone non cartographiée) est tout de même enregistrée : sans cela on
 * réinterrogerait Nominatim à chaque sortie qui la traverse. En revanche, une
 * cellule non résolue parce que le service était injoignable n'est jamais
 * gravée — voir `labelFor()`.
 *
 * Voir docs/gps.md §11.
 */
final class ZoneResolver
{
    /**
     * Résout les zones d'une trace, dans l'ordre de passage.
     *
     * @param  list<GpsPoint>  $points
     * @return list<string>
     */
    public function resolve(array $points): array
    {
        if ($points === []) {
            return [];
        }

        $cells = $this->distinctCells($points);
        $labels = [];

        foreach ($cells as $cell) {
            $label = $this->labelFor($cell['key'], $cell['lat'], $cell['lng']);

            // Déduplication des LIBELLÉS : deux cellules voisines tombent
            // souvent dans le même quartier, et « Ouakam · Ouakam » n'apporte
            // rien.
            if ($label !== null && ! in_array($label, $labels, strict: true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Cellules distinctes traversées, dans l'ordre de passage.
     *
     * @param  list<GpsPoint>  $points
     * @return list<array{key: string, lat: float, lng: float}>
     */
    public function distinctCells(array $points): array
    {
        $size = (float) config('cyclo.gps.zone_grid_degrees', 0.02);

        $cells = [];
        $seen = [];

        foreach ($points as $point) {
            // On arrondit au centre de la cellule : c'est cette coordonnée
            // qu'on enverra au géocodeur, et elle doit être stable pour que le
            // cache fonctionne.
            $lat = floor($point->lat / $size) * $size + $size / 2;
            $lng = floor($point->lng / $size) * $size + $size / 2;

            $key = $this->cellKey($lat, $lng);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $cells[] = ['key' => $key, 'lat' => $lat, 'lng' => $lng];
        }

        return $cells;
    }

    public function cellKey(float $lat, float $lng): string
    {
        return sprintf('%.4f,%.4f', $lat, $lng);
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Libellé d'une cellule : cache d'abord, service externe en dernier
     * recours.
     */
    private function labelFor(string $key, float $lat, float $lng): ?string
    {
        /*
         * On ne relit que les cellules RÉSOLUES.
         *
         * Distinction essentielle, et apprise à la dure : une cellule sans
         * libellé parce que le service a répondu « cette zone n'a pas de nom »
         * (pleine mer) doit être mise en cache pour toujours. Une cellule sans
         * libellé parce que le service était INJOIGNABLE ne doit surtout pas
         * l'être — sinon une panne réseau de dix minutes empoisonne
         * définitivement tout le territoire traversé ce jour-là, et plus aucune
         * sortie ne portera de nom de quartier.
         */
        $cached = DB::table('geo_zones_cache')
            ->where('cell_key', $key)
            ->where('resolved', true)
            ->first();

        if ($cached !== null) {
            return $cached->label;
        }

        $resolved = $this->askNominatim($lat, $lng);

        if (! $resolved['ok']) {
            // Service indisponible : on ne grave rien, la cellule sera
            // retentée à la prochaine sortie qui la traverse.
            return null;
        }

        // Le service a répondu : on grave, même sans libellé.
        DB::table('geo_zones_cache')->updateOrInsert(
            ['cell_key' => $key],
            [
                'center_lat' => $lat,
                'center_lng' => $lng,
                'label' => $resolved['label'],
                'city' => $resolved['city'],
                'country_code' => $resolved['country_code'],
                'raw' => $resolved['raw'] === null ? null : json_encode($resolved['raw']),
                'resolved' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $resolved['label'];
    }

    /**
     * Interroge Nominatim.
     *
     * @return array{ok: bool, label: string|null, city: string|null,
     *               country_code: string|null, raw: array<string, mixed>|null}
     */
    private function askNominatim(float $lat, float $lng): array
    {
        $empty = ['ok' => false, 'label' => null, 'city' => null, 'country_code' => null, 'raw' => null];

        // La politique d'usage de Nominatim impose un intervalle d'au moins
        // une seconde entre deux requêtes, et un agent utilisateur
        // identifiable. Ne pas la respecter fait bannir l'adresse IP du club.
        usleep((int) config('cyclo.map.nominatim.min_interval_ms', 1100) * 1000);

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('cyclo.map.nominatim.user_agent'),
                'Accept-Language' => 'fr',
            ])
                ->timeout(8)
                ->get(rtrim((string) config('cyclo.map.nominatim.url'), '/').'/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    // 14 = quartier / village. Plus fin donnerait des noms de
                    // rue, ce qui n'a aucun intérêt pour résumer une sortie.
                    'zoom' => 14,
                    'addressdetails' => 1,
                ]);
        } catch (ConnectionException) {
            // Le géocodage est un enrichissement, pas une donnée vitale :
            // son indisponibilité ne doit jamais faire échouer une sortie.
            return $empty;
        }

        if (! $response->successful()) {
            Log::warning('Nominatim a répondu '.$response->status(), compact('lat', 'lng'));

            return $empty;
        }

        $body = $response->json();
        $address = $body['address'] ?? [];

        return [
            'ok' => true,
            'label' => $this->extractLabel($address),
            'city' => $address['city'] ?? $address['town'] ?? $address['county'] ?? null,
            'country_code' => isset($address['country_code'])
                ? strtoupper((string) $address['country_code'])
                : null,
            'raw' => is_array($body) ? $body : null,
        ];
    }

    /**
     * Extrait le libellé le plus parlant d'une adresse.
     *
     * L'ordre compte : à Dakar, `suburb` donne « Ouakam » ou « Ngor », ce que
     * le club reconnaît immédiatement, là où `city` donnerait « Dakar » pour
     * toute la sortie.
     *
     * @param  array<string, mixed>  $address
     */
    private function extractLabel(array $address): ?string
    {
        foreach (['suburb', 'quarter', 'neighbourhood', 'village', 'town', 'city_district', 'city', 'county'] as $key) {
            if (isset($address[$key]) && is_string($address[$key]) && $address[$key] !== '') {
                return $this->tidy($address[$key]);
            }
        }

        return null;
    }

    /**
     * Allège le libellé.
     *
     * Les données OpenStreetMap sénégalaises nomment les quartiers de Dakar
     * « Commune de Médina », « Commune de Grand Yoff ». Le préfixe est exact
     * mais encombrant : répété quatre fois sur une ligne de liste, il masque
     * la seule partie qui distingue les zones. Le club dit « Médina ».
     */
    private function tidy(string $label): string
    {
        return preg_replace('/^(Commune|Arrondissement|Département)\s+d[eu]\s+/iu', '', $label) ?: $label;
    }
}
