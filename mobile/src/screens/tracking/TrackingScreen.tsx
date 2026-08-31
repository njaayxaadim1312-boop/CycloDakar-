import { StatusBar } from 'expo-status-bar'
import { useEffect, useState } from 'react'
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button } from '../../components/Button'
import { formatDistance, formatDuration, formatPace, formatSpeed } from '../../lib/format'
import { startRefreshLoop, useTracking } from '../../stores/tracking'
import { fontSize, radius, spacing } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'
import type { SportCode } from '../../types/api'

interface TrackingScreenProps {
  onFinished: (uuid: string) => void
  onDiscarded: () => void
}

/**
 * Suivi en direct.
 *
 * Écran conçu pour être lu **au guidon, en plein soleil, en mouvement** :
 *
 *  - un seul chiffre dominant, la distance, en très grand ;
 *  - police à chasse fixe pour les nombres, sinon le chronomètre « danse » à
 *    chaque seconde et devient illisible ;
 *  - aucune animation, aucune carte : elles consommeraient de la batterie
 *    pour une information que le membre ne regarde pas en roulant ;
 *  - boutons surdimensionnés (72 dp), visés parfois avec des gants.
 *
 * Les chiffres affichés sont PROVISOIRES : le serveur recalcule tout à la
 * finalisation, à partir des points bruts.
 */
export function TrackingScreen({ onFinished, onDiscarded }: TrackingScreenProps) {
  const { colors, isDark } = useTheme()
  const { activity, acquiring, pause, resume, stop, discard } = useTracking()
  const [stopping, setStopping] = useState(false)

  // La boucle ne tourne que tant que l'écran est monté : inutile de réveiller
  // le processeur quand personne ne regarde.
  useEffect(() => startRefreshLoop(), [])

  if (activity === null) {
    return (
      <SafeAreaView style={[styles.safe, styles.center, { backgroundColor: colors.bg }]}>
        <ActivityIndicator color={colors.orange} />
      </SafeAreaView>
    )
  }

  const isPaused = activity.status === 'PAUSED'
  const sport = activity.sport as SportCode
  const usesPace = sport !== 'CYCLING'

  const movingS = Math.round(activity.moving_ms / 1000)
  const pausedS = Math.round(activity.paused_ms / 1000)
  const elapsedS = movingS + pausedS

  const avgSpeed = movingS > 0 ? activity.distance_m / movingS : 0
  const avgPace = activity.distance_m > 100 ? movingS / (activity.distance_m / 1000) : 0

  function confirmStop() {
    Alert.alert(
      'Terminer la sortie',
      'Votre parcours sera enregistré et transmis au club.',
      [
        { text: 'Continuer la sortie', style: 'cancel' },
        {
          text: 'Terminer',
          onPress: () => {
            setStopping(true)
            void stop()
              .then((uuid) => {
                if (uuid) onFinished(uuid)
              })
              .finally(() => setStopping(false))
          },
        },
      ],
    )
  }

  function confirmDiscard() {
    Alert.alert(
      'Abandonner la sortie',
      'Le parcours enregistré sera perdu. Cette action est définitive.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Abandonner',
          style: 'destructive',
          onPress: () => void discard().then(onDiscarded),
        },
      ],
    )
  }

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      {/* --- Bandeau d'état ------------------------------------------------ */}
      <View
        style={[
          styles.banner,
          { backgroundColor: isPaused ? colors.warning : colors.green },
        ]}
      >
        <Text style={styles.bannerText}>
          {acquiring
            ? 'Recherche du signal GPS…'
            : isPaused
              ? 'EN PAUSE'
              : 'ENREGISTREMENT EN COURS'}
        </Text>
      </View>

      <View style={styles.content}>
        {/* --- Distance, le chiffre dominant ------------------------------- */}
        <View style={styles.hero}>
          <Text style={[styles.heroValue, { color: colors.text }]}>
            {acquiring ? '—' : formatDistance(activity.distance_m).replace(/\s?(km|m)$/, '')}
          </Text>
          <Text style={[styles.heroUnit, { color: colors.textMuted }]}>
            {activity.distance_m < 1000 ? 'MÈTRES' : 'KILOMÈTRES'}
          </Text>
        </View>

        {/* --- Chronomètre et vitesse -------------------------------------- */}
        <View style={styles.grid}>
          <Metric
            label="Temps actif"
            value={formatDuration(movingS)}
            colors={colors}
          />
          <Metric
            label={usesPace ? 'Allure moyenne' : 'Vitesse moyenne'}
            value={
              acquiring || activity.distance_m === 0
                ? '—'
                : usesPace
                  ? formatPace(avgPace)
                  : formatSpeed(avgSpeed)
            }
            colors={colors}
          />
          <Metric
            label="Durée totale"
            value={formatDuration(elapsedS)}
            colors={colors}
          />
          <Metric
            label={usesPace ? 'Vitesse maxi' : 'Vitesse maxi'}
            value={acquiring ? '—' : formatSpeed(activity.max_speed_mps)}
            colors={colors}
          />
        </View>

        {pausedS > 0 && (
          <Text style={[styles.pausedNote, { color: colors.textMuted }]}>
            {formatDuration(pausedS)} de pause, non comptés dans le temps actif
          </Text>
        )}

        {/*
          Indicateur de qualité du signal. Il n'apparaît que si le filtre a
          écarté beaucoup de points : c'est l'explication d'une trace qui
          paraîtrait courte, donnée AVANT que le membre ne s'en inquiète.
        */}
        {activity.raw_count > 20 &&
          activity.last_seq / activity.raw_count < 0.8 && (
            <View style={[styles.signalWarning, { backgroundColor: colors.warningSoft }]}>
              <Text style={[styles.signalText, { color: colors.warning }]}>
                Signal GPS irrégulier — {Math.round((activity.last_seq / activity.raw_count) * 100)} %
                des positions retenues. La distance peut être sous-estimée.
              </Text>
            </View>
          )}
      </View>

      {/* --- Commandes ----------------------------------------------------- */}
      <View style={styles.controls}>
        {isPaused ? (
          <>
            <Button title="Reprendre" large onPress={() => void resume()} />
            <View style={styles.row}>
              <Button
                title="Terminer"
                variant="dark"
                loading={stopping}
                onPress={confirmStop}
                style={styles.flex}
              />
              <Button
                title="Abandonner"
                variant="ghost"
                onPress={confirmDiscard}
                style={styles.flex}
              />
            </View>
          </>
        ) : (
          <>
            {/* Noir et pleine largeur, comme sur la planche du prototype :
                c'est le bouton qu'on vise sans regarder. */}
            <Button title="Pause" variant="dark" large onPress={() => void pause()} />
            <Button title="Terminer la sortie" variant="ghost" onPress={confirmStop} />
          </>
        )}
      </View>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Metric({
  label,
  value,
  colors,
}: {
  label: string
  value: string
  colors: ReturnType<typeof useTheme>['colors']
}) {
  return (
    <View style={[styles.metric, { backgroundColor: colors.surface, borderColor: colors.border }]}>
      <Text style={[styles.metricLabel, { color: colors.textMuted }]}>
        {label.toUpperCase()}
      </Text>
      <Text style={[styles.metricValue, { color: colors.text }]}>{value}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  center: { alignItems: 'center', justifyContent: 'center' },

  banner: { paddingVertical: spacing.sm, alignItems: 'center' },
  bannerText: {
    fontSize: fontSize.caption,
    fontWeight: '800',
    letterSpacing: 1,
    color: '#1A1A1A',
  },

  content: { flex: 1, padding: spacing.lg, gap: spacing.lg },

  hero: { alignItems: 'center', marginTop: spacing.lg },
  heroValue: {
    fontSize: 76,
    fontWeight: '800',
    letterSpacing: -2,
    // Chasse fixe : sans cela, le chiffre change de largeur à chaque
    // seconde et l'affichage tressaute.
    fontVariant: ['tabular-nums'],
  },
  heroUnit: {
    fontSize: fontSize.caption,
    fontWeight: '700',
    letterSpacing: 2,
    marginTop: -4,
  },

  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  metric: {
    flexGrow: 1,
    flexBasis: '46%',
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.md,
    gap: 2,
  },
  metricLabel: { fontSize: 10, fontWeight: '700', letterSpacing: 0.6 },
  metricValue: {
    fontSize: fontSize.h2,
    fontWeight: '800',
    fontVariant: ['tabular-nums'],
  },

  pausedNote: { fontSize: fontSize.caption, textAlign: 'center' },

  signalWarning: { borderRadius: radius.sm, padding: spacing.md },
  signalText: { fontSize: fontSize.caption, lineHeight: 17, fontWeight: '600' },

  controls: { padding: spacing.lg, gap: spacing.md },
  row: { flexDirection: 'row', gap: spacing.sm },
})
