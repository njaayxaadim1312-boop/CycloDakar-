import type { SportCode } from '../types/api'

/**
 * Emoji, couleur et libellé de chaque sport, en un seul endroit.
 *
 * Miroir de `web/src/lib/sports.ts`. Ces tables étaient recopiées dans quatre
 * écrans ; l'ajout de la marche les a toutes fait échouer à la compilation le
 * même jour, ce qui est précisément le signe qu'elles n'auraient jamais dû
 * être quatre.
 *
 * `Record<SportCode, …>` et non un objet libre : TypeScript refuse alors de
 * compiler tant qu'un sport manque, plutôt que d'afficher un trou à
 * l'exécution.
 */

export const SPORT_EMOJI: Record<SportCode, string> = {
  CYCLING: '🚴',
  RUNNING: '🏃',
  HIKING: '🥾',
  WALKING: '🚶',
}

export const SPORT_LABEL: Record<SportCode, string> = {
  CYCLING: 'Cyclisme',
  RUNNING: 'Course',
  HIKING: 'Randonnée',
  WALKING: 'Marche',
}

/**
 * Couleur d'identification.
 *
 * Les valeurs sont littérales et non des jetons : React Native ne résout pas
 * les variables CSS. Elles doivent rester alignées sur `theme/tokens.ts`.
 */
export const SPORT_COLOR: Record<SportCode, string> = {
  CYCLING: '#FF8C00',
  RUNNING: '#004080',
  HIKING: '#32CD32',
  WALKING: '#3D7AB8',
}

/** Ordre d'affichage : du plus pratiqué au moins pratiqué au club. */
export const SPORTS: SportCode[] = ['CYCLING', 'RUNNING', 'WALKING', 'HIKING']
