import { useMemo } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import MapView, { Marker, Polyline, PROVIDER_DEFAULT } from 'react-native-maps'
import { decimate, decodePolyline } from '../lib/polyline'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { ActivityBounds } from '../types/api'

interface RouteMapProps {
  /** Trace au format Google Encoded Polyline, telle que l'envoie l'API. */
  polyline: string | null
  bounds: ActivityBounds | null
  height?: number
}

/**
 * Trace d'une sortie déjà transmise, dessinée à partir de la polyligne
 * encodée envoyée par le serveur.
 *
 * À distinguer de `TraceMap`, qui lit la base LOCALE : celle-ci sert à revoir
 * une sortie ancienne, dont les points bruts ont été purgés du téléphone après
 * synchronisation. Les deux coexistent volontairement — purger le local était
 * indispensable pour ne pas laisser grossir la base sans fin.
 */
export function RouteMap({ polyline, bounds, height = 200 }: RouteMapProps) {
  const { colors } = useTheme()

  const points = useMemo(
    // 400 points suffisent largement sur un écran de téléphone ; au-delà on
    // dessine plusieurs segments par pixel en faisant ramer la carte.
    () => decimate(decodePolyline(polyline), 400),
    [polyline],
  )

  if (points.length < 2) {
    return (
      <View
        style={[
          styles.empty,
          { height, backgroundColor: colors.surface2, borderColor: colors.border },
        ]}
      >
        <Text style={[styles.emptyText, { color: colors.textMuted }]}>
          Aucune trace enregistrée pour cette sortie.
        </Text>
      </View>
    )
  }

  const start = points[0]!
  const end = points[points.length - 1]!

  // On cadre sur les bornes calculées par le serveur quand elles existent :
  // elles couvrent la trace COMPLÈTE, là où la polyligne est simplifiée.
  const region = bounds
    ? {
        latitude: (bounds.min_lat + bounds.max_lat) / 2,
        longitude: (bounds.min_lng + bounds.max_lng) / 2,
        // La marge de 30 % évite que la trace ne touche les bords.
        latitudeDelta: Math.max((bounds.max_lat - bounds.min_lat) * 1.3, 0.005),
        longitudeDelta: Math.max((bounds.max_lng - bounds.min_lng) * 1.3, 0.005),
      }
    : {
        latitude: start.lat,
        longitude: start.lng,
        latitudeDelta: 0.02,
        longitudeDelta: 0.02,
      }

  return (
    <View style={[styles.wrap, { height, borderColor: colors.border }]}>
      <MapView
        provider={PROVIDER_DEFAULT}
        style={StyleSheet.absoluteFill}
        initialRegion={region}
        // Carte de consultation : on la laisse manipulable, mais sans
        // boussole ni bouton de localisation, qui n'ont pas de sens ici.
        showsCompass={false}
        showsMyLocationButton={false}
        toolbarEnabled={false}
      >
        <Polyline
          coordinates={points.map((p) => ({ latitude: p.lat, longitude: p.lng }))}
          strokeColor="#32CD32"
          strokeWidth={4}
        />

        <Marker
          coordinate={{ latitude: start.lat, longitude: start.lng }}
          title="Départ"
          pinColor="green"
        />
        <Marker
          coordinate={{ latitude: end.lat, longitude: end.lng }}
          title="Arrivée"
          pinColor="red"
        />
      </MapView>
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    borderRadius: radius.md,
    borderWidth: 1,
    overflow: 'hidden',
  },
  empty: {
    borderRadius: radius.md,
    borderWidth: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.lg,
  },
  emptyText: { fontSize: fontSize.small, textAlign: 'center' },
})
