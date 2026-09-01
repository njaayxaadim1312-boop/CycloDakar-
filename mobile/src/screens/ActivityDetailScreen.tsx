import { useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { ChevronLeft } from 'lucide-react-native'
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { RouteMap } from '../components/RouteMap'
import { fetchActivity } from '../lib/activities'
import {
  formatDateTime,
  formatDistance,
  formatDuration,
  formatDurationLong,
  formatElevation,
  formatPace,
  formatSpeed,
} from '../lib/format'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

interface ActivityDetailScreenProps {
  uuid: string
  onBack: () => void
}

/**
 * Détail d'une sortie déjà transmise.
 *
 * Les chiffres viennent du SERVEUR, pas de la base locale : ce sont ceux qui
 * font foi, ceux qui alimenteront les classements, et une sortie ancienne n'a
 * de toute façon plus ses points bruts sur le téléphone.
 *
 * Le bloc « qualité du signal » n'apparaît que si l'API le renvoie — elle le
 * réserve au propriétaire de la sortie. C'est SA mesure, et le premier élément
 * à regarder quand une trace lui paraît fausse.
 */
export function ActivityDetailScreen({ uuid, onBack }: ActivityDetailScreenProps) {
  const { colors, isDark } = useTheme()

  const query = useQuery({
    queryKey: ['activity', uuid],
    queryFn: () => fetchActivity(uuid),
  })

  const activity = query.data

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <View style={[styles.header, { borderBottomColor: colors.border }]}>
        <Pressable onPress={onBack} hitSlop={12} accessibilityLabel="Retour">
          <ChevronLeft color={colors.text} size={24} />
        </Pressable>
        <Text style={[styles.headerTitle, { color: colors.text }]} numberOfLines={1}>
          {activity?.title ?? 'Sortie'}
        </Text>
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {query.isLoading && <ActivityIndicator color={colors.orange} style={styles.spacer} />}

        {query.isError && (
          <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
            <Text style={[styles.alertText, { color: colors.danger }]}>
              Impossible de charger cette sortie. Vérifiez votre connexion.
            </Text>
          </View>
        )}

        {activity !== undefined && (
          <>
            <RouteMap polyline={activity.polyline} bounds={activity.bounds} height={220} />

            <Text style={[styles.when, { color: colors.textMuted }]}>
              {activity.sport_label}
              {activity.started_at !== null && ` · ${formatDateTime(activity.started_at)}`}
              {activity.zones.length > 0 && `\n${activity.zones.join(' · ')}`}
            </Text>

            {/* --- Chiffres principaux ---------------------------------- */}
            <View
              style={[
                styles.card,
                { backgroundColor: colors.surface, borderColor: colors.border },
              ]}
            >
              <View style={styles.grid}>
                <Metric
                  label="Distance"
                  value={formatDistance(activity.distance_m)}
                  primary
                />
                <Metric
                  label="En mouvement"
                  value={formatDuration(activity.moving_time_s)}
                  primary
                />
              </View>

              <View style={styles.grid}>
                <Metric
                  label={activity.uses_pace ? 'Allure moyenne' : 'Vitesse moyenne'}
                  value={
                    activity.uses_pace
                      ? activity.avg_pace_s_per_km !== null
                        ? formatPace(activity.avg_pace_s_per_km)
                        : '—'
                      : formatSpeed(activity.avg_speed_mps)
                  }
                />
                <Metric label="Maximum" value={formatSpeed(activity.max_speed_mps)} />
                <Metric
                  label="Dénivelé"
                  value={formatElevation(activity.elevation_gain_m)}
                />
              </View>

              <View style={styles.grid}>
                <Metric label="Durée totale" value={formatDuration(activity.duration_s)} />
                <Metric
                  label="Pauses"
                  value={
                    activity.paused_time_s > 0
                      ? formatDurationLong(activity.paused_time_s)
                      : 'aucune'
                  }
                />
                {/* Sans le poids du membre, le serveur ne renvoie pas de
                    calories : une estimation inventée vaudrait moins que
                    rien. Le tiret le dit. */}
                <Metric
                  label="Calories"
                  value={
                    activity.calories_kcal !== null
                      ? `${activity.calories_kcal} kcal`
                      : '—'
                  }
                />
              </View>
            </View>

            {/* --- Qualité du signal ------------------------------------ */}
            {activity.signal !== undefined && (
              <View
                style={[
                  styles.card,
                  { backgroundColor: colors.surface, borderColor: colors.border },
                ]}
              >
                <Text style={[styles.cardTitle, { color: colors.text }]}>
                  Qualité du signal GPS
                </Text>
                <Text style={[styles.cardSub, { color: colors.textMuted }]}>
                  {activity.signal.raw_points_count} points reçus,{' '}
                  {activity.signal.filtered_out} écartés comme aberrants
                  {activity.signal.quality_percent !== null &&
                    ` — ${activity.signal.quality_percent} % retenus`}
                  .
                </Text>
              </View>
            )}

            {activity.notes !== null && activity.notes !== undefined && activity.notes !== '' && (
              <View
                style={[
                  styles.card,
                  { backgroundColor: colors.surface, borderColor: colors.border },
                ]}
              >
                <Text style={[styles.cardTitle, { color: colors.text }]}>Notes</Text>
                <Text style={[styles.notes, { color: colors.text }]}>{activity.notes}</Text>
              </View>
            )}
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Metric({
  label,
  value,
  primary,
}: {
  label: string
  value: string
  primary?: boolean
}) {
  const { colors } = useTheme()

  return (
    <View style={styles.metric}>
      <Text style={[styles.metricLabel, { color: colors.textMuted }]}>{label}</Text>
      <Text
        style={[
          primary ? styles.metricValuePrimary : styles.metricValue,
          { color: primary ? colors.orangeText : colors.text },
        ]}
      >
        {value}
      </Text>
    </View>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
    borderBottomWidth: 1,
  },
  headerTitle: { flex: 1, fontSize: fontSize.h3, fontWeight: '700' },

  scroll: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
  spacer: { marginVertical: spacing.xl },

  when: { fontSize: fontSize.small, lineHeight: 19 },

  card: {
    borderRadius: radius.md,
    borderWidth: 1,
    padding: spacing.lg,
    gap: spacing.lg,
  },
  cardTitle: { fontSize: fontSize.body, fontWeight: '700' },
  cardSub: { fontSize: fontSize.small, lineHeight: 19, marginTop: -spacing.sm },
  notes: { fontSize: fontSize.small, lineHeight: 20, marginTop: -spacing.sm },

  grid: { flexDirection: 'row', gap: spacing.lg },
  metric: { flex: 1 },
  metricLabel: { fontSize: fontSize.caption },
  metricValue: { fontSize: fontSize.h3, fontWeight: '700', marginTop: 2 },
  metricValuePrimary: { fontSize: fontSize.h1, fontWeight: '800', marginTop: 2 },

  alert: { borderRadius: radius.md, padding: spacing.lg },
  alertText: { fontSize: fontSize.small },
})
