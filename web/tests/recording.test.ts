import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  DEFAULT_THRESHOLDS,
  filterPoint,
  haversine,
  isMoving,
  type Candidate,
  type Reference,
} from '@/lib/gps'
import type { SportCode } from '@/types/api'

/**
 * L'algorithme GPS **du navigateur**, sur les traces réelles du club.
 *
 * POURQUOI CE FICHIER EXISTE.
 *
 * Le calcul affiché en direct pendant une sortie tourne ICI, dans le
 * navigateur — pas sur le serveur. C'est ce code-là que le membre regarde en
 * marchant, et c'est lui qui a produit les signalements « la vitesse exagère »
 * puis « les mètres ne sont pas pris ». Il n'avait aucun test automatisé.
 *
 * Le serveur recalcule tout à la finalisation et fait foi. Mais entre le départ
 * et l'arrivée, un compteur qui ment est la seule chose que le membre voit —
 * et il jugera l'application là-dessus.
 *
 * LES FIXTURES SONT CELLES DU BACKEND, PAS UNE COPIE.
 *
 * Elles sont lues à leur emplacement d'origine. Les dupliquer ici aurait créé
 * une seconde vérité qui aurait divergé au premier ré-export — exactement le
 * problème que les fichiers miroirs du projet (`lib/gps.ts`, `lib/format.ts`)
 * demandent déjà de surveiller à la main. On n'en ajoute pas un troisième.
 */

interface PointFixture {
  seq: number
  lat: string
  lng: string
  altitude_m: number | null
  speed_mps: number | null
  accuracy_m: number | null
  recorded_at: string
}

function trace(nom: string): PointFixture[] {
  const chemin = fileURLToPath(
    new URL(`../../backend/tests/Fixtures/traces/${nom}.json`, import.meta.url),
  )

  return (JSON.parse(readFileSync(chemin, 'utf8')) as { points: PointFixture[] }).points
}

/**
 * Rejoue une trace comme le ferait `RecordingSession`.
 *
 * On réimplémente ici la boucle d'accumulation plutôt que d'instancier la
 * session : celle-ci parle au réseau, au stockage local et à `crypto` dès sa
 * construction. Le but est d'éprouver la RÈGLE — l'ancre, le seuil, la
 * confirmation — pas la plomberie qui l'entoure.
 *
 * Toute divergence entre cette boucle et la vraie serait donc invisible : c'est
 * la limite assumée de ce test, et la raison pour laquelle les mêmes fixtures
 * passent aussi par le serveur, où la boucle réelle est testée.
 */
function mesurer(points: PointFixture[], sport: SportCode) {
  const seuils = DEFAULT_THRESHOLDS[sport]

  let distance = 0
  let rejetes = 0
  let ancre: (Reference & { accuracyM: number | null }) | null = null
  let attenteDepuis: number | null = null

  const CONFIRMATION_MS = 2_000
  const FACTEUR_PRECISION = 2.0

  const incertitude = (a: number | null, b: number | null): number => {
    const valeurs = [a, b].filter((v): v is number => v !== null)

    if (valeurs.length === 0) return 0

    return (valeurs.reduce((s, v) => s + v, 0) / valeurs.length) * FACTEUR_PRECISION
  }

  for (const brut of points) {
    const candidat: Candidate = {
      lat: Number(brut.lat),
      lng: Number(brut.lng),
      timestampMs: Date.parse(brut.recorded_at.replace(' ', 'T') + 'Z'),
      altitudeM: brut.altitude_m,
      accuracyM: brut.accuracy_m,
      speedMps: brut.speed_mps,
      headingDeg: null,
    }

    const reference: Reference | null =
      ancre === null
        ? null
        : {
            lat: ancre.lat,
            lng: ancre.lng,
            timestampMs: ancre.timestampMs,
            altitudeM: ancre.altitudeM,
            lastSpeedMps: ancre.lastSpeedMps,
          }

    const issue = filterPoint(candidat, reference, seuils)

    if (!issue.accepted) {
      rejetes++
      continue
    }

    if (ancre === null) {
      ancre = { ...candidat, lastSpeedMps: 0, accuracyM: candidat.accuracyM }
      continue
    }

    const seuil = Math.max(
      seuils.minSegmentM,
      incertitude(ancre.accuracyM, candidat.accuracyM),
    )

    if (issue.distanceM < seuil) {
      // Sous le seuil : bruit. L'ancre RESTE, l'attente est annulée.
      attenteDepuis = null
      continue
    }

    attenteDepuis ??= candidat.timestampMs

    const confirme = candidat.timestampMs - attenteDepuis >= CONFIRMATION_MS

    if (!confirme || !isMoving(issue.speedMps, seuils)) {
      // L'ancre reste : les mètres attendent au lieu d'être perdus.
      continue
    }

    attenteDepuis = null
    distance += issue.distanceM
    ancre = { ...candidat, lastSpeedMps: issue.speedMps, accuracyM: candidat.accuracyM }
  }

  return { distance, rejetes }
}

/* -------------------------------------------------------------------------- */

describe('haversine', () => {
  it('mesure une distance connue au mètre près', () => {
    // Un degré de latitude vaut environ 111 320 m. C'est la constante sur
    // laquelle repose tout le module : si elle dérive, toutes les distances
    // du club dérivent avec elle.
    const metres = haversine(14.6928, -17.4467, 14.6928 + 1 / 111_320, -17.4467)

    expect(metres).toBeGreaterThan(0.98)
    expect(metres).toBeLessThan(1.02)
  })

  it('renvoie zéro pour deux points identiques', () => {
    // Un `NaN` ici — l'arccosinus déborde facilement — se propagerait dans
    // toute la distance et afficherait « NaN km » au membre.
    expect(haversine(14.6928, -17.4467, 14.6928, -17.4467)).toBe(0)
  })
})

describe('isMoving', () => {
  it('accepte une flânerie à pied et la refuse à vélo', () => {
    // Le cœur du défaut « les mètres ne sont pas pris » : un seuil unique de
    // 0,8 m/s (2,9 km/h) est DÉJÀ une allure de marche, et effaçait toute
    // promenade tranquille.
    expect(isMoving(0.6, DEFAULT_THRESHOLDS.WALKING)).toBe(true)
    expect(isMoving(0.6, DEFAULT_THRESHOLDS.CYCLING)).toBe(false)
  })

  it('refuse une dérive lente quel que soit le sport', () => {
    // 10 m en 60 s font 0,17 m/s : ce n'est le pas de personne.
    for (const sport of Object.values(DEFAULT_THRESHOLDS)) {
      expect(isMoving(0.17, sport)).toBe(false)
    }
  })
})

describe('mesure sur traces réelles', () => {
  it('mesure la sortie vélo réelle à son ordre de grandeur', () => {
    // La même trace que côté serveur, mesurée par le code du navigateur. Les
    // deux doivent tomber d'accord : le membre voit l'un pendant sa sortie et
    // l'autre à l'arrivée, et un écart visible ferait douter des deux.
    const { distance } = mesurer(trace('velo-dakar-7km'), 'CYCLING')

    expect(distance).toBeGreaterThan(6_000)
    expect(distance).toBeLessThan(8_000)
  })

  it('ne compte pas un aller-retour de quelques mètres', () => {
    /*
     * Les essais qui ont produit le signalement. Excursion maximale de 7 à
     * 13 m pour une précision de 4 à 8 m : la personne a fait quelques pas et
     * est revenue, dans un rayon à peine plus grand que l'incertitude de son
     * propre récepteur.
     *
     * Zéro est la bonne réponse. Compter les 35 m de « chemin » reviendrait à
     * compter le tremblement — le défaut d'origine, qui affichait 209 m à
     * l'arrêt complet.
     */
    for (const fixture of ['marche-aller-retour-13m', 'marche-sur-place-7m']) {
      const { distance } = mesurer(trace(fixture), 'WALKING')

      expect(distance, `${fixture} compte du bruit comme du déplacement`).toBe(0)
    }
  })

  it('retient les points de ces essais malgré tout', () => {
    // Distinction qui compte : les points sont RETENUS, c'est la distance qui
    // vaut zéro. S'ils étaient rejetés, la carte serait vide et l'on
    // conclurait à une panne d'enregistrement.
    const points = trace('marche-sur-place-7m')
    const { rejetes } = mesurer(points, 'WALKING')

    expect(rejetes).toBeLessThan(points.length * 0.2)
  })
})
