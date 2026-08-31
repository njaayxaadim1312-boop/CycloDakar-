<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Enveloppe unique de toutes les réponses de l'API.
 *
 * Le web et le mobile consomment strictement le même contrat : une seule forme
 * de réponse évite d'écrire deux fois la gestion d'erreurs côté client.
 *
 *   Succès  : { "data": ..., "meta": {...}? }
 *   Erreur  : { "message": "...", "errors": {...}?, "code": "..." }
 *
 * Le code HTTP porte le sens ; le corps porte le détail.
 */
final class ApiResponse
{
    /**
     * Réponse de succès contenant une ressource ou un tableau.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Ressource créée.
     */
    public static function created(mixed $data = null): JsonResponse
    {
        return self::ok($data, status: 201);
    }

    /**
     * Traitement accepté mais pas encore terminé (génération vidéo, export).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function accepted(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 202);
    }

    /**
     * Succès sans contenu à renvoyer.
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Collection paginée : la pagination est toujours exposée de la même façon,
     * ce qui permet au client d'avoir un seul composant de liste infinie.
     */
    public static function paginated(LengthAwarePaginator $paginator, ?string $resource = null): JsonResponse
    {
        $items = $paginator->getCollection();

        $data = $resource !== null
            ? $resource::collection($items)
            : $items;

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Erreur métier.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
        ?string $code = null,
    ): JsonResponse {
        $payload = ['message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    /**
     * Convertit une ressource ou une collection en réponse normalisée.
     */
    public static function resource(JsonResource|ResourceCollection $resource, int $status = 200): JsonResponse
    {
        return $resource->response()->setStatusCode($status);
    }
}
