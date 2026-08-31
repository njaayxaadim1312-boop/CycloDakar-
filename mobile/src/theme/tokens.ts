/**
 * ============================================================================
 * CYCLO DAKAR — Jetons de design (mobile)
 * ============================================================================
 *
 * Miroir exact de `web/src/styles/tokens.css`.
 * Source : assets/brand/prototype-design-system.jpg — voir docs/design-system.md
 *
 * Règle du projet : AUCUNE couleur en dur dans un composant. Une nouvelle
 * teinte s'ajoute ici d'abord, dans les deux thèmes.
 * ============================================================================
 */

/** Palette de marque — identique en clair et en sombre. */
export const brand = {
  orange: '#FF8C00',
  black: '#1A1A1A',
  blue: '#004080',
  green: '#32CD32',
} as const

const light = {
  ...brand,

  /**
   * Seule teinte orange utilisable pour du TEXTE : #FF8C00 sur blanc ne donne
   * que 2,3:1, illisible en plein soleil. #C46A00 atteint 4,6:1.
   */
  orangeText: '#C46A00',
  orangeHover: '#E67E00',
  orangeSoft: '#FFF4E6',
  blueHover: '#003366',
  blueSoft: '#E6EEF5',
  greenHover: '#28A428',
  greenSoft: '#EAFAEA',

  bg: '#F7F7F8',
  surface: '#FFFFFF',
  surface2: '#F2F2F4',
  border: '#E4E4E7',
  borderStrong: '#D1D1D6',

  text: '#1A1A1A',
  textMuted: '#6B7280',
  textInverse: '#FFFFFF',

  danger: '#DC2626',
  dangerSoft: '#FEE2E2',
  warning: '#D97706',
  warningSoft: '#FEF3C7',
  success: '#32CD32',
  successSoft: '#EAFAEA',
  disabledBg: '#D4D4D8',
  disabledText: '#8A8A8F',

  sportCycling: '#FF8C00',
  sportRunning: '#004080',
  sportHiking: '#32CD32',
} as const

/**
 * Les valeurs de `light` sont figées par `as const`, ce qui donnerait des types
 * littéraux ('#FFFFFF' plutôt que string). On élargit ici : ce sont les CLÉS
 * qui doivent être identiques d'un thème à l'autre, pas les valeurs.
 */
export type Palette = { [K in keyof typeof light]: string }

const dark: Palette = {
  ...brand,

  orangeText: '#FFA733',
  orangeHover: '#FFB259',
  orangeSoft: '#33240A',
  blueHover: '#1A5490',
  blueSoft: '#0D1F33',
  greenHover: '#5FE05F',
  greenSoft: '#10240F',

  bg: '#111113',
  surface: '#1C1C1F',
  surface2: '#26262A',
  border: '#2E2E33',
  borderStrong: '#3F3F46',

  text: '#F4F4F5',
  textMuted: '#9CA3AF',
  textInverse: '#1A1A1A',

  danger: '#F87171',
  dangerSoft: '#3B1414',
  warning: '#FBBF24',
  warningSoft: '#35260A',
  success: '#32CD32',
  successSoft: '#10240F',
  disabledBg: '#3F3F46',
  disabledText: '#71717A',

  sportCycling: '#FF8C00',
  sportRunning: '#004080',
  sportHiking: '#32CD32',
}

export const palettes = { light, dark } as const

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const

export const radius = {
  sm: 8,
  md: 12,
  lg: 20,
  pill: 999,
} as const

export const fontSize = {
  caption: 12,
  small: 13,
  body: 15,
  h3: 20,
  h2: 24,
  h1: 30,
  /** Chiffres de l'écran d'enregistrement : lisibles d'un coup d'œil au guidon. */
  display: 44,
} as const

/**
 * Cibles tactiles.
 *
 * `touchMin` respecte le minimum de 44 pt d'Apple / 48 dp d'Android.
 * `touchField` est réservé aux boutons utilisés EN ROULANT (démarrer, pause,
 * arrêter) : plus gros que la norme, parce qu'on les vise avec des gants,
 * en mouvement, à bout de bras.
 */
export const touch = {
  min: 48,
  field: 72,
} as const
