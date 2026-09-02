<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Sport;
use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LES FICHIERS MIROIRS DISENT-ILS LA MÊME CHOSE ?
 *
 * `CLAUDE.md` liste quatre paires de fichiers que le web et le mobile doivent
 * garder identiques À LA MAIN — l'ADR-006 a écarté un paquet partagé, pour de
 * bonnes raisons. Le prix de ce choix, c'est qu'une valeur peut diverger sans
 * que rien ne le signale.
 *
 * CE N'EST PAS UNE CRAINTE THÉORIQUE. C'EST DÉJÀ ARRIVÉ, DEUX FOIS.
 *
 * Le seuil de segment minimal du vélo est resté à 1 m côté client alors que le
 * serveur utilisait 5 : le compteur affiché pendant la sortie ne correspondait
 * pas au résultat final, et personne ne comprenait pourquoi la distance
 * changeait à l'arrivée.
 *
 * Puis, en corrigeant la marche, le seuil d'immobilité a été abaissé sur le web
 * avant de l'être sur le mobile : la même promenade aurait compté sur un
 * appareil et pas sur l'autre.
 *
 * Ces divergences sont invisibles à la relecture — les fichiers font trois
 * cents lignes chacun, et l'œil glisse sur un `0.8` qui aurait dû être `0.3`.
 * Une machine, elle, ne glisse pas.
 *
 * POURQUOI CE TEST VIT CÔTÉ SERVEUR.
 *
 * Parce que le serveur porte la valeur de RÉFÉRENCE, dans `config/cyclo.php` :
 * c'est lui qui recalcule tout à la finalisation, et c'est donc son chiffre qui
 * fait foi. Les deux clients sont comparés à lui, pas l'un à l'autre — sans
 * quoi ils pourraient être d'accord tous les deux, et tous les deux faux.
 */
final class MirrorsTest extends TestCase
{
    private const WEB = __DIR__.'/../../../web/src';

    private const MOBILE = __DIR__.'/../../../mobile/src';

    /* ---------------------------------------------------------------------- */

    private function lire(string $chemin): string
    {
        $this->assertFileExists($chemin, "Fichier miroir introuvable : {$chemin}");

        return (string) file_get_contents($chemin);
    }

    /**
     * Extrait les seuils d'un sport dans un `DEFAULT_THRESHOLDS` TypeScript.
     *
     * Lecture par expression régulière, faute de mieux : évaluer du TypeScript
     * depuis PHP demanderait Node, et rendrait la suite de tests dépendante
     * d'un outil de plus. Le format est stable et la lecture échoue
     * bruyamment si elle ne trouve pas ce qu'elle cherche — c'est suffisant.
     *
     * @return array<string, float>
     */
    private function seuilsTypeScript(string $source, Sport $sport): array
    {
        $bloc = null;

        if (preg_match(
            '/'.$sport->value.':\s*\{(.*?)\n  \},/s',
            $source,
            $correspondance,
        ) === 1) {
            $bloc = $correspondance[1];
        }

        $this->assertNotNull(
            $bloc,
            "Le sport {$sport->value} est absent de DEFAULT_THRESHOLDS.",
        );

        $valeurs = [];

        preg_match_all('/(\w+):\s*([\d.]+)\s*,/', (string) $bloc, $paires, PREG_SET_ORDER);

        foreach ($paires as $paire) {
            $valeurs[$paire[1]] = (float) $paire[2];
        }

        return $valeurs;
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function les_seuils_gps_des_clients_suivent_ceux_du_serveur(): void
    {
        $web = $this->lire(self::WEB.'/lib/gps.ts');
        $mobile = $this->lire(self::MOBILE.'/lib/gps.ts');

        // Ce qui doit correspondre, et le nom que chaque côté lui donne.
        $correspondances = [
            'min_distance_m' => 'minSegmentM',
            'max_accuracy_m' => 'maxAccuracyM',
            'max_speed_mps' => 'maxSpeedMps',
            'idle_speed_mps' => 'idleSpeedMps',
        ];

        $ecarts = [];

        foreach (Sport::cases() as $sport) {
            $seuilsWeb = $this->seuilsTypeScript($web, $sport);
            $seuilsMobile = $this->seuilsTypeScript($mobile, $sport);

            foreach ($correspondances as $clePhp => $cleTs) {
                $reference = config("cyclo.sports.{$sport->value}.{$clePhp}");

                if ($reference === null) {
                    // `idle_speed_mps` peut retomber sur le réglage global.
                    $reference = config('cyclo.gps.'.$clePhp);
                }

                if ($reference === null) {
                    continue;
                }

                foreach (['web' => $seuilsWeb, 'mobile' => $seuilsMobile] as $cote => $seuils) {
                    if (! isset($seuils[$cleTs])) {
                        $ecarts[] = "{$sport->value}.{$cleTs} : absent côté {$cote}";

                        continue;
                    }

                    if (abs($seuils[$cleTs] - (float) $reference) > 0.001) {
                        $ecarts[] = sprintf(
                            '%s.%s : serveur %s, %s %s',
                            $sport->value,
                            $cleTs,
                            $reference,
                            $cote,
                            $seuils[$cleTs],
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $ecarts,
            "Les seuils GPS divergent entre le serveur et les clients :\n  "
            .implode("\n  ", $ecarts),
        );
    }

    #[Test]
    public function les_constantes_partagees_du_gps_sont_identiques_partout(): void
    {
        /*
         | Le facteur de précision, la durée de confirmation, la fenêtre du
         | temps actif : trois nombres qui décident de ce qui compte comme un
         | déplacement. Un écart entre serveur et client ferait diverger le
         | compteur affiché pendant la sortie du résultat final — exactement ce
         | que le membre remarque le plus.
         */
        $attendus = [
            'ACCURACY_FACTOR' => (float) config('cyclo.gps.accuracy_factor'),
            'CONFIRM_MOVE_MS' => (float) config('cyclo.gps.confirm_move_s') * 1000,
            'STOP_WINDOW_MS' => (float) config('cyclo.gps.stop_window_s') * 1000,
        ];

        $sources = [
            'web' => $this->lire(self::WEB.'/lib/recording.ts'),
            'mobile' => $this->lire(self::MOBILE.'/services/locationTask.ts'),
        ];

        $ecarts = [];

        foreach ($sources as $cote => $source) {
            foreach ($attendus as $constante => $valeur) {
                if (preg_match(
                    '/const '.$constante.' = ([\d_.]+)/',
                    $source,
                    $correspondance,
                ) !== 1) {
                    $ecarts[] = "{$constante} : absente côté {$cote}";

                    continue;
                }

                $trouvee = (float) str_replace('_', '', $correspondance[1]);

                if (abs($trouvee - $valeur) > 0.001) {
                    $ecarts[] = "{$constante} : serveur {$valeur}, {$cote} {$trouvee}";
                }
            }
        }

        $this->assertSame(
            [],
            $ecarts,
            "Les constantes GPS divergent :\n  ".implode("\n  ", $ecarts),
        );
    }

    #[Test]
    public function la_hierarchie_des_roles_est_la_meme_des_deux_cotes(): void
    {
        /*
         | `hasAtLeastRole` décide côté client de ce qu'on AFFICHE ; les Policies
         | décident côté serveur de ce qu'on AUTORISE. Une hiérarchie qui
         | diverge ne crée donc pas de faille — mais elle produit des boutons
         | qui répondent 403, et un utilisateur qui conclut à une panne.
         |
         | Le chef de groupe, ajouté récemment, est précisément le genre de rôle
         | qu'on oublie d'ajouter au second fichier.
         */
        $sources = [
            'web' => $this->lire(self::WEB.'/stores/auth.ts'),
            'mobile' => $this->lire(self::MOBILE.'/stores/auth.ts'),
        ];

        $ecarts = [];

        foreach ($sources as $cote => $source) {
            foreach (UserRole::cases() as $role) {
                if (preg_match(
                    '/'.$role->value.':\s*(\d+)\s*,/',
                    $source,
                    $correspondance,
                ) !== 1) {
                    $ecarts[] = "{$role->value} : absent de ROLE_LEVEL côté {$cote}";

                    continue;
                }

                if ((int) $correspondance[1] !== $role->level()) {
                    $ecarts[] = sprintf(
                        '%s : serveur %d, %s %d',
                        $role->value,
                        $role->level(),
                        $cote,
                        (int) $correspondance[1],
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $ecarts,
            "La hiérarchie des rôles diverge :\n  ".implode("\n  ", $ecarts),
        );
    }

    #[Test]
    public function les_quatre_paires_de_fichiers_miroirs_existent_toujours(): void
    {
        /*
         | Si l'une disparaît — renommée, déplacée, fusionnée — les tests
         | ci-dessus cesseraient silencieusement de vérifier quoi que ce soit.
         | Un test qui ne teste plus rien est pire qu'un test absent : il
         | rassure.
         */
        $paires = [
            ['/lib/api.ts', '/lib/api.ts'],
            ['/lib/format.ts', '/lib/format.ts'],
            ['/types/api.ts', '/types/api.ts'],
            ['/styles/tokens.css', null],
        ];

        foreach ($paires as [$cheminWeb, $cheminMobile]) {
            $this->assertFileExists(self::WEB.$cheminWeb, "Miroir web manquant : {$cheminWeb}");

            if ($cheminMobile !== null) {
                $this->assertFileExists(
                    self::MOBILE.$cheminMobile,
                    "Miroir mobile manquant : {$cheminMobile}",
                );
            }
        }

        // Le pendant du fichier de jetons CSS vit sous un autre nom.
        $this->assertFileExists(self::MOBILE.'/theme/tokens.ts');
    }
}
