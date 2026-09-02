<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Le journal d'audit, enfin consultable.
 *
 * IL EXISTE DEPUIS LA PHASE 3 ET N'A JAMAIS PU ÊTRE LU.
 *
 * Chaque attribution de rôle, chaque encaissement, chaque annulation y écrit
 * une ligne depuis des mois. Mais il fallait ouvrir la base pour les voir — ce
 * qu'un trésorier ne fera jamais, et ce qu'un administrateur de club n'a
 * souvent pas les moyens de faire.
 *
 * Un journal qu'on ne peut pas lire ne protège personne. Il donne le sentiment
 * d'être protégé, ce qui est pire : on renonce à d'autres contrôles en
 * croyant celui-là actif.
 *
 * RÉSERVÉ À L'ADMINISTRATION, ET SEULEMENT À ELLE.
 *
 * Le journal dit qui a fait quoi, avec les valeurs avant et après. C'est
 * l'outil du contrôle — et, entre de mauvaises mains, la carte complète de qui
 * manie l'argent du club et quand. Le trésorier lui-même n'y a pas accès : il
 * est la personne que ce journal surveille.
 *
 * EN LECTURE SEULE. Il n'existe aucune route pour écrire, modifier ou effacer
 * une ligne d'audit, et il ne doit jamais en exister : un journal qu'on peut
 * retoucher ne prouve rien.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        // Administration seulement. Le tableau des droits de `finance.md` le
        // dit déjà : voir les journaux d'audit est la seule ligne où le
        // trésorier a un ❌.
        if (! $request->user()->role->isAdmin()) {
            return ApiResponse::error(
                message: "Le journal d'audit est réservé à l'administration du club.",
                status: 403,
                code: 'FORBIDDEN',
            );
        }

        $filtres = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'entity_type' => ['nullable', 'string', 'max:60'],
            'user' => ['nullable', 'uuid', 'exists:users,uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            // Le plus récent d'abord : on consulte un journal pour comprendre
            // ce qui vient de se passer, pas pour lire l'histoire du club.
            ->orderByDesc('audit_logs.id');

        if (isset($filtres['action'])) {
            $query->where('audit_logs.action', 'like', $filtres['action'].'%');
        }

        if (isset($filtres['entity_type'])) {
            $query->where('audit_logs.entity_type', $filtres['entity_type']);
        }

        if (isset($filtres['user'])) {
            $query->where('users.uuid', $filtres['user']);
        }

        if (isset($filtres['from'])) {
            $query->whereDate('audit_logs.created_at', '>=', $filtres['from']);
        }

        if (isset($filtres['to'])) {
            $query->whereDate('audit_logs.created_at', '<=', $filtres['to']);
        }

        $lignes = $query->paginate(
            $filtres['per_page'] ?? self::PER_PAGE,
            [
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.old_values',
                'audit_logs.new_values',
                'audit_logs.reason',
                'audit_logs.ip_address',
                'audit_logs.created_at',
                'users.uuid as user_uuid',
                'users.name as user_name',
            ],
        );

        return ApiResponse::ok(
            collect($lignes->items())->map(fn ($l) => [
                'id' => (int) $l->id,
                'action' => $l->action,
                'entity' => [
                    'type' => $l->entity_type,
                    'id' => (int) $l->entity_id,
                ],
                // Décodées ici : le client n'a pas à savoir qu'elles sont
                // stockées en JSON, et il ne doit surtout pas les ré-analyser
                // lui-même — une chaîne mal échappée deviendrait un trou.
                'old_values' => $l->old_values === null
                    ? null
                    : json_decode((string) $l->old_values, true),
                'new_values' => $l->new_values === null
                    ? null
                    : json_decode((string) $l->new_values, true),
                'reason' => $l->reason,
                // L'adresse IP est montrée : c'est ce qui distingue « le
                // trésorier a annulé ce paiement » de « quelqu'un utilisant
                // son compte l'a annulé ».
                'ip_address' => $l->ip_address,
                'author' => $l->user_uuid === null
                    ? null
                    : ['uuid' => $l->user_uuid, 'name' => $l->user_name],
                'created_at' => $l->created_at,
            ])->all(),
            meta: [
                'current_page' => $lignes->currentPage(),
                'last_page' => $lignes->lastPage(),
                'total' => $lignes->total(),
                'has_more' => $lignes->hasMorePages(),
            ],
        );
    }

    /**
     * Les actions réellement présentes, pour alimenter le filtre.
     *
     * Lues en base plutôt qu'écrites en dur : une liste figée oublierait les
     * actions ajoutées par les phases suivantes, et le filtre deviendrait
     * silencieusement incomplet.
     */
    public function actions(Request $request): JsonResponse
    {
        if (! $request->user()->role->isAdmin()) {
            return ApiResponse::error(
                message: "Le journal d'audit est réservé à l'administration du club.",
                status: 403,
                code: 'FORBIDDEN',
            );
        }

        $actions = DB::table('audit_logs')
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->orderBy('action')
            ->get()
            ->map(fn ($l) => ['action' => $l->action, 'count' => (int) $l->total])
            ->all();

        return ApiResponse::ok($actions);
    }
}
