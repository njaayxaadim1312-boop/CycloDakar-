import type { Coordinate } from '@/types/api'

/**
 * Décodage du format Google Encoded Polyline.
 *
 * Miroir de `backend/app/Services/Gps/PolylineEncoder.php`. Le serveur envoie
 * la trace simplifiée sous cette forme : ~1 Ko au lieu de ~500 Ko de JSON.
 * Sans cela, afficher 20 miniatures dans une liste d'activités demanderait
 * 10 Mo — impensable sur un réseau mobile.
 *
 * L'algorithme encode des DIFFÉRENCES entre points successifs : deux points
 * voisins ne diffèrent que de quelques unités, ce qui tient sur un ou deux
 * caractères.
 */

/** Précision de l'encodage, identique côté serveur (`cyclo.gps.polyline_precision`). */
const PRECISION = 5

export function decodePolyline(encoded: string | null): Coordinate[] {
  if (!encoded) return []

  const factor = 10 ** PRECISION
  const points: Coordinate[] = []

  let index = 0
  let lat = 0
  let lng = 0

  while (index < encoded.length) {
    let result = 0
    let shift = 0
    let byte: number

    // Latitude.
    do {
      byte = encoded.charCodeAt(index++) - 63
      result |= (byte & 0x1f) << shift
      shift += 5
    } while (byte >= 0x20)

    lat += result & 1 ? ~(result >> 1) : result >> 1

    // Longitude.
    result = 0
    shift = 0

    do {
      byte = encoded.charCodeAt(index++) - 63
      result |= (byte & 0x1f) << shift
      shift += 5
    } while (byte >= 0x20)

    lng += result & 1 ? ~(result >> 1) : result >> 1

    points.push({ lat: lat / factor, lng: lng / factor })
  }

  return points
}

/**
 * Réduit une trace à N points au plus.
 *
 * Pour une miniature de 200 pixels, 40 points suffisent : au-delà, on dessine
 * plusieurs segments par pixel. La trace complète reste disponible pour la
 * carte en grand.
 */
export function decimate(points: Coordinate[], maxPoints: number): Coordinate[] {
  if (points.length <= maxPoints) return points

  const step = Math.ceil(points.length / maxPoints)
  const reduced = points.filter((_, index) => index % step === 0)

  // Le dernier point est conservé quoi qu'il arrive : sinon la trace
  // paraîtrait s'arrêter avant l'arrivée.
  const last = points[points.length - 1]!
  if (reduced[reduced.length - 1] !== last) {
    reduced.push(last)
  }

  return reduced
}
