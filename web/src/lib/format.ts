import type { Fcfa } from '@/types/api'

/**
 * Formatage d'affichage.
 *
 * Règle : la base et l'API travaillent en unités SI (mètres, secondes, m/s) et
 * en entiers de FCFA. Toute conversion vers une unité lisible se fait ICI et
 * nulle part ailleurs. C'est ce qui évite les bugs de type « km stocké,
 * mètres affiché ».
 */

const NBSP = ' ' // espace fine insécable

/**
 * Montant en francs CFA : « 5 000 FCFA ». Jamais de décimales.
 */
export function formatFcfa(amount: Fcfa, options: { compact?: boolean } = {}): string {
  const value = Math.round(amount)

  if (options.compact && Math.abs(value) >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(1).replace('.', ',')}${NBSP}M${NBSP}FCFA`
  }

  return `${formatInteger(value)}${NBSP}FCFA`
}

/** Entier avec séparateur de milliers : 1 250 000 */
export function formatInteger(value: number): string {
  return Math.round(value)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, NBSP)
}

/**
 * Distance : mètres → « 46,8 km » ou « 850 m ».
 * Sous 1 km on reste en mètres, sinon « 0,8 km » est moins parlant.
 */
export function formatDistance(meters: number): string {
  if (meters < 1000) {
    return `${Math.round(meters)}${NBSP}m`
  }
  const km = meters / 1000
  const decimals = km >= 100 ? 0 : 1
  return `${km.toFixed(decimals).replace('.', ',')}${NBSP}km`
}

/**
 * Durée : secondes → « 02:16:24 » ou « 16:24 ».
 * Format monospacé pour ne pas sauter pendant un enregistrement.
 */
export function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => n.toString().padStart(2, '0')

  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

/** Durée en langage naturel : « 2 h 16 min ». */
export function formatDurationLong(seconds: number): string {
  const total = Math.max(0, Math.round(seconds))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)

  if (h === 0) return `${m}${NBSP}min`
  if (m === 0) return `${h}${NBSP}h`
  return `${h}${NBSP}h${NBSP}${m.toString().padStart(2, '0')}`
}

/** Vitesse : m/s → « 20,6 km/h » (cyclisme). */
export function formatSpeed(metersPerSecond: number): string {
  const kmh = metersPerSecond * 3.6
  return `${kmh.toFixed(1).replace('.', ',')}${NBSP}km/h`
}

/**
 * Allure : secondes par kilomètre → « 5:42 /km » (course, randonnée).
 * Une allure nulle ou infinie (à l'arrêt) s'affiche « — ».
 */
export function formatPace(secondsPerKm: number): string {
  if (!Number.isFinite(secondsPerKm) || secondsPerKm <= 0) return '—'

  const m = Math.floor(secondsPerKm / 60)
  const s = Math.round(secondsPerKm % 60)
  // 5:60 n'existe pas : on reporte sur la minute.
  const [mm, ss] = s === 60 ? [m + 1, 0] : [m, s]

  return `${mm}:${ss.toString().padStart(2, '0')}${NBSP}/km`
}

/** Dénivelé : « +245 m » / « −80 m ». */
export function formatElevation(meters: number): string {
  const rounded = Math.round(meters)
  const sign = rounded > 0 ? '+' : rounded < 0 ? '−' : ''
  return `${sign}${Math.abs(rounded)}${NBSP}m`
}

/** Pourcentage entier borné à [0, 100] — barres de progression de challenge. */
export function formatPercent(value: number, total: number): string {
  if (total <= 0) return '0 %'
  const pct = Math.min(100, Math.max(0, Math.round((value / total) * 100)))
  return `${pct}${NBSP}%`
}

/**
 * Date/heure dans le fuseau du club, quelle que soit la position du visiteur.
 * L'API renvoie de l'UTC ; le club raisonne en heure de Dakar.
 */
export function formatDateTime(
  iso: string,
  timeZone = 'Africa/Dakar',
  options: Intl.DateTimeFormatOptions = {},
): string {
  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone,
    ...options,
  }).format(new Date(iso))
}

export function formatDate(iso: string, timeZone = 'Africa/Dakar'): string {
  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'long',
    timeZone,
  }).format(new Date(iso))
}

export function formatTime(iso: string, timeZone = 'Africa/Dakar'): string {
  return new Intl.DateTimeFormat('fr-FR', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone,
  }).format(new Date(iso))
}
