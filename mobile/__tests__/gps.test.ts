import {
  DEFAULT_THRESHOLDS,
  elevationGain,
  filterPoint,
  haversine,
  isMoving,
  smoothSpeed,
  type Candidate,
  type Reference,
} from '../src/lib/gps'

/**
 * Filtre GPS côté téléphone.
 *
 * Ces tests reprennent volontairement les mêmes cas que ceux du serveur
 * (`backend/tests/Unit/Gps/GpsFilterTest.php`). Les deux implémentations
 * doivent donner le même verdict sur la même trace : sinon le membre verrait
 * une distance pendant sa sortie, et une autre après synchronisation — la
 * meilleure façon de lui faire perdre confiance.
 */
describe('filtre GPS', () => {
  const thresholds = DEFAULT_THRESHOLDS.CYCLING

  const at = (
    lat: number,
    lng: number,
    timestampMs: number,
    overrides: Partial<Candidate> = {},
  ): Candidate => ({
    lat,
    lng,
    timestampMs,
    altitudeM: 12,
    speedMps: null,
    accuracyM: 5,
    headingDeg: null,
    ...overrides,
  })

  const from = (
    lat: number,
    lng: number,
    timestampMs: number,
    lastSpeedMps: number | null = null,
  ): Reference => ({ lat, lng, timestampMs, altitudeM: 12, lastSpeedMps })

  it('accepte le premier point sans référence', () => {
    const outcome = filterPoint(at(14.6928, -17.4467, 1000), null, thresholds)

    expect(outcome.accepted).toBe(true)
  })

  it('rejette les coordonnées aberrantes', () => {
    // « Null Island » : ce que renvoient certains appareils sans position.
    expect(filterPoint(at(0, 0, 1000), null, thresholds)).toEqual({
      accepted: false,
      reason: 'invalid_coordinates',
    })

    expect(filterPoint(at(91, -17.4467, 1000), null, thresholds)).toEqual({
      accepted: false,
      reason: 'invalid_coordinates',
    })
  })

  it('rejette un point trop imprécis', () => {
    const outcome = filterPoint(
      at(14.6928, -17.4467, 1000, { accuracyM: 80 }),
      null,
      thresholds,
    )

    expect(outcome).toEqual({ accepted: false, reason: 'poor_accuracy' })
  })

  it('applique le seuil de précision propre au sport', () => {
    // 22 m passe en cyclisme (seuil 25) mais pas en course (seuil 20).
    const point = at(14.6928, -17.4467, 1000, { accuracyM: 22 })

    expect(filterPoint(point, null, DEFAULT_THRESHOLDS.CYCLING).accepted).toBe(true)
    expect(filterPoint(point, null, DEFAULT_THRESHOLDS.RUNNING).accepted).toBe(false)
  })

  it('rejette un point qui remonte le temps', () => {
    // Fausserait toutes les vitesses calculées ensuite.
    const outcome = filterPoint(
      at(14.693, -17.4467, 5000),
      from(14.6928, -17.4467, 10_000),
      thresholds,
    )

    expect(outcome).toEqual({ accepted: false, reason: 'out_of_order' })
  })

  it('rejette un saut de multipath', () => {
    // Le cas des Almadies : 150 m en une seconde, soit 150 m/s. Sans ce
    // filtre, la sortie gagnerait 300 m fantômes (aller-retour).
    const outcome = filterPoint(
      at(14.6928 + 150 / 111_320, -17.4467, 2000),
      from(14.6928, -17.4467, 1000),
      thresholds,
    )

    expect(outcome).toEqual({ accepted: false, reason: 'impossible_speed' })
  })

  it('rejette une accélération humainement impossible', () => {
    // 20 m en 1 s après un segment à 1 m/s : le saut est trop court pour
    // déclencher le test de vitesse, mais l'accélération le trahit.
    const outcome = filterPoint(
      at(14.6928 + 20 / 111_320, -17.4467, 2000),
      from(14.6928, -17.4467, 1000, 1.0),
      thresholds,
    )

    expect(outcome).toEqual({ accepted: false, reason: 'impossible_acceleration' })
  })

  it('rejette un point immobile et quasi simultané', () => {
    // À l'arrêt, le GPS produit des points qui n'apportent rien et
    // alourdissent la trace.
    const outcome = filterPoint(
      at(14.6928, -17.4467, 1500),
      from(14.6928, -17.4467, 1000),
      thresholds,
    )

    expect(outcome).toEqual({ accepted: false, reason: 'duplicate' })
  })

  it('accepte un déplacement plausible et en donne la mesure', () => {
    // 6 m en 1 s = 6 m/s, soit 21,6 km/h.
    const outcome = filterPoint(
      at(14.6928 + 6 / 111_320, -17.4467, 2000),
      from(14.6928, -17.4467, 1000),
      thresholds,
    )

    expect(outcome.accepted).toBe(true)

    if (outcome.accepted) {
      expect(outcome.distanceM).toBeCloseTo(6, 0)
      expect(outcome.speedMps).toBeCloseTo(6, 0)
    }
  })
})

describe('mesures', () => {
  it('calcule une distance conforme sur un degré de latitude', () => {
    // Un degré de latitude vaut ~111 320 m, partout sur le globe.
    expect(haversine(14.0, -17.4467, 15.0, -17.4467)).toBeCloseTo(111_320, -3)
  })

  it('renvoie zéro pour deux points identiques', () => {
    expect(haversine(14.6928, -17.4467, 14.6928, -17.4467)).toBe(0)
  })

  it('distingue le mouvement de l’arrêt', () => {
    const thresholds = DEFAULT_THRESHOLDS.CYCLING

    // Un feu rouge ne doit pas compter dans le temps actif.
    expect(isMoving(0.3, thresholds)).toBe(false)
    expect(isMoving(6.0, thresholds)).toBe(true)
  })

  it('ne compte pas le bruit d’altitude comme du dénivelé', () => {
    const thresholds = DEFAULT_THRESHOLDS.CYCLING

    // ±4 m : c'est du bruit GPS, pas une montée.
    expect(elevationGain(100, 104, thresholds)).toBe(0)
    // +15 m : c'est une vraie montée.
    expect(elevationGain(100, 115, thresholds)).toBe(15)
    // Une descente n'ajoute rien au dénivelé positif.
    expect(elevationGain(100, 80, thresholds)).toBe(0)
  })

  it('ignore une altitude absente', () => {
    // Beaucoup d'appareils d'entrée de gamme n'en fournissent pas.
    expect(elevationGain(null, 120, DEFAULT_THRESHOLDS.CYCLING)).toBe(0)
    expect(elevationGain(100, null, DEFAULT_THRESHOLDS.CYCLING)).toBe(0)
  })

  it('lisse la vitesse affichée', () => {
    // Le chiffre brut saute d'une seconde à l'autre et devient illisible au
    // guidon : le lissage suit les vraies accélérations sans trembler.
    expect(smoothSpeed(null, 6)).toBe(6)
    // `toBeCloseTo` et non `toBe` : 6 x 0,7 + 6 x 0,3 vaut 5,999999999999999
    // en virgule flottante. Exiger l'egalite stricte testerait IEEE 754, pas
    // notre lissage.
    expect(smoothSpeed(6, 6)).toBeCloseTo(6, 10)

    const jolted = smoothSpeed(6, 12)
    expect(jolted).toBeGreaterThan(6)
    expect(jolted).toBeLessThan(9)
  })
})
