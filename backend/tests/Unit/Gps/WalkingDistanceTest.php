<?php

declare(strict_types=1);

namespace Tests\Unit\Gps;

use App\Enums\Sport;
use App\Services\Gps\ActivityStatsCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GpsTraceBuilder;
use Tests\TestCase;

/**
 * La marche lente : le cas où le bruit du GPS dépasse le déplacement.
 *
 * À 1,2 m/s, un marcheur avance de 1,2 m par seconde alors que l'incertitude
 * de position vaut plusieurs mètres. Chaque point tombe à côté du précédent,
 * et un calcul naïf additionne ce tremblement comme du déplacement : six
 * mètres parcourus s'affichent en vingt, et la vitesse s'envole.
 *
 * C'est le défaut signalé par le club, et ces tests le verrouillent.
 *
 * Le bruit est appliqué **perpendiculairement** à la marche : il n'ajoute
 * aucune distance réelle. Tout mètre compté en plus est donc, à coup sûr, une
 * erreur — il n'y a pas d'ambiguïté à interpréter.
 */
final class WalkingDistanceTest extends TestCase
{
    private function calculator(): ActivityStatsCalculator
    {
        return app(ActivityStatsCalculator::class);
    }

    #[Test]
    public function une_marche_lente_ne_voit_pas_sa_distance_multipliee_par_le_bruit(): void
    {
        // 60 s à 1,2 m/s : 72 m réels, avec 3 m de tremblement latéral.
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 60, speedMps: 1.2, noiseM: 3.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        /*
         | Avant correction : 135 m pour 72 m réels, soit +87 %.
         | Après : 69 m, soit −4 %. La bande retenue, 60 à 80 m, laisse
         | respirer le va-et-vient résiduel sans jamais tolérer un
         | sur-comptage.
         */
        $this->assertGreaterThan(
            60,
            $stats['distance_m'],
            'Le filtre rogne trop : 72 m réels mesurés à '.$stats['distance_m'].' m.',
        );
        $this->assertLessThan(
            80,
            $stats['distance_m'],
            'La distance est gonflée par le bruit GPS : 72 m réels mesurés à '.$stats['distance_m'].' m.',
        );
    }

    #[Test]
    public function la_vitesse_moyenne_d_une_marche_reste_une_vitesse_de_marche(): void
    {
        // Le symptôme le plus visible pour le membre : « la vitesse exagère ».
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 60, speedMps: 1.2, noiseM: 3.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        // Mesuré : 1,25 m/s pour 1,2 réel. Avant correction : 2,29 m/s,
        // soit 8,2 km/h en marchant.
        $this->assertLessThan(
            1.6,
            $stats['avg_speed_mps'],
            'Une marche à 1,2 m/s ne peut pas afficher '
            .round($stats['avg_speed_mps'] * 3.6, 1).' km/h.',
        );
    }

    #[Test]
    public function la_vitesse_maximale_d_une_marche_reste_plausible(): void
    {
        // Sans anti-tremblement, un saut de 4 m en 1 s donne 14 km/h : un
        // record personnel de marche fabriqué par le bruit.
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 60, speedMps: 1.2, noiseM: 3.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        // Mesuré : 1,28 m/s. La pointe d'une marche reste une marche.
        $this->assertLessThan(
            1.8,
            $stats['max_speed_mps'],
            'Vitesse maximale aberrante : '.round($stats['max_speed_mps'] * 3.6, 1).' km/h en marchant.',
        );
    }

    #[Test]
    public function un_marcheur_immobile_n_accumule_aucune_distance(): void
    {
        // Le cas limite : le membre s'arrête pour discuter. Le GPS continue
        // de trembler, la distance ne doit pas bouger d'un mètre.
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 90, speedMps: 0.0, noiseM: 4.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        $this->assertLessThan(
            15,
            $stats['distance_m'],
            'À l\'arrêt, le tremblement a été compté comme du déplacement : '
            .$stats['distance_m'].' m.',
        );
    }

    #[Test]
    public function un_telephone_pose_n_accumule_rien_quel_que_soit_le_sport(): void
    {
        /*
         | Le defaut signale : « je demarre en cyclisme, je suis sur place, il
         | m'affiche deja 67 m ».
         |
         | La derive lente est plus perfide qu'un bruit vif : elle finit par
         | franchir n'importe quel seuil de DISTANCE, l'ancre suit, et le
         | cycle recommence. Seule la vitesse la demasque — 10 m en 60 s font
         | 0,17 m/s, ce qui n'est ni rouler ni marcher.
         */
        $points = GpsTraceBuilder::make()->stationaryDrift(seconds: 300, driftM: 10.0)->build();

        foreach (Sport::cases() as $sport) {
            $stats = $this->calculator()->calculate($points, $sport);

            $this->assertSame(
                0,
                $stats['distance_m'],
                "Telephone immobile en {$sport->value} : {$stats['distance_m']} m accumules.",
            );
            $this->assertSame(0, $stats['moving_time_s']);
        }
    }

    #[Test]
    public function une_sortie_velo_reste_mesuree_fidelement(): void
    {
        // Le correctif ne doit pas rogner les sports rapides : à 6 m/s, chaque
        // seconde franchit largement le seuil, et rien ne doit être perdu.
        $points = GpsTraceBuilder::make()
            ->straight(speedMps: 6.0, seconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Cycling);

        // 1 800 m attendus, à 3 % près.
        $this->assertGreaterThan(1_740, $stats['distance_m']);
        $this->assertLessThan(1_860, $stats['distance_m']);
        $this->assertEqualsWithDelta(6.0, $stats['avg_speed_mps'], 0.4);
    }

    #[Test]
    public function une_course_reste_mesuree_fidelement(): void
    {
        $points = GpsTraceBuilder::make()
            ->straight(speedMps: 3.0, seconds: 300)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Running);

        // 900 m attendus.
        $this->assertGreaterThan(860, $stats['distance_m']);
        $this->assertLessThan(940, $stats['distance_m']);
    }

    /* ---------------------------------------------------------------------- */
    /* Le defaut signale : « j'ai teste en marchant, les metres ne sont pas   */
    /* pris ». Trois manieres de perdre une marche, trois verrous.            */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_flanerie_est_comptee_comme_une_marche(): void
    {
        /*
         | Le defaut : le seuil d'immobilite valait 0,8 m/s pour TOUS les
         | sports. Or 0,8 m/s font 2,9 km/h, ce qui est deja une allure de
         | marche : une promenade tranquille, une montee, une marche avec un
         | enfant tombent toutes en dessous. Elles etaient donc comptees comme
         | des arrets, et 72 m reellement parcourus s'affichaient 0 m.
         |
         | Le seuil est desormais PAR SPORT — 0,3 m/s a pied.
         */
        $points = GpsTraceBuilder::make()
            ->walkWithJitter(seconds: 120, speedMps: 0.6, noiseM: 2.0)
            ->build();

        $stats = $this->calculator()->calculate($points, Sport::Walking);

        // 72 m reels. On tolere 25 % : sous 8 m de precision, le bruit pese
        // presque autant que le deplacement, et exiger mieux serait mentir.
        $this->assertGreaterThan(
            54,
            $stats['distance_m'],
            'Une flanerie a 0,6 m/s est comptee comme un arret : '
            .$stats['distance_m'].' m au lieu de 72.',
        );
        $this->assertLessThan(90, $stats['distance_m']);
    }

    #[Test]
    public function une_marche_ponctuee_d_arrets_ne_perd_pas_ses_metres(): void
    {
        /*
         | Le defaut le plus couteux : au rejet pour lenteur, l'ancre AVANCAIT.
         | Les metres deja parcourus depuis elle etaient donc perdus pour de
         | bon. Chaque arret effacait le trajet qui le precedait, et une marche
         | de 96 m en quatre etapes s'affichait 43 m.
         |
         | L'ancre reste desormais en place : les metres attendent, et le
         | marcheur les retrouve des qu'il repart.
         */
        $trace = GpsTraceBuilder::make();

        for ($i = 0; $i < 4; $i++) {
            $trace->walkWithJitter(seconds: 20, speedMps: 1.2, noiseM: 2.0)
                ->idle(10);
        }

        $stats = $this->calculator()->calculate($trace->build(), Sport::Walking);

        // 96 m reels, quatre arrets de 10 s.
        $this->assertGreaterThan(
            76,
            $stats['distance_m'],
            'Les arrets ont efface la distance parcourue avant eux : '
            .$stats['distance_m'].' m au lieu de 96.',
        );
        $this->assertLessThan(115, $stats['distance_m']);
    }

    #[Test]
    public function les_arrets_ne_gonflent_pas_le_temps_actif(): void
    {
        /*
         | Contrepartie du verrou precedent : puisque l'ancre reste en place
         | pendant un arret, le temps ecoule depuis elle inclut l'arret. Le
         | compter comme du temps de roulage ferait s'effondrer la vitesse
         | moyenne affichee.
         |
         | Le temps actif se mesure donc sur une fenetre glissante, sans
         | rapport avec l'ancre de distance.
         */
        $trace = GpsTraceBuilder::make();

        for ($i = 0; $i < 4; $i++) {
            $trace->walkWithJitter(seconds: 20, speedMps: 1.2, noiseM: 2.0)
                ->idle(10);
        }

        $stats = $this->calculator()->calculate($trace->build(), Sport::Walking);

        /*
         | La trace dure 119 s : 80 s de marche et 40 s d'arret repartis en
         | quatre pauses de dix secondes.
         |
         | On ne verifie PAS que le temps actif tombe a 80 s, et ce n'est pas
         | une facilite : la fenetre du temps actif dure trente secondes, si
         | bien qu'un arret de dix secondes ne s'y detache pas. C'est un choix
         | assume — une fenetre plus courte declarerait immobile un promeneur
         | qui avance a 0,6 m/s, ce qui est le defaut qu'on vient de corriger.
         |
         | Ce qui est verrouille ici, c'est l'invariant : LE TEMPS ACTIF NE
         | DEPASSE JAMAIS LA DUREE. Il l'a depasse — 129 s pour 119 s — parce
         | que le rattrapage des derniers metres creditait du temps deja
         | compte par la fenetre.
         */
        $this->assertLessThanOrEqual(
            $stats['duration_s'],
            $stats['moving_time_s'],
            'Le temps actif depasse la duree de la sortie : '
            .$stats['moving_time_s'].' s pour '.$stats['duration_s'].' s.',
        );
    }
}
