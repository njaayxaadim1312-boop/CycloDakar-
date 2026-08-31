import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import MapView, { Marker, Polyline, PROVIDER_DEFAULT } from 'react-native-maps'
import { getTraceForMap } from '../lib/database'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

interface TraceMapProps {
  /** Identifiant de la sortie, lue depuis la base LOCALE. */
  activityUuid: string
  height?: number
}

/**
 * Trace d'une sortie, lue depuis la base locale.
 *
 * Elle s'affiche donc **sans réseau**, immédiatement après l'arrêt — le membre
 * voit son parcours avant même que la sortie ne soit transmise.
 *
 * La trace est **décimée** (500 points au plus, voir `getTraceForMap`) : passer
 * 10 000 points à `<Polyline>` ferait ramer la carte sur un téléphone d'entrée
 * de gamme, alors qu'un point sur N donne exactement le même dessin à
 * l'échelle de l'écran.
 */
export function TraceMap({ activityUuid, height = 220 }: TraceMapProps) {
  const { colors } = useTheme()
  const [trace, setTrace] = useState<{ latitude: number; longitude: number }[] | null>(
    null,
  )

  useEffect(() => {
    let cancelled = false

    void getTraceForMap(activityUuid).then((points) => {
      if (!cancelled) setTrace(points)
    })

    return () => {
      cancelled = true
    }
  }, [activityUuid])

  if (trace === null) {
    return (
      <View
        style={[styles.placeholder, { height, backgroundColor: colors.surface2 }]}
      >
        <Text style={[styles.placeholderText, { color: colors.textMuted }]}>
          Chargement du parcours…
        </Text>
      </View>
    )
  }

  if (trace.length < 2) {
    return (
      <View style={[styles.placeholder, { height, backgroundColor: colors.surface2 }]}>
        <Text style={[styles.placeholderText, { color: colors.textMuted }]}>
          Trace trop courte pour être affichée.
        </Text>
      </View>
    )
  }

  const region = boundingRegion(trace)

  return (
    <View style={[styles.wrapper, { height, borderColor: colors.border }]}>
      <MapView
        // Fournisseur par défaut : Google sur Android, Apple sur iOS. Aucune
        // clé API à gérer, aucun coût — cohérent avec le choix OSM du web
        // (ADR-004).
        provider={PROVIDER_DEFAULT}
        style={StyleSheet.absoluteFill}
        initialRegion={region}
        // La carte du résumé se regarde, elle ne se manipule pas : désactiver
        // les gestes évite de la faire bouger en faisant défiler l'écran.
        scrollEnabled={false}
        zoomEnabled={false}
        rotateEnabled={false}
        pitchEnabled={false}
        toolbarEnabled={false}
      >
        <Polyline
          coordinates={trace}
          strokeColor={colors.green}
          strokeWidth={4}
          lineJoin="round"
          lineCap="round"
        />

        <Marker coordinate={trace[0]!} title="Départ" pinColor="green" />
        <Marker coordinate={trace[trace.length - 1]!} title="Arrivée" pinColor="orange" />
      </MapView>
    </View>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Cadre la carte sur la trace, avec une marge.
 *
 * Le minimum de 0,005° (~550 m) évite qu'une sortie très courte ne soit
 * affichée à un zoom absurde, au ras du bitume.
 */
function boundingRegion(points: { latitude: number; longitude: number }[]) {
  const lats = points.map((p) => p.latitude)
  const lngs = points.map((p) => p.longitude)

  const minLat = Math.min(...lats)
  const maxLat = Math.max(...lats)
  const minLng = Math.min(...lngs)
  const maxLng = Math.max(...lngs)

  return {
    latitude: (minLat + maxLat) / 2,
    longitude: (minLng + maxLng) / 2,
    latitudeDelta: Math.max(0.005, (maxLat - minLat) * 1.4),
    longitudeDelta: Math.max(0.005, (maxLng - minLng) * 1.4),
  }
}

const styles = StyleSheet.create({
  wrapper: {
    borderRadius: radius.md,
    overflow: 'hidden',
    borderWidth: StyleSheet.hairlineWidth,
  },
  placeholder: {
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.lg,
  },
  placeholderText: { fontSize: fontSize.small },
})
