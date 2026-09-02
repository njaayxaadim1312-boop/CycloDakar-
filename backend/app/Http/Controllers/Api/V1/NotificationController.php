<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Les notifications d'un membre, et ses appareils.
 *
 * TOUT EST STRICTEMENT PERSONNEL ICI.
 *
 * Aucune route ne prend d'identifiant d'utilisateur : on ne lit et on ne
 * marque que ses propres notifications, celles de la session. Un paramètre
 * `user` ouvrirait la porte à la lecture des notifications d'autrui — et
 * celles-ci contiennent des montants, des dettes, des décisions financières.
 *
 * Pas de Policy, donc, parce qu'il n'y a rien à autoriser : la requête ne peut
 * désigner que soi.
 */
final class NotificationController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * Les notifications du membre connecté.
     *
     * Les non-lues sont comptées à part et renvoyées dans `meta` : c'est le
     * seul chiffre dont l'interface a besoin en permanence — la pastille du
     * menu — et le demander séparément ferait un appel de plus à chaque
     * ouverture d'écran.
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $query = $user->notifications()->getQuery();

        if (($filtres['unread'] ?? false) === true) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate($filtres['per_page'] ?? self::PER_PAGE);

        return ApiResponse::ok(
            collect($notifications->items())
                ->map(fn (DatabaseNotification $n) => $this->present($n))
                ->all(),
            meta: [
                'unread' => $user->unreadNotifications()->count(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'has_more' => $notifications->hasMorePages(),
            ],
        );
    }

    /** Le seul compteur dont l'interface a besoin en continu. */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::ok(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if ($notification === null) {
            // 404 et non 403 : une notification qui n'est pas la sienne ne
            // doit pas être distinguable d'une notification inexistante,
            // sinon on pourrait éprouver l'existence d'un identifiant.
            return ApiResponse::error(
                message: 'Cette notification est introuvable.',
                status: 404,
                code: 'NOTIFICATION_NOT_FOUND',
            );
        }

        $notification->markAsRead();

        return ApiResponse::ok($this->present($notification->fresh()));
    }

    /** Tout marquer comme lu — le geste qu'on fait après une absence. */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $lues = $request->user()->unreadNotifications()->count();

        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::ok(['marked' => $lues, 'unread' => 0]);
    }

    /* ---------------------------------------------------------------------- */
    /* Appareils                                                              */
    /* ---------------------------------------------------------------------- */

    /**
     * Enregistre le jeton push d'un appareil.
     *
     * Appelé à chaque démarrage de l'application mobile : Expo peut changer le
     * jeton après une mise à jour du système, et un jeton périmé ne prévient
     * pas — il cesse simplement de recevoir.
     *
     * Le jeton est unique en base : s'il appartenait à quelqu'un d'autre — un
     * téléphone prêté, revendu — il CHANGE DE PROPRIÉTAIRE plutôt que d'être
     * dupliqué. Sans cela, l'ancien utilisateur continuerait de recevoir sur
     * un appareil qui n'est plus le sien.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            // `ExponentPushToken[...]` — on ne vérifie pas la forme exacte :
            // Expo l'a déjà changée par le passé, et refuser un jeton valide
            // parce qu'on connaît mal son format couperait les notifications
            // sans que personne comprenne pourquoi.
            'token' => ['required', 'string', 'min:10', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        PushToken::register(
            user: $request->user(),
            token: $donnees['token'],
            deviceName: $donnees['device_name'] ?? null,
            platform: $donnees['platform'] ?? null,
        );

        return ApiResponse::ok(['registered' => true]);
    }

    /**
     * Retire un appareil.
     *
     * C'est aussi le réglage « ne plus me notifier » : pas de jeton, pas de
     * push. Les notifications en base, elles, continuent d'arriver — elles ne
     * réveillent personne, et un membre doit pouvoir retrouver ce qu'on lui a
     * dit même s'il a coupé les alertes.
     */
    public function forgetDevice(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $supprimes = PushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $donnees['token'])
            ->delete();

        return ApiResponse::ok(['forgotten' => $supprimes > 0]);
    }

    /* ---------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            // Le code stable, pas la classe PHP : le client choisit son icône
            // et sa destination dessus, et exposer le nom de classe ferait
            // fuiter l'arborescence du code dans l'API.
            'code' => $data['code'] ?? 'unknown',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'url' => $data['url'] ?? null,
            'icon' => $data['icon'] ?? 'bell',
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            // Le reste du contenu, propre à chaque type — un numéro de reçu,
            // un montant. Le client l'ignore s'il ne le connaît pas.
            'payload' => collect($data)
                ->except(['code', 'title', 'body', 'url', 'icon'])
                ->all(),
        ];
    }
}
