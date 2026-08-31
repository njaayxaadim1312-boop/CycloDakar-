<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Courriel de réinitialisation de mot de passe.
 *
 * Le lien pointe vers l'APPLICATION WEB (`FRONTEND_URL`) et non vers une route
 * Laravel : l'API ne rend aucune page, c'est le front qui affiche le formulaire
 * puis appelle `POST /api/v1/auth/reset-password`.
 */
final class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) Config::get('app.frontend_url'), '/');

        // L'identifiant est repris dans l'URL : le formulaire du front doit
        // renvoyer exactement le même à l'API, sinon le jeton ne correspond à
        // rien (le jeton est lié au couple identifiant + token).
        $login = $notifiable->email ?? $notifiable->phone;

        $url = sprintf(
            '%s/reset-password?token=%s&login=%s',
            $frontend,
            $this->token,
            urlencode((string) $login),
        );

        $minutes = (int) Config::get('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Cyclo Dakar — Réinitialisation de votre mot de passe')
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Vous avez demandé à réinitialiser le mot de passe de votre compte Cyclo Dakar.')
            ->action('Choisir un nouveau mot de passe', $url)
            ->line("Ce lien expire dans {$minutes} minutes.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.")
            ->salutation('L\'équipe Cyclo Dakar');
    }
}
