<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Le socle de toutes les notifications du club.
 *
 * TROIS DÉCISIONS PORTÉES ICI, UNE FOIS POUR TOUTES.
 *
 * **Elles passent par la file d'attente.** Une notification envoyée en ligne
 * ferait attendre le collecteur pendant que le serveur parle à Expo, au bord
 * d'une route, sur un réseau lent. L'encaissement doit répondre tout de suite ;
 * le push peut arriver deux secondes plus tard.
 *
 * Et elles ne partent QU'APRÈS la validation de la transaction SQL — réglage
 * `after_commit` de la file, dans `config/queue.php`. Sans lui, un encaissement
 * annulé en fin de transaction enverrait quand même « paiement enregistré », et
 * le membre croirait avoir payé. Le worker pourrait même traiter le message
 * avant que la transaction soit écrite, et ne trouverait alors aucun paiement à
 * décrire.
 *
 * **La base d'abord, le push ensuite.** L'écriture en base est le canal qui ne
 * peut pas échouer : c'est elle qui garantit qu'un membre retrouvera
 * l'information en ouvrant l'application, même si son téléphone était éteint,
 * même si Expo était en panne. Le push n'est qu'un rappel.
 *
 * **Le format est le même partout.** Un code stable, un titre, un corps, une
 * destination. C'est ce qui permet au client d'afficher une notification qu'il
 * ne connaît pas encore — une version d'application plus ancienne que le
 * serveur doit continuer de fonctionner, pas afficher une case vide.
 */
abstract class ClubNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Les canaux.
     *
     * `database` toujours ; `expo` seulement si l'utilisateur a un appareil
     * enregistré — le canal s'en assure lui-même, et il vaut mieux qu'il le
     * fasse à un seul endroit.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'expo'];
    }

    /**
     * Le code stable de ce type de notification : `payment.received`.
     *
     * Stable veut dire : on ne le renomme pas. Le client s'en sert pour choisir
     * une icône et une destination, et un renommage casserait les
     * notifications déjà en base — celles qu'un membre n'a pas encore lues.
     */
    abstract public function code(): string;

    abstract public function title(object $notifiable): string;

    abstract public function body(object $notifiable): string;

    /** Où mène cette notification quand on la touche. */
    abstract public function url(object $notifiable): string;

    /** Le nom d'icône, dans le vocabulaire des clients. */
    public function icon(): string
    {
        return 'bell';
    }

    /**
     * Ce que le client reçoit, en base comme en push.
     *
     * @return array<string, mixed>
     */
    public function payload(object $notifiable): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    final public function toDatabase(object $notifiable): array
    {
        return [
            'code' => $this->code(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url($notifiable),
            'icon' => $this->icon(),
        ] + $this->payload($notifiable);
    }

    /**
     * Le message push.
     *
     * `data` porte l'URL : c'est ce qui permet à l'application d'ouvrir le bon
     * écran quand on touche la notification, plutôt que de retomber sur
     * l'accueil et de laisser l'utilisateur chercher ce dont on vient de lui
     * parler.
     *
     * @return array<string, mixed>
     */
    final public function toExpo(object $notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'data' => [
                'code' => $this->code(),
                'url' => $this->url($notifiable),
            ] + $this->payload($notifiable),
        ];
    }
}
