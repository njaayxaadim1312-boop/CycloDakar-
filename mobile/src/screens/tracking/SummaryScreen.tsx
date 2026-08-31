import { StatusBar } from 'expo-status-bar'
import { useEffect, useState } from 'react'
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button } from '../../components/Button'
import { getLocalActivity, type LocalActivity } from '../../lib/database'
import {
  formatDistance,
  formatDuration,
  formatElevation,
  formatPace,
  formatSpeed,
} from '../../lib/format'
import { syncPending, type SyncOutcome } from '../../services/sync'
import { fontSize, radius, spacing } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'
import type { SportCode } from '../../types/api'

interface SummaryScreenProps {
  uuid: string
  onClose: () => void
}

const SPORT_EMOJI: Record<SportCode, string> = {
  CYCLING: '🚴',
  RUNNING: '🏃',
  HIKING: '🥾',
}

const SPORT_LABEL: Record<SportCode, string> = {
  CYCLING: 'Cyclisme',
  RUNNING: 'Course',
  HIKING: 'Randonnée',
}

/**
 * Résumé de la sortie qui vient de se terminer.
 *
 * Les chiffres viennent de la base LOCALE : ils s'affichent immédiatement,
 * sans attendre le réseau. Le serveur les recalculera et pourra les ajuster
 * légèrement — il voit toute la trace, là où le téléphone calculait au fil de
 * l'eau. On le dit franchement plutôt que de laisser croire à un bug si un
 * chiffre bouge de quelques mètres.
 */
export function SummaryScreen({ uuid, onClose }: SummaryScreenProps) {
  const { colors, isDark } = useTheme()

  const [activity, setActivity] = useState<LocalActivity | null>(null)
  const [sync, setSync] = useState<SyncOutcome | null>(null)
  const [syncing, setSyncing] = useState(true)

  useEffect(() => {
    let cancelled = false

    void getLocalActivity(uuid).then((found) => {
      if (!cancelled) setActivity(found)
    })

    void syncPending()
      .then((results) => {
        if (!cancelled) {
          setSync(results.find((r) => r.uuid === uuid) ?? null)
        }
      })
      .finally(() => {
        if (!cancelled) setSyncing(false)
      })

    return () => {
      cancelled = true
    }
  }, [uuid])

  if (activity === null) {
    return (
      <SafeAreaView style={[styles.safe, styles.center, { backgroundColor: colors.bg }]}>
        <ActivityIndicator color={colors.orange} />
      </SafeAreaView>
    )
  }

  const sport = activity.sport as SportCode
  const usesPace = sport !== 'CYCLING'
  const movingS = Math.round(activity.moving_ms / 1000)
  const pausedS = Math.round(activity.paused_ms / 1000)

  const avgSpeed = movingS > 0 ? activity.distance_m / movingS : 0
  const avgPace = activity.distance_m > 100 ? movingS / (activity.distance_m / 1000) : 0

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <ScrollView contentContainerStyle={styles.scroll}>
        {/* --- Bandeau ------------------------------------------------------ */}
        <View style={[styles.hero, { backgroundColor: colors.orange }]}>
          <Text style={styles.heroEmoji}>{SPORT_EMOJI[sport]}</Text>
          <Text style={styles.heroSport}>{SPORT_LABEL[sport].toUpperCase()}</Text>
          <Text style={styles.heroDistance}>{formatDistance(activity.distance_m)}</Text>
          <Text style={styles.heroDate}>
            {new Date(activity.started_at).toLocaleString('fr-FR', {
              dateStyle: 'long',
              timeStyle: 'short',
            })}
          </Text>
        </View>

        {/* --- Statistiques -------------------------------------------------- */}
        <View style={styles.grid}>
          <Stat label="Temps actif" value={formatDuration(movingS)} colors={colors} />
          <Stat
            label="Durée totale"
            value={formatDuration(movingS + pausedS)}
            colors={colors}
          />
          <Stat
            label={usesPace ? 'Allure moyenne' : 'Vitesse moyenne'}
            value={usesPace ? formatPace(avgPace) : formatSpeed(avgSpeed)}
            colors={colors}
          />
          <Stat
            label="Vitesse maximale"
            value={formatSpeed(activity.max_speed_mps)}
            colors={colors}
          />
          <Stat
            label="Dénivelé positif"
            value={formatElevation(activity.elevation_gain_m)}
            colors={colors}
          />
          <Stat
            label="Points GPS"
            value={`${activity.last_seq}`}
            colors={colors}
          />
        </View>

        {pausedS > 0 && (
          <Text style={[styles.note, { color: colors.textMuted }]}>
            {formatDuration(pausedS)} de pause, exclus du temps actif et de la moyenne.
          </Text>
        )}

        {/* --- État de la transmission --------------------------------------- */}
        <View
          style={[
            styles.syncCard,
            {
              backgroundColor:
                sync?.status === 'synced'
                  ? colors.greenSoft
                  : sync?.status === 'failed'
                    ? colors.dangerSoft
                    : colors.surface2,
            },
          ]}
        >
          {syncing ? (
            <View style={styles.syncRow}>
              <ActivityIndicator color={colors.orange} size="small" />
              <Text style={[styles.syncText, { color: colors.text }]}>
                Transmission au club…
              </Text>
            </View>
          ) : sync?.status === 'synced' ? (
            <Text style={[styles.syncText, { color: colors.greenHover }]}>
              ✓ Sortie transmise au club. Le serveur a recalculé les statistiques
              définitives — elles peuvent différer de quelques mètres de celles
              affichées ici.
            </Text>
          ) : sync?.status === 'offline' ? (
            <Text style={[styles.syncText, { color: colors.text }]}>
              Pas de connexion. Votre sortie est enregistrée sur le téléphone et sera
              transmise automatiquement dès le retour du réseau — rien n'est perdu.
            </Text>
          ) : sync?.status === 'failed' ? (
            <Text style={[styles.syncText, { color: colors.danger }]}>
              La transmission a échoué : {sync.message} La sortie reste enregistrée
              sur le téléphone et repartira plus tard.
            </Text>
          ) : (
            <Text style={[styles.syncText, { color: colors.text }]}>
              Sortie enregistrée sur le téléphone.
            </Text>
          )}
        </View>

        <Text style={[styles.note, { color: colors.textMuted }]}>
          La carte du parcours, les zones traversées et le graphique d'altitude
          arrivent en phase 7.
        </Text>
      </ScrollView>

      <View style={[styles.footer, { borderTopColor: colors.border }]}>
        <Button title="Terminé" large onPress={onClose} />
      </View>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Stat({
  label,
  value,
  colors,
}: {
  label: string
  value: string
  colors: ReturnType<typeof useTheme>['colors']
}) {
  return (
    <View style={[styles.stat, { backgroundColor: colors.surface, borderColor: colors.border }]}>
      <Text style={[styles.statLabel, { color: colors.textMuted }]}>
        {label.toUpperCase()}
      </Text>
      <Text style={[styles.statValue, { color: colors.text }]}>{value}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  center: { alignItems: 'center', justifyContent: 'center' },
  scroll: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },

  hero: {
    borderRadius: radius.lg,
    padding: spacing.xl,
    alignItems: 'center',
    gap: 2,
  },
  heroEmoji: { fontSize: 40 },
  heroSport: {
    fontSize: fontSize.caption,
    fontWeight: '800',
    letterSpacing: 1.5,
    color: 'rgba(0,0,0,0.6)',
  },
  heroDistance: {
    fontSize: 46,
    fontWeight: '800',
    color: '#1A1A1A',
    fontVariant: ['tabular-nums'],
  },
  heroDate: { fontSize: fontSize.small, color: 'rgba(0,0,0,0.7)' },

  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  stat: {
    flexGrow: 1,
    flexBasis: '46%',
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.md,
    gap: 2,
  },
  statLabel: { fontSize: 10, fontWeight: '700', letterSpacing: 0.6 },
  statValue: {
    fontSize: fontSize.h3,
    fontWeight: '800',
    fontVariant: ['tabular-nums'],
  },

  note: { fontSize: fontSize.caption, lineHeight: 17, textAlign: 'center' },

  syncCard: { borderRadius: radius.md, padding: spacing.lg },
  syncRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  syncText: { fontSize: fontSize.small, lineHeight: 20, flex: 1 },

  footer: { padding: spacing.lg, borderTopWidth: StyleSheet.hairlineWidth },
})
