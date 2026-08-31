<?php

declare(strict_types=1);

namespace App\Services\Gps;

/**
 * Dénivelé positif et négatif.
 *
 * Le problème, et il est sérieux : l'altitude GPS a une erreur de ±10 à 15 m.
 * Sommer naïvement les différences entre 5 000 points fabrique plusieurs
 * centaines de mètres de dénivelé **sur un parcours parfaitement plat**.
 *
 * Dakar est quasi plat. Une sortie sur la Corniche affichant « +2 000 m »
 * décrédibiliserait l'application en une seule utilisation.
 *
 * Deux protections, dans cet ordre :
 *
 *  1. **lissage** par moyenne mobile centrée sur 5 points — élimine le bruit
 *     ponctuel sans déplacer les vraies montées ;
 *  2. **hystérésis** — un changement de direction n'est acté que si l'écart
 *     cumulé dépasse 3 m. En dessous, on considère qu'on est toujours sur la
 *     même pente et que la variation est du bruit.
 *
 * Sans le second point, le lissage seul laisserait passer une oscillation
 * lente de ±2 m qui, répétée mille fois, ferait encore +2 000 m.
 */
final class ElevationCalculator
{
    /**
     * @param  list<GpsPoint>  $points
     * @return array{gain: int, loss: int, min: int|null, max: int|null}
     */
    public function calculate(array $points): array
    {
        $altitudes = [];

        foreach ($points as $point) {
            if ($point->altitudeM !== null) {
                $altitudes[] = $point->altitudeM;
            }
        }

        // Certains appareils d'entrée de gamme ne fournissent pas d'altitude
        // du tout. Mieux vaut zéro qu'un chiffre inventé.
        if (count($altitudes) < 3) {
            return ['gain' => 0, 'loss' => 0, 'min' => null, 'max' => null];
        }

        $smoothed = $this->smooth($altitudes);

        return [
            ...$this->accumulate($smoothed),
            'min' => (int) round(min($smoothed)),
            'max' => (int) round(max($smoothed)),
        ];
    }

    /**
     * Moyenne mobile centrée.
     *
     * La fenêtre est rétrécie aux extrémités plutôt que de tronquer la série :
     * couper les premiers et derniers points perdrait le départ et l'arrivée,
     * qui sont justement ce que l'utilisateur regarde en premier.
     *
     * @param  list<float>  $altitudes
     * @return list<float>
     */
    private function smooth(array $altitudes): array
    {
        $window = (int) config('cyclo.gps.elevation_smoothing_window', 5);
        $half = intdiv($window, 2);
        $count = count($altitudes);
        $smoothed = [];

        for ($i = 0; $i < $count; $i++) {
            $from = max(0, $i - $half);
            $to = min($count - 1, $i + $half);

            $slice = array_slice($altitudes, $from, $to - $from + 1);
            $smoothed[] = array_sum($slice) / count($slice);
        }

        return $smoothed;
    }

    /**
     * Accumulation avec hystérésis.
     *
     * On suit une direction (montée ou descente) et on ne l'inverse que
     * lorsque l'écart accumulé dans l'autre sens dépasse le seuil. Le dénivelé
     * n'est comptabilisé qu'au moment où un segment est confirmé.
     *
     * @param  list<float>  $altitudes
     * @return array{gain: int, loss: int}
     */
    private function accumulate(array $altitudes): array
    {
        $threshold = (float) config('cyclo.gps.elevation_threshold_m', 3.0);

        $gain = 0.0;
        $loss = 0.0;

        // Altitude de référence du segment en cours.
        $anchor = $altitudes[0];
        // +1 montée, -1 descente, 0 indéterminé (début de trace).
        $direction = 0;

        foreach ($altitudes as $altitude) {
            $delta = $altitude - $anchor;

            if ($direction === 0) {
                // On attend un écart franc avant de décider d'une direction.
                if (abs($delta) >= $threshold) {
                    $direction = $delta > 0 ? 1 : -1;
                    $delta > 0 ? $gain += $delta : $loss += abs($delta);
                    $anchor = $altitude;
                }

                continue;
            }

            if ($direction === 1) {
                if ($delta > 0) {
                    // On continue de monter : on comptabilise et on avance
                    // l'ancre, ce qui évite de compter deux fois le même mètre.
                    $gain += $delta;
                    $anchor = $altitude;
                } elseif (abs($delta) >= $threshold) {
                    // Redescente confirmée : on bascule.
                    $direction = -1;
                    $loss += abs($delta);
                    $anchor = $altitude;
                }
                // Sinon : redescente sous le seuil, c'est du bruit — on ignore
                // sans déplacer l'ancre, pour ne pas rogner la montée.

                continue;
            }

            if ($delta < 0) {
                $loss += abs($delta);
                $anchor = $altitude;
            } elseif ($delta >= $threshold) {
                $direction = 1;
                $gain += $delta;
                $anchor = $altitude;
            }
        }

        return ['gain' => (int) round($gain), 'loss' => (int) round($loss)];
    }
}
