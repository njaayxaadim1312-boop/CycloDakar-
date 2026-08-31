<?php

declare(strict_types=1);

/*
| Messages du gestionnaire de mots de passe de Laravel.
|
| Le contrôleur renvoie ses propres messages pour ne rien divulguer sur
| l'existence d'un compte (voir PasswordResetController) ; ceux-ci servent de
| repli et pour l'interface web classique.
*/

return [
    'reset' => 'Votre mot de passe a été réinitialisé.',
    'sent' => 'Un lien de réinitialisation vous a été envoyé par courriel.',
    'throttled' => 'Veuillez patienter avant de réessayer.',
    'token' => 'Ce lien de réinitialisation est invalide ou a expiré.',
    'user' => "Aucun compte ne correspond à cet identifiant.",
];
