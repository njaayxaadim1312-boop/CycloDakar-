import { Bike, Footprints, Mountain, PersonStanding, type LucideIcon } from 'lucide-react'
import type { SportCode } from '@/types/api'

/**
 * Icône, couleur et libellé de chaque sport, en un seul endroit.
 *
 * Ces trois tables étaient recopiées dans quatre écrans. L'ajout de la marche
 * les a toutes fait échouer à la compilation le même jour — ce qui est
 * précisément le signe qu'elles n'auraient jamais dû être quatre. Un sport de
 * plus ne doit se déclarer qu'ici.
 *
 * `Record<SportCode, …>` et non un objet libre : TypeScript refuse alors de
 * compiler tant qu'un sport manque, plutôt que d'afficher un trou à
 * l'exécution.
 */

export const SPORT_ICON: Record<SportCode, LucideIcon> = {
  CYCLING: Bike,
  RUNNING: Footprints,
  HIKING: Mountain,
  // Une silhouette debout pour la marche : les empreintes servent déjà à la
  // course, et deux icônes identiques rendraient la liste illisible.
  WALKING: PersonStanding,
}

export const SPORT_COLOR: Record<SportCode, string> = {
  CYCLING: 'var(--cd-sport-cycling)',
  RUNNING: 'var(--cd-sport-running)',
  HIKING: 'var(--cd-sport-hiking)',
  WALKING: 'var(--cd-sport-walking)',
}

export const SPORT_LABEL: Record<SportCode, string> = {
  CYCLING: 'Cyclisme',
  RUNNING: 'Course',
  HIKING: 'Randonnée',
  WALKING: 'Marche',
}

/** Ordre d'affichage : du plus pratiqué au moins pratiqué au club. */
export const SPORTS: SportCode[] = ['CYCLING', 'RUNNING', 'WALKING', 'HIKING']

/** Options d'un filtre, « tous les sports » compris. */
export const SPORT_FILTERS: { value: SportCode | ''; label: string }[] = [
  { value: '', label: 'Tous les sports' },
  ...SPORTS.map((code) => ({ value: code, label: SPORT_LABEL[code] })),
]

/**
 * Teinte de fond d'une pastille de sport.
 *
 * `color-mix` plutôt qu'une seconde table de couleurs pâles : une variante
 * calculée ne peut pas diverger de sa couleur d'origine.
 */
export function sportTint(sport: SportCode, percent = 15): string {
  return `color-mix(in srgb, ${SPORT_COLOR[sport]} ${percent}%, transparent)`
}
