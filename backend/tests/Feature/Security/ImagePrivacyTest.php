<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use App\Services\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LES IMAGES ENVOYÉES NE DOIVENT RIEN RÉVÉLER D'AUTRE QUE LEURS PIXELS.
 *
 * Une photo prise au téléphone porte, dans ses métadonnées EXIF, les
 * coordonnées GPS du lieu de la prise de vue. Invisibles à l'œil, lisibles par
 * n'importe quel outil en deux secondes.
 *
 * Un membre qui envoie sa photo de profil prise chez lui publierait donc son
 * adresse — sur un disque public, à une URL devinable, sans jamais l'avoir su.
 * `docs/risques.md` interdit cela pour les traces GPS ; une photo révèle la
 * même chose, en un point au lieu d'une trace.
 *
 * L'EXIF porte aussi le modèle du téléphone, la date exacte, et sur certains
 * appareils le nom du propriétaire. Rien de tout cela n'a de raison d'être
 * publié par un club de vélo.
 */
final class ImagePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function member(): Member
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        return Member::factory()->for($user)->create();
    }

    /**
     * Fabrique un JPEG portant de vraies coordonnées GPS dans son EXIF.
     *
     * On n'invente pas un EXIF factice : on écrit un segment APP1 conforme,
     * avec la structure TIFF que produirait un téléphone. Sans cela, le test
     * prouverait seulement qu'on sait effacer une chaîne de caractères.
     *
     * Les coordonnées sont celles du Monument de la Renaissance à Dakar — un
     * lieu public, choisi exprès : une fixture de test ne doit pas porter
     * l'adresse de quelqu'un.
     */
    private function photoAvecGps(): UploadedFile
    {
        $image = imagecreatetruecolor(600, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // Le segment APP1 : « Exif\0\0 » puis un en-tête TIFF little-endian.
        // On y place un IFD0 qui pointe vers un IFD GPS contenant la latitude.
        $exif = $this->segmentExifGps();

        // Inséré juste après le marqueur SOI (0xFFD8), là où un appareil photo
        // le placerait.
        $avecExif = substr($jpeg, 0, 2).$exif.substr($jpeg, 2);

        $chemin = tempnam(sys_get_temp_dir(), 'cyclo').'.jpg';
        file_put_contents($chemin, $avecExif);

        return new UploadedFile($chemin, 'maison.jpg', 'image/jpeg', null, true);
    }

    /**
     * Un segment APP1 réellement conforme, portant une position GPS.
     *
     * LES DÉCALAGES SONT CALCULÉS, PAS DEVINÉS — c'est tout le sujet.
     *
     * Un EXIF approximatif est simplement ignoré par les lecteurs, et le test
     * prouverait alors qu'on sait effacer… rien du tout. La première version de
     * cette fixture était dans ce cas : la garde l'a signalé, et c'est
     * précisément pourquoi elle existe.
     *
     * Structure, en octets depuis le début du bloc TIFF :
     *
     *   0  : « II », 42, puis le décalage de l'IFD0 (8)
     *   8  : IFD0 — 1 entrée (2 + 12 + 4 = 18 octets)
     *   26 : IFD GPS — 3 entrées (2 + 36 + 4 = 42 octets)
     *   68 : zone de données — la latitude, trois rationnels de 8 octets
     *
     * Une entrée fait toujours 12 octets : étiquette (2), type (2), nombre (4),
     * puis la valeur elle-même si elle tient dans 4 octets, sinon son décalage.
     */
    private function segmentExifGps(): string
    {
        $debutIfdGps = 26;
        $debutDonnees = 68;

        // IFD0 : une seule entrée, le pointeur vers l'IFD GPS (type 4 = LONG).
        $ifd0 = pack('v', 1)
            .pack('vvV', 0x8825, 4, 1).pack('V', $debutIfdGps)
            .pack('V', 0);

        // IFD GPS. Les entrées doivent être triées par étiquette croissante.
        $ifdGps = pack('v', 3)
            // GPSVersionID — 4 octets, ils tiennent dans le champ valeur.
            .pack('vvV', 0x0000, 1, 4)."\x02\x02\x00\x00"
            // GPSLatitudeRef — « N\0 », deux octets, inline eux aussi.
            .pack('vvV', 0x0001, 2, 2)."N\x00\x00\x00"
            // GPSLatitude — trois rationnels : 24 octets, donc un décalage.
            .pack('vvV', 0x0002, 5, 3).pack('V', $debutDonnees)
            .pack('V', 0);

        // 14° 43' 12" — le Monument de la Renaissance, à Dakar. Un lieu public,
        // choisi exprès : une fixture ne doit pas porter l'adresse de quelqu'un.
        $latitude = pack('VV', 14, 1).pack('VV', 43, 1).pack('VV', 12, 1);

        $tiff = 'II'.pack('v', 42).pack('V', 8).$ifd0.$ifdGps.$latitude;

        $this->assertSame(
            $debutIfdGps,
            8 + strlen($ifd0),
            "Le décalage de l'IFD GPS ne correspond pas à la taille réelle d'IFD0.",
        );
        $this->assertSame(
            $debutDonnees,
            $debutIfdGps + strlen($ifdGps),
            'Le décalage de la zone de données est faux.',
        );

        $app1 = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function la_fixture_porte_bien_un_exif_gps_avant_traitement(): void
    {
        /*
         | LA GARDE DU TEST LUI-MÊME.
         |
         | Si la fixture ne portait aucun EXIF, le test suivant passerait sans
         | rien prouver — il vérifierait qu'on n'a pas ajouté d'EXIF, ce que
         | personne n'a jamais fait. Un test qui rassure sans prouver est pire
         | qu'un test absent.
         */
        $fichier = $this->photoAvecGps();

        $brut = (string) file_get_contents($fichier->getPathname());

        $this->assertStringContainsString(
            'Exif',
            $brut,
            "La fixture ne porte pas d'EXIF : le test ne prouverait rien.",
        );

        // Et l'extension le lit vraiment comme une position.
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($fichier->getPathname());

            $this->assertIsArray($exif);
            $this->assertArrayHasKey(
                'GPSLatitudeRef',
                $exif,
                "La fixture n'expose aucune position GPS lisible.",
            );
        }
    }

    #[Test]
    public function une_photo_de_profil_perd_sa_position_gps(): void
    {
        $membre = $this->member();

        $this->actingAs_($membre->user)
            ->post("/api/v1/members/{$membre->uuid}", [
                '_method' => 'POST',
                'photo' => $this->photoAvecGps(),
            ])
            ->assertOk();

        $chemin = $membre->fresh()->photo_path;

        $this->assertNotNull($chemin, "La photo n'a pas été enregistrée.");

        $stocke = Storage::disk('public')->get($chemin);

        $this->assertStringNotContainsString(
            'Exif',
            (string) $stocke,
            'La photo stockée porte encore des métadonnées EXIF.',
        );
        $this->assertStringNotContainsString('GPS', (string) $stocke);
    }

    #[Test]
    public function un_fond_d_ecran_perd_aussi_sa_position_gps(): void
    {
        $membre = $this->member();

        $this->actingAs_($membre->user)
            ->post("/api/v1/members/{$membre->uuid}/cover", [
                'cover' => $this->photoAvecGps(),
            ])
            ->assertOk();

        $stocke = Storage::disk('public')->get((string) $membre->fresh()->cover_path);

        $this->assertStringNotContainsString('Exif', (string) $stocke);
    }

    #[Test]
    public function une_image_trop_grande_est_reduite(): void
    {
        /*
         | Un téléphone récent produit du 4 000 × 3 000. Servir huit
         | mégaoctets pour un avatar de 72 pixels, sur des forfaits mobiles
         | sénégalais, est un gaspillage que le club paie deux fois : en
         | stockage et en données de ses membres.
         */
        $membre = $this->member();

        $this->actingAs_($membre->user)
            ->post("/api/v1/members/{$membre->uuid}/cover", [
                'cover' => UploadedFile::fake()->image('immense.jpg', 4000, 3000),
            ])
            ->assertOk();

        $stocke = (string) Storage::disk('public')->get((string) $membre->fresh()->cover_path);

        $dimensions = getimagesizefromstring($stocke);

        $this->assertIsArray($dimensions);
        $this->assertLessThanOrEqual(ImageProcessor::COVER, $dimensions[0]);
        $this->assertLessThanOrEqual(ImageProcessor::COVER, $dimensions[1]);
    }

    #[Test]
    public function une_petite_image_n_est_jamais_agrandie(): void
    {
        // Agrandir rendrait l'image floue ET plus lourde : deux pertes pour
        // aucun gain.
        $membre = $this->member();

        $this->actingAs_($membre->user)
            ->post("/api/v1/members/{$membre->uuid}/cover", [
                'cover' => UploadedFile::fake()->image('petite.jpg', 320, 200),
            ])
            ->assertOk();

        $dimensions = getimagesizefromstring(
            (string) Storage::disk('public')->get((string) $membre->fresh()->cover_path),
        );

        $this->assertSame(320, $dimensions[0]);
        $this->assertSame(200, $dimensions[1]);
    }

    #[Test]
    public function un_png_transparent_garde_sa_transparence(): void
    {
        /*
         | Le piège classique du redimensionnement : sans préparer le canal
         | alpha AVANT la copie, GD remplit le fond de noir. Un logo de club
         | transparent en ressortirait sur un carré noir.
         */
        $processeur = app(ImageProcessor::class);

        $image = imagecreatetruecolor(2400, 1200);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        $chemin = tempnam(sys_get_temp_dir(), 'cyclo').'.png';
        imagepng($image, $chemin);
        imagedestroy($image);

        $nettoyee = $processeur->clean(
            new UploadedFile($chemin, 'logo.png', 'image/png', null, true),
            ImageProcessor::COVER,
        );

        $relue = imagecreatefromstring($nettoyee);
        $this->assertNotFalse($relue);

        $couleur = imagecolorat($relue, 5, 5);
        $alpha = ($couleur >> 24) & 0x7F;

        $this->assertSame(127, $alpha, 'La transparence a été remplacée par du noir.');

        imagedestroy($relue);
    }
}
