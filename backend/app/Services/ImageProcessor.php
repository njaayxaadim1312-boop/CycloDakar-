<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Nettoie et redimensionne une image avant de la stocker.
 *
 * DEUX RAISONS, ET LA PREMIÈRE EST UNE QUESTION DE VIE PRIVÉE.
 *
 * **Une photo prise au téléphone porte les coordonnées GPS du lieu de la
 * prise de vue.** Dans les métadonnées EXIF, invisibles à l'œil, mais lisibles
 * par n'importe quel outil en deux secondes. Un membre qui envoie sa photo de
 * profil prise chez lui publierait donc son adresse — sur un disque public, à
 * une URL devinable.
 *
 * C'est exactement ce que `docs/risques.md` interdit pour les traces GPS. Il
 * n'y a aucune raison qu'une photo échappe à la règle : elle révèle la même
 * chose, en une seule position au lieu d'une trace.
 *
 * L'EXIF porte aussi le modèle du téléphone, son numéro de série sur certains
 * appareils, la date exacte, parfois le nom du propriétaire. Rien de tout cela
 * n'a de raison d'être publié par un club de vélo.
 *
 * **La seconde raison est le coût.** Un téléphone récent produit des images de
 * 4 000 × 3 000. Servir huit mégaoctets pour un avatar de 72 pixels, sur des
 * forfaits mobiles sénégalais, est un gaspillage que le club paie deux fois :
 * en stockage et en données de ses membres.
 *
 * LE PIÈGE : L'ORIENTATION.
 *
 * Un téléphone n'écrit pas l'image tournée ; il l'écrit telle que le capteur
 * l'a vue, et note dans l'EXIF « tourne-la de 90° avant d'afficher ». Effacer
 * l'EXIF sans appliquer cette rotation d'abord fait sortir toutes les photos
 * verticales couchées sur le côté.
 *
 * On applique donc l'orientation, PUIS on ré-encode — ce qui efface tout le
 * reste par construction : GD ne recopie aucune métadonnée.
 */
final class ImageProcessor
{
    /** Une photo de profil s'affiche au plus en 200 px. 1024 laisse du confort. */
    public const AVATAR = 1024;

    /** Un fond d'écran couvre un écran large. Au-delà, on paie sans rien gagner. */
    public const COVER = 1920;

    /**
     * Nettoie une image et renvoie son contenu binaire, prêt à écrire.
     *
     * Le format de sortie suit celui d'entrée — un PNG reste un PNG, sa
     * transparence avec. Convertir systématiquement en JPEG remplirait de noir
     * le fond d'un logo transparent.
     */
    public function clean(UploadedFile $fichier, int $maxDimension): string
    {
        $source = $this->lire($fichier);

        $source = $this->applyOrientation($source, $fichier->getPathname());
        $source = $this->downscale($source, $maxDimension);

        $contenu = $this->encoder($source, $fichier->getMimeType() ?? 'image/jpeg');

        imagedestroy($source);

        return $contenu;
    }

    /* ---------------------------------------------------------------------- */

    private function lire(UploadedFile $fichier): GdImage
    {
        $binaire = file_get_contents($fichier->getPathname());

        if ($binaire === false) {
            throw new RuntimeException("Le fichier envoyé n'a pas pu être lu.");
        }

        $image = @imagecreatefromstring($binaire);

        if ($image === false) {
            // La validation `mimetypes:` a déjà vérifié le type réel ; arriver
            // ici signifie un fichier tronqué ou corrompu, pas une attaque.
            throw new RuntimeException("Cette image n'a pas pu être décodée.");
        }

        return $image;
    }

    /**
     * Applique la rotation notée dans l'EXIF, avant qu'elle ne disparaisse.
     *
     * Sans cela, toutes les photos prises en portrait sortiraient couchées. Les
     * valeurs 3, 6 et 8 couvrent les trois rotations ; les modes miroir (2, 4,
     * 5, 7) sont si rares qu'ils ne valent pas le code qu'ils coûteraient.
     */
    private function applyOrientation(GdImage $image, string $chemin): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($chemin);

        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        $angle = match ((int) $orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $tournee = imagerotate($image, $angle, 0);

        if ($tournee === false) {
            return $image;
        }

        imagedestroy($image);

        return $tournee;
    }

    /** Réduit l'image si elle dépasse la dimension voulue. Jamais l'inverse. */
    private function downscale(GdImage $image, int $max): GdImage
    {
        $largeur = imagesx($image);
        $hauteur = imagesy($image);

        if ($largeur <= $max && $hauteur <= $max) {
            // On n'AGRANDIT jamais : une petite image agrandie devient floue et
            // pèse plus lourd, pour aucun gain.
            return $image;
        }

        $ratio = $max / max($largeur, $hauteur);
        $nouvelleLargeur = max(1, (int) round($largeur * $ratio));
        $nouvelleHauteur = max(1, (int) round($hauteur * $ratio));

        $reduite = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);

        // La transparence doit être préservée AVANT la copie, sinon un PNG
        // transparent se retrouve sur fond noir.
        imagealphablending($reduite, false);
        imagesavealpha($reduite, true);

        imagecopyresampled(
            $reduite, $image,
            0, 0, 0, 0,
            $nouvelleLargeur, $nouvelleHauteur,
            $largeur, $hauteur,
        );

        imagedestroy($image);

        return $reduite;
    }

    /**
     * Ré-encode l'image — et c'est CE geste qui efface l'EXIF.
     *
     * GD ne recopie aucune métadonnée : l'image sortante ne porte que des
     * pixels. Il n'y a donc pas de « suppression » d'EXIF à faire, ce qui est
     * plus sûr qu'une liste de champs à effacer, laquelle finirait par en
     * oublier un.
     */
    private function encoder(GdImage $image, string $mime): string
    {
        ob_start();

        match ($mime) {
            'image/png' => imagepng($image, null, 8),
            'image/webp' => imagewebp($image, null, 85),
            // 85 : la qualité au-delà de laquelle personne ne voit la
            // différence sur une photo, et en deçà de laquelle les aplats
            // commencent à se marquer.
            default => imagejpeg($image, null, 85),
        };

        $contenu = ob_get_clean();

        if ($contenu === false || $contenu === '') {
            throw new RuntimeException("L'image n'a pas pu être ré-encodée.");
        }

        return $contenu;
    }
}
