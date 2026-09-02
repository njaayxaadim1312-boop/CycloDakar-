<?php

declare(strict_types=1);

namespace Tests\Unit\Gps;

use App\Enums\Sport;
use App\Services\Gps\ActivityStatsCalculator;
use App\Services\Gps\ElevationCalculator;
use App\Services\Gps\GpsFilter;
use App\Services\Gps\GpsPoint;
use App\Services\Gps\PolylineEncoder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * L'algorithme GPS sur des traces RÉELLES.
 *
 * POURQUOI CELLES-CI VALENT MIEUX QUE LES TRACES SYNTHÉTIQUES.
 *
 * Les traces fabriquées ont bien servi : elles isolent un phénomène — un
 * tremblement, une dérive, un arrêt — et se règlent à volonté. Mais elles
 * ressemblent à ce qu'on CROIT que fait un GPS, pas à ce qu'il fait. Une vraie
 * trace porte les irrégularités qu'on n'aurait pas pensé à simuler : une
 * précision qui change en route, un intervalle qui saute parce que le téléphone
 * a hésité, une altitude qui dérive de dix mètres sur du plat.
 *
 * Ces quatre-là viennent d'un téléphone réel, à Dakar, en août et septembre
 * 2026. Trois sont les essais de marche qui ont produit le signalement
 * « les mètres ne sont pas pris » : elles sont conservées précisément pour
 * cela.
 *
 * CE QUE CES TESTS FIXENT, ET CE QU'ILS NE FIXENT PAS.
 *
 * Ils ne prétendent pas connaître la distance vraie — personne ne l'a mesurée
 * au décamètre. Ils fixent les PROPRIÉTÉS qui doivent tenir : un ordre de
 * grandeur pour la sortie vélo, et zéro pour ce qui n'est pas un déplacement.
 * Une borne large qui tient est plus utile qu'une valeur exacte qui casse au
 * moindre réglage.
 */
final class RealTraceTest extends TestCase
{
    /**
     * @return list<GpsPoint>
     */
    private function trace(string $nom): array
    {
        $chemin = __DIR__."/../../Fixtures/traces/{$nom}.json";

        $this->assertFileExists($chemin, "Fixture manquante : {$nom}");

        /** @var array{points: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($chemin), true);

        return array_map(
            fn (array $p) => GpsPoint::fromArray([
                'seq' => $p['seq'],
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'altitude_m' => $p['altitude_m'],
                'speed_mps' => $p['speed_mps'],
                'accuracy_m' => $p['accuracy_m'],
                'heading_deg' => $p['heading_deg'],
                'recorded_at' => $p['recorded_at'],
                'is_paused' => $p['is_paused'],
            ]),
            $fixture['points'],
        );
    }

    private function calculator(): ActivityStatsCalculator
    {
        return new ActivityStatsCalculator(new ElevationCalculator, new PolylineEncoder);
    }

    /**
     * @param  list<GpsPoint>  $points
     * @return array<string, mixed>
     */
    private function stats(array $points, Sport $sport): array
    {
        $filtre = new GpsFilter($sport);

        return $this->calculator()->calculate($filtre->apply($points), $sport);
    }

    /* ---------------------------------------------------------------------- */
    /* Une vraie sortie vélo                                                  */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_sortie_velo_reelle_est_mesuree_a_son_ordre_de_grandeur(): void
    {
        /*
         | 1 379 points, 23 minutes, sur la corniche de Dakar. C'est LA trace de
         | référence du projet : si un réglage la fait sortir de ces bornes, il
         | casse quelque chose de réel.
         |
         | Les bornes sont larges — 6 à 8 km — et volontairement : personne n'a
         | mesuré ce parcours au décamètre. Une borne large qui tient vaut mieux
         | qu'une valeur exacte qui casse au moindre ajustement de seuil.
         */
        $stats = $this->stats($this->trace('velo-dakar-7km'), Sport::Cycling);

        $this->assertGreaterThan(6_000, $stats['distance_m']);
        $this->assertLessThan(8_000, $stats['distance_m']);

        // Une vitesse moyenne de cycliste, pas de voiture ni de piéton.
        $this->assertGreaterThan(3.0, $stats['avg_speed_mps']);
        $this->assertLessThan(12.0, $stats['avg_speed_mps']);

        // Le temps actif ne peut pas dépasser la durée totale — l'invariant
        // qu'un cumul de temps a déjà violé une fois.
        $this->assertLessThanOrEqual($stats['duration_s'], $stats['moving_time_s']);

        // La trace simplifiée reste exploitable : une polyligne vide ferait
        // une carte blanche.
        $this->assertNotEmpty($stats['polyline']);
    }

    #[Test]
    public function la_sortie_velo_reelle_garde_presque_tous_ses_points(): void
    {
        // Le filtre en cascade doit écarter les aberrations, pas décimer une
        // trace saine. Rejeter plus de 10 % d'un enregistrement normal
        // signalerait un seuil trop serré.
        $points = $this->trace('velo-dakar-7km');
        $filtre = new GpsFilter(Sport::Cycling);
        $gardes = $filtre->apply($points);

        $this->assertGreaterThan(count($points) * 0.9, count($gardes));
    }

    /* ---------------------------------------------------------------------- */
    /* Les trois essais de marche du signalement                              */
    /* ---------------------------------------------------------------------- */

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function essaisDeMarche(): array
    {
        // Nom de fixture, et excursion maximale réellement observée depuis le
        // point de départ.
        return [
            'aller-retour de 13 m' => ['marche-aller-retour-13m', 12.7],
            'aller-retour de 10 m' => ['marche-aller-retour-10m', 9.7],
            'quelques pas sur place' => ['marche-sur-place-7m', 6.8],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('essaisDeMarche')]
    public function un_aller_retour_de_quelques_metres_ne_compte_pas(
        string $fixture,
        float $excursionReelle,
    ): void {
        /*
         | LE POINT LE PLUS DÉLICAT DE TOUT LE MODULE GPS, ET IL EST ASSUMÉ.
         |
         | Ces trois traces sont les essais qui ont produit le signalement
         | « en marchant, les mètres ne sont pas pris ». Elles mesurent zéro, et
         | c'est la bonne réponse.
         |
         | Le chemin brut point à point y fait 35 à 43 m. Mais l'excursion
         | maximale depuis le départ n'est que de 7 à 13 m, pour une précision
         | annoncée de 4 à 8 m. Autrement dit : la personne a fait quelques pas
         | et est revenue, dans un rayon à peine plus grand que l'incertitude de
         | son propre récepteur. Les 35 m de « chemin » sont, pour l'essentiel,
         | du bruit — quarante points multipliés par un mètre de tremblement.
         |
         | Compter ces mètres reviendrait à compter le tremblement, et c'est
         | exactement le défaut d'origine : 72 m réels affichés 135 m, 209 m
         | accumulés à l'arrêt complet.
         |
         | LA CONSÉQUENCE, DITE FRANCHEMENT : un aller-retour de moins d'une
         | quinzaine de mètres n'est pas mesurable par un téléphone. Pour
         | éprouver la marche, il faut marcher EN LIGNE, sur cinquante mètres au
         | moins. Ce n'est pas un défaut qu'on peut régler : c'est la limite de
         | l'instrument.
         */
        $stats = $this->stats($this->trace($fixture), Sport::Walking);

        $this->assertSame(
            0,
            $stats['distance_m'],
            "Le bruit d'un aller-retour de {$excursionReelle} m est compté comme du déplacement.",
        );

        // Et donc aucun temps actif : annoncer « 0 m en 90 s de marche »
        // n'aurait aucun sens, et l'allure calculée dessus serait absurde.
        $this->assertSame(0, $stats['moving_time_s']);
    }

    #[Test]
    public function ces_essais_gardent_bien_leurs_points_malgre_tout(): void
    {
        /*
         | Distinction qui compte : les points sont RETENUS, c'est la DISTANCE
         | qui vaut zéro.
         |
         | Si le filtre les rejetait, la carte serait vide et l'on conclurait à
         | une panne d'enregistrement. Ici la trace existe, elle se voit sur la
         | carte, elle montre bien que quelqu'un a bougé sur place — et le
         | compteur reste à zéro parce que ce mouvement n'est pas un
         | déplacement.
         */
        foreach (['marche-aller-retour-13m', 'marche-aller-retour-10m', 'marche-sur-place-7m'] as $fixture) {
            $points = $this->trace($fixture);
            $gardes = (new GpsFilter(Sport::Walking))->apply($points);

            $this->assertGreaterThan(
                count($points) * 0.8,
                count($gardes),
                "La trace {$fixture} a été décimée par le filtre.",
            );
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Non-régression sur les défauts déjà corrigés                           */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function les_seuils_par_sport_ne_font_pas_diverger_la_meme_realite(): void
    {
        /*
         | La même trace, lue comme du vélo puis comme de la course. Les seuils
         | diffèrent — 5 m contre 3 m de segment minimal, 0,8 contre 0,5 m/s
         | d'allure d'arrêt — mais la réalité, elle, ne change pas.
         |
         | Un écart de plus de 10 % signalerait que les seuils d'un sport
         | comptent du bruit que l'autre écarte, c'est-à-dire qu'un des deux
         | ment.
         |
         | On compare avec la COURSE et non avec la marche : le filtre de marche
         | rejette légitimement une trace à 25 km/h, et la comparaison n'aurait
         | alors mesuré que ce rejet — voir le test suivant.
         */
        $velo = $this->stats($this->trace('velo-dakar-7km'), Sport::Cycling);
        $course = $this->stats($this->trace('velo-dakar-7km'), Sport::Running);

        $ecart = abs($velo['distance_m'] - $course['distance_m']) / max(1, $velo['distance_m']);

        $this->assertLessThan(
            0.10,
            $ecart,
            'Les seuils par sport font diverger la même trace de plus de 10 %.',
        );
    }

    #[Test]
    public function une_sortie_velo_declaree_en_marche_est_rejetee_et_le_dit(): void
    {
        /*
         | Se tromper de sport au départ est une erreur courante — le bouton est
         | à côté. Le filtre écarte alors la trace entière : 25 km/h n'est pas
         | une allure de marche, et l'accepter produirait une « marche » de 7 km
         | qui fausserait les classements et les défis de tout le club.
         |
         | CE QUI COMPTE ICI, C'EST QUE LE REJET SOIT EXPLICABLE. Chaque point
         | écarté est compté par motif : sans cela, un membre verrait une trace
         | vide sans jamais comprendre pourquoi, et conclurait à une panne.
         */
        $filtre = new GpsFilter(Sport::Walking);
        $gardes = $filtre->apply($this->trace('velo-dakar-7km'));

        $this->assertLessThan(50, count($gardes));

        $motifs = $filtre->rejections();

        $this->assertArrayHasKey(GpsFilter::REASON_SPEED, $motifs);
        $this->assertGreaterThan(100, $motifs[GpsFilter::REASON_SPEED]);
    }
}
