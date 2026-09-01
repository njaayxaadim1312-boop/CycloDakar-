<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use SimpleSoftwareIO\QrCode\Generator;

/**
 * QR Code personnel d'un membre.
 *
 * **Le QR ne contient AUCUNE donnée personnelle.** Ni nom, ni téléphone, ni
 * matricule : seulement un jeton opaque de 43 caractères, tiré au hasard. Un
 * QR photographié dans la rue, ou retrouvé sur une carte perdue, ne dit donc
 * rien de son porteur à qui n'a pas accès à l'API du club.
 *
 * C'est aussi pourquoi le jeton est **révocable** : le compromettre ne coûte
 * qu'une rotation (`Member::rotateQrToken()`), là où un QR contenant un
 * numéro de téléphone aurait exposé ce numéro pour toujours.
 *
 * Le SVG est produit **côté serveur** et non dans le navigateur : c'est la même
 * image sur le web, sur le mobile et à l'impression, et le club peut la coller
 * dans un courriel ou un document sans dépendre d'une bibliothèque cliente.
 */
final class QrCodeGenerator
{
    /**
     * Préfixe du contenu encodé.
     *
     * Il permet à un scanner de reconnaître un QR du club **avant** d'appeler
     * l'API : inutile d'interroger le serveur parce qu'un membre a scanné un
     * paquet de biscuits. Il ne protège rien — c'est un aiguillage, pas un
     * secret.
     */
    private const PREFIX = 'CD:';

    /** Contenu réellement encodé dans l'image. */
    public function payload(Member $member): string
    {
        return self::PREFIX.$member->qr_token;
    }

    /**
     * Extrait le jeton d'un contenu scanné.
     *
     * Accepte le contenu avec ou sans préfixe : un membre peut avoir un vieux
     * QR imprimé, et refuser de le lire pour une question de forme serait
     * absurde. Renvoie `null` si le contenu ne ressemble pas à un jeton du
     * club, ce qui évite d'aller interroger l'API pour rien.
     */
    public function extractToken(string $scanned): ?string
    {
        $token = str_starts_with($scanned, self::PREFIX)
            ? substr($scanned, strlen(self::PREFIX))
            : $scanned;

        $token = trim($token);

        // 43 caractères base64url : la forme exacte de `generateQrToken()`.
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 ? $token : null;
    }

    /**
     * SVG du QR Code, prêt à afficher ou à imprimer.
     *
     * Correction d'erreur en niveau **Q** (25 %) et non L : ces QR seront
     * imprimés sur des cartes qui passeront des mois dans un portefeuille,
     * puis scannés au bord d'une route poussiéreuse. Une image qui reste
     * lisible malgré une tache vaut les quelques modules supplémentaires.
     */
    public function svg(Member $member, int $size = 320): string
    {
        return (string) (new Generator)
            ->format('svg')
            ->size($size)
            // Une marge est OBLIGATOIRE : sans zone silencieuse autour, la
            // plupart des lecteurs ne trouvent pas le motif de repérage.
            ->margin(1)
            ->errorCorrection('Q')
            ->generate($this->payload($member));
    }

    /**
     * SVG en `data:` URI, pour l'insérer directement dans une page.
     *
     * Évite une requête réseau supplémentaire par membre sur un écran qui en
     * affiche plusieurs — la fiche d'impression du club, par exemple.
     */
    public function dataUri(Member $member, int $size = 320): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($member, $size));
    }
}
