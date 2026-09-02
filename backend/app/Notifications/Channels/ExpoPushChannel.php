<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\PushToken;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi des notifications push par le service Expo.
 *
 * UNE NOTIFICATION QUI ÉCHOUE NE DOIT JAMAIS CASSER L'ACTE QUI L'A DÉCLENCHÉE.
 *
 * C'est la règle de ce fichier, et elle explique tous les `try` qu'on y trouve.
 * Un encaissement enregistré, une dépense approuvée, un badge gagné : ces
 * actes-là sont accomplis. Si le service Expo est en panne, si le téléphone a
 * désinstallé l'application, si le réseau du serveur tombe — le membre ne
 * recevra pas son push, et c'est un ennui mineur. Faire remonter l'erreur
 * annulerait la transaction : on perdrait de l'argent pour un ping raté.
 *
 * La notification reste EN BASE quoi qu'il arrive : le membre la verra en
 * ouvrant l'application. Le push n'est qu'un rappel.
 *
 * LES JETONS MORTS SE NETTOIENT TOUT SEULS.
 *
 * Expo répond `DeviceNotRegistered` quand l'application a été désinstallée ou
 * que le jeton a été révoqué par le système. On supprime alors le jeton :
 * sans cela, la table grossirait indéfiniment d'appareils qui n'existent plus,
 * et chaque envoi collectif traînerait ces adresses mortes.
 *
 * POURQUOI PAS DE FILE D'ATTENTE ICI
 *
 * Les notifications sont mises en file (`ShouldQueue`) au niveau de la classe
 * de notification, pas du canal. Le canal, lui, tourne déjà dans le worker :
 * y remettre une file ferait une file dans une file.
 */
final class ExpoPushChannel
{
    /** Le point d'entrée public d'Expo. Aucun jeton d'API n'est requis. */
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** Expo accepte cent messages par requête. Au-delà, il refuse le lot. */
    private const BATCH = 100;

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpo')) {
            return;
        }

        $jetons = PushToken::query()
            ->where('user_id', $notifiable->getKey())
            ->pluck('token')
            ->all();

        if ($jetons === []) {
            // Pas de jeton, pas de push — et c'est aussi le réglage : se
            // désabonner revient à supprimer son jeton.
            return;
        }

        /** @var array<string, mixed> $contenu */
        $contenu = $notification->toExpo($notifiable);

        foreach (array_chunk($jetons, self::BATCH) as $lot) {
            $this->push($lot, $contenu);
        }
    }

    /**
     * @param  list<string>  $jetons
     * @param  array<string, mixed>  $contenu
     */
    private function push(array $jetons, array $contenu): void
    {
        $messages = array_map(fn (string $jeton) => array_merge([
            'to' => $jeton,
            // `default` sur Android : sans canal déclaré, la notification
            // arrive muette et sans vibration sur les versions récentes.
            'channelId' => 'default',
            'sound' => 'default',
            'priority' => 'normal',
        ], $contenu), $jetons);

        try {
            $reponse = Http::timeout((int) config('cyclo.push.timeout_s', 10))
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT, $messages);

            if ($reponse->failed()) {
                // On journalise, on ne relance pas : l'acte d'origine est
                // accompli, et le membre verra sa notification en base.
                Log::warning('Push Expo refusé.', [
                    'status' => $reponse->status(),
                    'body' => mb_substr($reponse->body(), 0, 500),
                ]);

                return;
            }

            $this->handleReceipts($jetons, (array) $reponse->json('data', []));
        } catch (Throwable $e) {
            Log::warning('Push Expo injoignable.', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Traite les accusés d'Expo, un par message envoyé.
     *
     * Ils reviennent DANS L'ORDRE des messages : c'est ce qui permet de
     * rattacher une erreur à son jeton. Expo le garantit, et rien d'autre ne
     * le permettrait — les accusés ne portent pas le jeton.
     *
     * @param  list<string>  $jetons
     * @param  array<int, mixed>  $accuses
     */
    private function handleReceipts(array $jetons, array $accuses): void
    {
        $morts = [];

        foreach ($accuses as $index => $accuse) {
            if (! is_array($accuse) || ($accuse['status'] ?? 'ok') === 'ok') {
                continue;
            }

            $cause = $accuse['details']['error'] ?? null;

            if ($cause === 'DeviceNotRegistered' && isset($jetons[$index])) {
                $morts[] = $jetons[$index];

                continue;
            }

            Log::warning('Push Expo en erreur.', [
                'error' => $cause,
                'message' => $accuse['message'] ?? null,
            ]);
        }

        if ($morts !== []) {
            // L'application a été désinstallée ou le jeton révoqué : garder
            // ces adresses ferait traîner chaque envoi collectif sur des
            // appareils qui n'existent plus.
            PushToken::query()->whereIn('token', $morts)->delete();

            Log::info('Jetons push périmés supprimés.', ['count' => count($morts)]);
        }
    }
}
