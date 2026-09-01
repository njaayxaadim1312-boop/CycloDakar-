<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Enums\Sport;

/**
 * Calcul des statistiques d'une activité, à partir des points bruts.
 *
 * **Le client n'est jamais cru.** Le téléphone affiche des chiffres pendant la
 * sortie pour le confort de l'utilisateur, mais tout est recalculé ici à la
 * finalisation. C'est ce qui garantit qu'un client modifié ne peut pas
 * s'inventer 200 km pour gagner le classement du mois.
 *
 * Toutes les valeurs produites sont en unités SI : mètres, secondes, m/s.
 * La conversion en km, km/h et min/km appartient à l'affichage.
 *
 * Algorithmes détaillés dans docs/gps.md.
 */
final class ActivityStatsCalculator
{
    public function __construct(
        private readonly ElevationCalculator $elevation = new ElevationCalculator,
        private readonly PolylineEncoder $polyline = new PolylineEncoder,
    ) {}

    /**
     * @param  list<GpsPoint>  $points  Points DÉJÀ filtrés, triés par `seq`.
     * @return array<string, mixed>
     */
    public function calculate(array $points, Sport $sport, ?float $weightKg = null): array
    {
        if (count($points) < 2) {
            return $this->emptyStats(count($points) === 1 ? $points[0] : null);
        }

        // Le sport decide du seuil sous lequel un deplacement n'en est pas
        // un : six metres franchis en une seconde a velo, mais un metre
        // vingt a pied.
        $movement = $this->measureMovement($points, $sport);
        $elevation = $this->elevation->calculate($points);

        $first = $points[0];
        $last = $points[count($points) - 1];

        $durationS = (int) round($last->secondsSince($first));
        $movingTimeS = (int) round($movement['moving_time_s']);
        $distanceM = (int) round($movement['distance_m']);

        // La vitesse moyenne se calcule sur le temps ACTIF, pas sur le temps
        // total : sinon une pause déjeuner de 40 min ferait passer une sortie
        // à 12 km/h de moyenne alors qu'on roulait à 24.
        $avgSpeed = $movingTimeS > 0 ? $distanceM / $movingTimeS : 0.0;

        $simplified = $this->polyline->simplify($points);

        return [
            'distance_m' => $distanceM,
            'duration_s' => $durationS,
            'moving_time_s' => $movingTimeS,
            'paused_time_s' => max(0, $durationS - $movingTimeS),

            'avg_speed_mps' => round($avgSpeed, 3),
            'max_speed_mps' => round($movement['max_speed_mps'], 3),

            'elevation_gain_m' => $elevation['gain'],
            'elevation_loss_m' => $elevation['loss'],
            'min_altitude_m' => $elevation['min'],
            'max_altitude_m' => $elevation['max'],

            'avg_pace_s_per_km' => $this->pace($distanceM, $movingTimeS),
            'best_pace_s_per_km' => $this->bestSplitPace($movement['splits']),

            'calories_kcal' => $this->calories($sport, $movingTimeS, $weightKg),

            'polyline' => $this->polyline->encode($simplified),
            'bounds' => $this->polyline->bounds($points),

            'start_lat' => $first->lat,
            'start_lng' => $first->lng,
            'end_lat' => $last->lat,
            'end_lng' => $last->lng,

            'points_count' => count($points),

            'splits' => $movement['splits'],
            'elevation_profile' => $this->elevationProfile($points),
            'speed_histogram' => $movement['histogram'],
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Parcourt la trace une seule fois pour en tirer distance, temps actif,
     * vitesse maximale, splits kilométriques et histogramme.
     *
     * Un seul passage plutôt que cinq : sur 10 000 points, relire la trace à
     * chaque mesure quintuplerait le coût de la finalisation, qui se produit
     * au moment précis où le membre attend son résumé.
     *
     * @param  list<GpsPoint>  $points
     * @return array{distance_m: float, moving_time_s: float, max_speed_mps: float,
     *               splits: list<array<string, mixed>>, histogram: array<string, int>}
     */
    private function measureMovement(array $points, Sport $sport): array
    {
        /*
         | Seuil de deplacement reel, PAR SPORT.
         |
         | Le seuil global de 1 m convenait au velo, ou une seconde franchit
         | six metres. A la marche, il ne filtrait rien : a 1,2 m/s le membre
         | avance moins vite que ne bouge l'incertitude de position, et chaque
         | tremblement passait pour un deplacement. Mesure sur trace
         | synthetique : 72 m reels comptes 135 m, et 209 m accumules a
         | l'arret complet.
         */
        $minSegment = (float) config(
            "cyclo.sports.{$sport->value}.min_distance_m",
            config('cyclo.gps.min_segment_m', 1.0),
        );

        /*
         | Vitesse en deca de laquelle on n'est pas « en mouvement », PAR SPORT.
         |
         | Elle ne sert plus qu'a compter le TEMPS ACTIF. Un seuil global de
         | 0,8 m/s (2,9 km/h) etait deja de la marche : une flanerie, une
         | montee, une promenade avec un enfant passent toutes en dessous. Le
         | velo garde 0,8 — sous cette allure on pousse son velo.
         */
        $idleSpeed = (float) config(
            "cyclo.sports.{$sport->value}.idle_speed_mps",
            config('cyclo.gps.idle_speed_mps', 0.8),
        );

        /*
         | Duree de CONFIRMATION d'un franchissement, en secondes.
         |
         | Voir le long commentaire plus bas, au moment du credit.
         */
        $confirmSeconds = (float) config('cyclo.gps.confirm_move_s', 2.0);

        // Franchissement en attente de confirmation : [point, distance].
        $pending = null;

        $distance = 0.0;
        $maxSpeed = 0.0;

        /*
         | LE TEMPS ACTIF EST CALCULE A PART, ET C'EST DELIBERE.
         |
         | « Quelle distance a-t-il parcourue ? » et « bougeait-il a cet
         | instant ? » sont deux questions differentes, et les melanger a
         | produit un defaut concret : l'ancre de distance reste en place
         | pendant un arret — c'est justement ce qui evite de perdre les
         | metres deja parcourus — mais le temps ecoule depuis cette ancre
         | inclut alors l'arret tout entier. Un feu rouge de trois minutes
         | entrait ainsi dans le temps de roulage, et la vitesse moyenne
         | affichee devenait incomprehensible.
         */
        $movingTime = $this->movingTime($points, $minSegment);

        $splits = [];
        $splitDistance = 0.0;
        $splitTime = 0.0;
        $splitIndex = 1;

        /** @var array<string, int> $histogram */
        $histogram = [];

        // Lissage de la vitesse : la vitesse maximale est prise sur la valeur
        // LISSEE, jamais sur une mesure isolee. Sans cela, un seul point
        // aberrant fixerait un record personnel a 87 km/h.
        $smoothedSpeed = null;

        /*
         | ANCRE.
         |
         | On ne compare pas chaque point au precedent, mais au dernier point
         | qui a produit un deplacement REEL. C'est toute la correction.
         |
         | Comparer de proche en proche laissait le bruit s'accumuler : chaque
         | tremblement etait mesure depuis le tremblement d'avant, et tous
         | ceux qui depassaient le seuil s'additionnaient. Avec une ancre
         | fixe, un membre immobile reste a quelques metres de son ancre quoi
         | qu'affiche le GPS, et rien ne s'accumule.
         |
         | Effet de bord bienvenu : la vitesse se calcule sur une base plus
         | longue, donc bien plus stable.
         */
        $anchor = $points[0] ?? null;

        if ($anchor === null) {
            return [
                'distance_m' => 0.0,
                'moving_time_s' => 0.0,
                'max_speed_mps' => 0.0,
                'splits' => [],
                'speed_histogram' => [],
            ];
        }

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $current = $points[$i];

            /*
             | Une pause declaree remet l'ancre a zero.
             |
             | Sans cela, le trajet parcouru pendant la pause — le membre peut
             | rentrer en taxi — serait mesure d'un seul bloc a la reprise et
             | compte comme une distance parcourue.
             */
            if ($current->isPaused) {
                $anchor = $current;

                continue;
            }

            $elapsed = $current->secondsSince($anchor);

            if ($elapsed <= 0.0) {
                continue;
            }

            $segment = Distance::between($anchor, $current);

            /*
             | Sous le seuil : c'est le tremblement du GPS, pas un
             | deplacement. On ignore le point ET ON GARDE L'ANCRE : c'est
             | precisement en avancant l'ancre que l'ancienne version
             | accumulait le bruit.
             |
             | Le seuil s'ADAPTE a la precision annoncee par l'appareil. Un
             | point donne a plus ou moins 20 m ne prouve pas un deplacement
             | de 9 m : exiger davantage quand le signal est mauvais evite de
             | compter du bruit sous un ciel bouche, sans rien rogner quand
             | la reception est bonne — le seuil du sport domine alors.
             */
            $seuil = max($minSegment, $this->uncertainty($anchor, $current));

            if ($segment < $seuil) {
                /*
                 | Sous le seuil : c'est le tremblement du GPS, pas un
                 | deplacement. On ignore le point ET ON GARDE L'ANCRE.
                 |
                 | Garder l'ancre est essentiel : c'est en l'avancant que la
                 | version d'origine accumulait le bruit, et c'est en la
                 | gardant qu'une marche lente finit par etre comptee. A
                 | 0,6 m/s, il faut une quinzaine de secondes pour franchir
                 | 10 m — mais on les franchit, et ils sont credites.
                 |
                 | Un franchissement en attente qui retombe sous le seuil est
                 | ANNULE : c'etait un sursaut de derive, pas un depart.
                 */
                $pending = null;

                continue;
            }

            /*
             | LE FRANCHISSEMENT DOIT SE CONFIRMER.
             |
             | Sans cette regle, un recepteur immobile finit par compter des
             | metres : l'erreur GPS n'est pas un bruit blanc, elle DERIVE de
             | facon correlee et franchit donc n'importe quel seuil de
             | distance si on lui laisse le temps. C'est ce qui affichait 67 m
             | a l'arret complet.
             |
             | La version precedente tranchait par la vitesse moyenne depuis
             | l'ancre. C'etait une erreur, et elle coutait cher :
             |
             |   - une flanerie a 0,6 m/s tombait sous le seuil et n'etait
             |     JAMAIS comptee — 72 m reels affiches 0 m ;
             |   - pire, l'ancre AVANCAIT au rejet, ce qui jetait les metres
             |     deja parcourus. Une marche avec arrets perdait la moitie de
             |     sa distance : 96 m reels comptes 43 m, parce que chaque
             |     arret effacait le trajet qui le precedait.
             |
             | La confirmation separe proprement les deux questions. Un
             | deplacement REEL s'eloigne de l'ancre et Y RESTE ; une derive
             | franchit le seuil puis revient. On attend donc quelques
             | secondes avant de crediter, et on annule si le point retombe.
             |
             | Ce que cette regle coute : les deux dernieres secondes d'une
             | sortie qui s'arrete pile sur un franchissement. Ce qu'elle
             | evite : compter du vent.
             */
            if ($pending === null) {
                $pending = $current;
            }

            if ($pending->secondsSince($anchor) > 0.0
                && $current->secondsSince($pending) < $confirmSeconds) {
                // Franchi, mais pas encore confirme. L'ancre ne bouge pas :
                // les metres ne sont ni perdus ni comptes, seulement en
                // attente.
                continue;
            }

            $pending = null;

            $speed = $segment / $elapsed;

            /*
             | TROP LENT POUR ETRE UN DEPLACEMENT — et l'ancre RESTE.
             |
             | Ce garde-fou existait deja. Ce qui etait faux, ce n'etait pas
             | son principe mais sa consequence : au rejet, l'ancre AVANCAIT,
             | ce qui jetait pour de bon les metres deja parcourus. Une marche
             | ponctuee d'arrets perdait ainsi la moitie de sa distance.
             |
             | Ici l'ancre ne bouge pas. Les metres ne sont ni comptes ni
             | perdus : ils attendent. Un marcheur arrete a un feu les
             | retrouve des qu'il repart, et une derive, elle, n'atteindra
             | jamais l'allure necessaire — 20 m parcourus en 4 minutes font
             | 0,08 m/s, ce qui n'est le pas de personne.
             |
             | Le seuil est PAR SPORT, et c'est decisif : les 0,8 m/s
             | uniformes d'avant (2,9 km/h) sont deja une allure de marche.
             | Ils effacaient purement et simplement toute promenade tranquille
             | — 72 m reels affiches 0 m.
             */
            if ($speed < $idleSpeed) {
                continue;
            }

            $smoothedSpeed = $smoothedSpeed === null
                ? $speed
                // Lissage exponentiel : reactif aux vraies accelerations,
                // insensible a un point isole.
                : 0.7 * $smoothedSpeed + 0.3 * $speed;

            $distance += $segment;

            /*
             | Le TEMPS ACTIF, lui, exclut toujours les allures d'arret.
             |
             | C'est ici — et non plus sur la distance — qu'intervient la
             | vitesse minimale. Un feu rouge de trois minutes fait bien
             | partie du trajet parcouru, mais pas du temps passe a rouler :
             | l'inclure ferait chuter la vitesse moyenne et rendrait
             | incomprehensible l'allure affichee.
             */
            // Le temps actif ne se calcule PAS ici : voir `movingTime()`,
            // plus bas. L'ancre pouvant rester en place pendant un feu rouge,
            // `elapsed` inclut parfois trois minutes d'arret.
            $maxSpeed = max($maxSpeed, $smoothedSpeed);

            $bucket = (string) (int) floor($speed * 3.6 / 5) * 5;
            $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;

            // Splits kilometriques.
            $splitDistance += $segment;
            $splitTime += $elapsed;

            while ($splitDistance >= 1000.0) {
                // Le segment peut franchir la borne du kilometre : on
                // n'attribue au split que la part qui lui revient.
                $overflow = $splitDistance - 1000.0;
                $ratio = $segment > 0 ? ($segment - $overflow) / $segment : 1.0;
                $timeForSplit = $splitTime - ($elapsed * (1 - $ratio));

                $splits[] = [
                    'km' => $splitIndex,
                    'duration_s' => (int) round($timeForSplit),
                    'pace_s_per_km' => (int) round($timeForSplit),
                    'speed_mps' => $timeForSplit > 0 ? round(1000 / $timeForSplit, 3) : 0.0,
                ];

                $splitIndex++;
                $splitDistance = $overflow;
                $splitTime = $elapsed * (1 - $ratio);
            }

            // Le deplacement est reel : l'ancre suit.
            $anchor = $current;
        }

        /*
         | LES DERNIERS METRES.
         |
         | Le seuil est une regle de PREUVE, pas une regle de comptage : tant
         | qu'on n'a pas franchi la distance qui prouve un deplacement, on
         | attend. Mais a la fin de la trace, il n'y a plus rien a attendre —
         | et sans ce rattrapage, tout ce qui restait sous le seuil est perdu
         | pour de bon.
         |
         | Sur une longue sortie, cela ne represente qu'une poignee de metres.
         | Sur une marche de deux minutes, c'etait la MOITIE du trajet, et sur
         | un aller-retour de 12 m la totalite : l'ecran affichait 0 m a
         | quelqu'un qui venait de marcher.
         |
         | Deux conditions, et elles suffisent :
         |
         | TROIS conditions, et la premiere est la plus importante :
         |
         |  - LA SORTIE A DEJA PROUVE UN DEPLACEMENT (`$distance > 0`). Sans
         |    elle, un telephone pose sur une table crediterait sa derniere
         |    oscillation : la derive d'un recepteur immobile atteint 1,9 m/s
         |    par moments, ce qui passerait n'importe quel test de vitesse.
         |    On ne complete que ce qu'on a deja constate ;
         |  - la distance depasse le plancher du sport (8 m a pied), donc on
         |    ne credite pas un tremblement ;
         |  - l'allure est celle de quelqu'un qui bouge.
         |
         | Consequence assumee : une marche de dix metres et de dix secondes
         | affiche zero. C'est la verite — a huit metres de precision, dix
         | metres ne se prouvent pas. Mieux vaut ne rien annoncer que de
         | promettre une precision qu'aucun telephone n'a.
         */
        $dernier = null;

        for ($i = count($points) - 1; $i >= 0; $i--) {
            if (! $points[$i]->isPaused) {
                $dernier = $points[$i];

                break;
            }
        }

        if ($dernier !== null && $dernier !== $anchor) {
            $reste = Distance::between($anchor, $dernier);
            $duree = $dernier->secondsSince($anchor);

            if ($distance > 0.0
                && $reste >= $minSegment
                && $duree > 0.0
                && $reste / $duree >= $idleSpeed) {
                // La DISTANCE seulement : le temps de ces secondes est deja
                // compte par la fenetre glissante, qui parcourt toute la
                // trace. L'ajouter ici le compterait deux fois — le temps
                // actif depassait alors la duree totale de la sortie.
                $distance += $reste;
            }
        }

        /*
         | Pas de deplacement prouve, pas de temps de deplacement.
         |
         | Meme principe que pour les derniers metres : la fenetre glissante
         | peut compter quelques secondes sur l'oscillation d'un recepteur
         | pose. Annoncer « 0 m parcourus en 9 s de mouvement » n'a aucun sens
         | pour le membre, et l'allure calculee sur ces neuf secondes serait
         | une aberration.
         */
        if ($distance <= 0.0) {
            $movingTime = 0.0;
        }

        return [
            'distance_m' => $distance,
            'moving_time_s' => $movingTime,
            'max_speed_mps' => $maxSpeed,
            'splits' => $splits,
            'histogram' => $histogram,
        ];
    }

    /** Allure moyenne, en secondes par kilomètre. */
    private function pace(int $distanceM, int $movingTimeS): ?int
    {
        if ($distanceM < 100 || $movingTimeS <= 0) {
            return null;
        }

        return (int) round($movingTimeS / ($distanceM / 1000));
    }

    /**
     * Meilleure allure, calculée sur le kilomètre le plus rapide.
     *
     * Et non sur un point isolé : la « vitesse maximale instantanée » est une
     * mesure fragile, alors qu'un kilomètre complet est un effort réel dont on
     * peut être fier.
     *
     * @param  list<array<string, mixed>>  $splits
     */
    private function bestSplitPace(array $splits): ?int
    {
        if ($splits === []) {
            return null;
        }

        return (int) min(array_column($splits, 'pace_s_per_km'));
    }

    /**
     * Calories estimées : MET × poids × durée.
     *
     * Estimation grossière et assumée comme telle. Sans le poids du membre —
     * que le club ne demande pas — on ne renvoie rien plutôt qu'un chiffre
     * calculé sur un poids moyen inventé.
     */
    /**
     * Temps reellement passe en mouvement, en secondes.
     *
     * La question est locale : a chaque instant, le membre a-t-il bouge
     * pendant les trente dernieres secondes ? On compare donc sa position a
     * celle qu'il occupait AU DEBUT d'une fenetre glissante, et non a une
     * ancre lointaine.
     *
     * POURQUOI TRENTE SECONDES. Il faut que la fenetre soit assez longue pour
     * qu'une allure lente y franchisse le seuil de bruit : a 0,6 m/s — une
     * flanerie — il faut une trentaine de secondes pour parcourir les 16 m
     * qui prouvent un deplacement sous 8 m de precision. Plus courte, la
     * fenetre declarerait immobile un promeneur qui avance.
     *
     * CE QUE CELA COUTE. Les bords d'un arret : les quelques secondes qui
     * l'entourent restent comptees comme du mouvement. Sur un feu rouge de
     * trois minutes, l'erreur est de l'ordre de la fenetre — negligeable
     * devant l'erreur inverse, qui comptait l'arret en entier.
     *
     * @param  list<GpsPoint>  $points
     */
    private function movingTime(array $points, float $minSegment): float
    {
        $fenetre = (float) config('cyclo.gps.stop_window_s', 30.0);

        $total = 0.0;
        $debut = 0;
        $n = count($points);

        for ($i = 1; $i < $n; $i++) {
            $current = $points[$i];
            $previous = $points[$i - 1];

            // Une pause declaree n'est jamais du temps actif, et elle est
            // deja comptee comme pause ailleurs.
            if ($current->isPaused || $previous->isPaused) {
                $debut = $i;

                continue;
            }

            $dt = $current->secondsSince($previous);

            if ($dt <= 0.0) {
                continue;
            }

            // Le debut de la fenetre avance jusqu'a couvrir au plus
            // `$fenetre` secondes. Pointeur monotone : le cout reste lineaire.
            while ($debut < $i - 1 && $current->secondsSince($points[$debut]) > $fenetre) {
                $debut++;
            }

            $reference = $points[$debut];
            $ecart = Distance::between($reference, $current);
            $seuil = max($minSegment, $this->uncertainty($reference, $current));

            if ($ecart >= $seuil) {
                $total += $dt;
            }
        }

        return $total;
    }

    /**
     * Incertitude combinee de deux points, en metres.
     *
     * Deux points annonces a plus ou moins 10 m peuvent se trouver a 20 m
     * l'un de l'autre sans que personne n'ait bouge. On prend donc la
     * moyenne des deux precisions : c'est le deplacement minimal qui prouve
     * quelque chose.
     *
     * Un point sans precision annoncee ne releve pas le seuil : on ne
     * penalise pas un appareil discret.
     */
    private function uncertainty(GpsPoint $a, GpsPoint $b): float
    {
        $accuracies = array_filter(
            [$a->accuracyM, $b->accuracyM],
            static fn (?float $value): bool => $value !== null && $value > 0,
        );

        if ($accuracies === []) {
            return 0.0;
        }

        /*
         | Facteur 1,5, et non 1.
         |
         | Deux points annonces chacun a plus ou moins 8 m peuvent se trouver
         | a 16 m l'un de l'autre sans que personne n'ait bouge. Prendre la
         | simple moyenne laissait passer une derive de 10 m sous une
         | precision de 8 m : le telephone pose accumulait encore 25 m en
         | cinq minutes.
         */
        $facteur = (float) config('cyclo.gps.accuracy_factor', 2.0);

        return (array_sum($accuracies) / count($accuracies)) * $facteur;
    }

    private function calories(Sport $sport, int $movingTimeS, ?float $weightKg): ?int
    {
        if ($weightKg === null || $movingTimeS <= 0) {
            return null;
        }

        return (int) round($sport->met() * $weightKg * ($movingTimeS / 3600));
    }

    /**
     * Profil d'altitude réduit à ~200 points.
     *
     * Un graphique n'a pas besoin de 10 000 valeurs : l'écran fait 400 pixels
     * de large, et transmettre le reste ne ferait que ralentir l'affichage.
     *
     * @param  list<GpsPoint>  $points
     * @return list<array{d: int, a: int}>
     */
    private function elevationProfile(array $points): array
    {
        $target = 200;
        $count = count($points);
        $step = max(1, (int) ceil($count / $target));

        $profile = [];
        $distance = 0.0;

        for ($i = 1; $i < $count; $i++) {
            $distance += Distance::between($points[$i - 1], $points[$i]);

            if ($i % $step === 0 && $points[$i]->altitudeM !== null) {
                $profile[] = [
                    'd' => (int) round($distance),
                    'a' => (int) round($points[$i]->altitudeM),
                ];
            }
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(?GpsPoint $single): array
    {
        return [
            'distance_m' => 0,
            'duration_s' => 0,
            'moving_time_s' => 0,
            'paused_time_s' => 0,
            'avg_speed_mps' => 0.0,
            'max_speed_mps' => 0.0,
            'elevation_gain_m' => 0,
            'elevation_loss_m' => 0,
            'min_altitude_m' => null,
            'max_altitude_m' => null,
            'avg_pace_s_per_km' => null,
            'best_pace_s_per_km' => null,
            'calories_kcal' => null,
            'polyline' => null,
            'bounds' => null,
            'start_lat' => $single?->lat,
            'start_lng' => $single?->lng,
            'end_lat' => $single?->lat,
            'end_lng' => $single?->lng,
            'points_count' => $single === null ? 0 : 1,
            'splits' => [],
            'elevation_profile' => [],
            'speed_histogram' => [],
        ];
    }
}
