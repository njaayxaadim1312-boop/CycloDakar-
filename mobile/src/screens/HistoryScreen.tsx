import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { ChevronLeft, ChevronRight } from 'lucide-react-native'
import { useState } from 'react'
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { fetchActivities } from '../lib/activities'
import {
  formatDate,
  formatDistance,
  formatDuration,
  formatDurationLong,
  formatElevation,
  formatSpeed,
} from '../lib/format'
import { fetchPersonalStats } from '../lib/stats'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { Activity, SportCode, StatsPeriod } from '../types/api'

interface HistoryScreenProps {
  onBack: () => void
  onOpenActivity: (uuid: string) => void
}

const SPORT_EMOJI: Record<SportCode, string> = {
  CYCLING: '🚴',
  RUNNING: '🏃',
  HIKING: '🥾',
}

const PERIODS: ReadonlyArray<{ value: StatsPeriod; label: string }> = [
  { value: 'week', label: 'Semaine' },
  { value: 'month', label: 'Mois' },
  { value: 'year', label: 'Année' },
  { value: 'all', label: 'Tout' },
]

/**
 * Historique des sorties du membre.
 *
 * Les cumuls en tête viennent de `/stats/me`, la liste de `/activities` :
 * deux requêtes, mais **une seule source de vérité par chiffre**. Additionner
 * la page affichée pour fabriquer un total donnerait le cumul des vingt
 * dernières sorties en le présentant comme le cumul du mois — un mensonge
 * discret et difficile à repérer.
 *
 * La liste ne charge que la première page : la pagination infinie arrive avec
 * les autres listes longues. REPORTÉE À LA PHASE 18.
 */
export function HistoryScreen({ onBack, onOpenActivity }: HistoryScreenProps) {
  const { colors, isDark } = useTheme()
  const [period, setPeriod] = useState<StatsPeriod>('month')

  const stats = useQuery({
    queryKey: ['stats', 'me', period],
    queryFn: () => fetchPersonalStats(period),
    placeholderData: keepPreviousData,
  })

  const activities = useQuery({
    queryKey: ['activities', 'mine', period],
    queryFn: () =>
      fetchActivities({
        mine: true,
        per_page: 30,
        ...(stats.data?.period_from ? { from: stats.data.period_from } : {}),
      }),
    // On attend les bornes de la période : lancer la liste avant donnerait
    // brièvement toutes les sorties, tous mois confondus.
    enabled: stats.data !== undefined,
    placeholderData: keepPreviousData,
  })

  const totals = stats.data?.totals
  const loading = stats.isLoading || activities.isLoading

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <View style={[styles.header, { borderBottomColor: colors.border }]}>
        <Pressable onPress={onBack} hitSlop={12} accessibilityLabel="Retour">
          <ChevronLeft color={colors.text} size={24} />
        </Pressable>
        <Text style={[styles.headerTitle, { color: colors.text }]}>Mes sorties</Text>
      </View>

      <FlatList
        data={activities.data?.data ?? []}
        keyExtractor={(item) => item.uuid}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl
            refreshing={activities.isFetching && !activities.isLoading}
            onRefresh={() => {
              void stats.refetch()
              void activities.refetch()
            }}
            tintColor={colors.orange}
            colors={[colors.orange]}
          />
        }
        ListHeaderComponent={
          <View style={styles.head}>
            {/* --- Période --------------------------------------------- */}
            <View style={[styles.periods, { backgroundColor: colors.surface2 }]}>
              {PERIODS.map((entry) => {
                const active = entry.value === period

                return (
                  <Pressable
                    key={entry.value}
                    onPress={() => setPeriod(entry.value)}
                    accessibilityRole="button"
                    accessibilityState={{ selected: active }}
                    style={[
                      styles.period,
                      active && { backgroundColor: colors.orange },
                    ]}
                  >
                    <Text
                      style={[
                        styles.periodLabel,
                        { color: active ? colors.black : colors.textMuted },
                      ]}
                    >
                      {entry.label}
                    </Text>
                  </Pressable>
                )
              })}
            </View>

            {/* --- Cumuls ---------------------------------------------- */}
            <View
              style={[
                styles.card,
                { backgroundColor: colors.surface, borderColor: colors.border },
              ]}
            >
              <Text style={[styles.cardTitle, { color: colors.textMuted }]}>
                {stats.data?.period_label ?? '…'}
              </Text>

              {totals === undefined ? (
                <ActivityIndicator color={colors.orange} style={styles.spacer} />
              ) : (
                <>
                  <View style={styles.totalsRow}>
                    <Total
                      value={formatDistance(totals.distance_m)}
                      label="distance"
                      primary
                    />
                    <Total
                      value={formatDurationLong(totals.moving_time_s)}
                      label="en mouvement"
                    />
                  </View>
                  <View style={styles.totalsRow}>
                    <Total value={String(totals.activities)} label="sorties" />
                    <Total
                      value={formatElevation(totals.elevation_gain_m)}
                      label="dénivelé"
                    />
                    <Total
                      value={formatSpeed(totals.avg_speed_mps)}
                      label="moyenne"
                    />
                  </View>
                </>
              )}
            </View>
          </View>
        }
        renderItem={({ item }) => (
          <ActivityRow activity={item} onPress={() => onOpenActivity(item.uuid)} />
        )}
        ListEmptyComponent={
          loading ? (
            <ActivityIndicator color={colors.orange} style={styles.spacer} />
          ) : (
            <Text style={[styles.empty, { color: colors.textMuted }]}>
              Aucune sortie sur cette période.
            </Text>
          )
        }
      />
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Total({
  value,
  label,
  primary,
}: {
  value: string
  label: string
  primary?: boolean
}) {
  const { colors } = useTheme()

  return (
    <View style={styles.total}>
      <Text
        style={[
          primary ? styles.totalValuePrimary : styles.totalValue,
          { color: primary ? colors.orangeText : colors.text },
        ]}
      >
        {value}
      </Text>
      <Text style={[styles.totalLabel, { color: colors.textMuted }]}>{label}</Text>
    </View>
  )
}

function ActivityRow({
  activity,
  onPress,
}: {
  activity: Activity
  onPress: () => void
}) {
  const { colors } = useTheme()

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.row,
        {
          backgroundColor: pressed ? colors.surface2 : colors.surface,
          borderColor: colors.border,
        },
      ]}
    >
      <Text style={styles.rowEmoji}>{SPORT_EMOJI[activity.sport]}</Text>

      <View style={styles.flex}>
        <Text style={[styles.rowTitle, { color: colors.text }]} numberOfLines={1}>
          {activity.title}
        </Text>
        <Text style={[styles.rowMeta, { color: colors.textMuted }]} numberOfLines={1}>
          {activity.started_at !== null ? formatDate(activity.started_at) : '—'}
          {activity.zones.length > 0 && ` · ${activity.zones[0]}`}
        </Text>

        <View style={styles.rowStats}>
          <Text style={[styles.rowStat, { color: colors.text }]}>
            {formatDistance(activity.distance_m)}
          </Text>
          <Text style={[styles.rowStat, { color: colors.textMuted }]}>
            {formatDuration(activity.moving_time_s)}
          </Text>
          <Text style={[styles.rowStat, { color: colors.textMuted }]}>
            {formatSpeed(activity.avg_speed_mps)}
          </Text>
        </View>
      </View>

      <ChevronRight color={colors.textMuted} size={18} />
    </Pressable>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
    borderBottomWidth: 1,
  },
  headerTitle: { fontSize: fontSize.h2, fontWeight: '700' },

  list: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xl },
  head: { gap: spacing.md, marginBottom: spacing.sm },

  periods: {
    flexDirection: 'row',
    borderRadius: radius.pill,
    padding: 3,
  },
  period: {
    flex: 1,
    paddingVertical: spacing.sm,
    borderRadius: radius.pill,
    alignItems: 'center',
  },
  periodLabel: { fontSize: fontSize.small, fontWeight: '600' },

  card: {
    borderRadius: radius.md,
    borderWidth: 1,
    padding: spacing.lg,
    gap: spacing.md,
  },
  cardTitle: { fontSize: fontSize.small, fontWeight: '700' },
  spacer: { marginVertical: spacing.lg },

  totalsRow: { flexDirection: 'row', gap: spacing.lg },
  total: { flex: 1 },
  totalValue: { fontSize: fontSize.h3, fontWeight: '700' },
  totalValuePrimary: { fontSize: fontSize.h1, fontWeight: '800' },
  totalLabel: { fontSize: fontSize.caption, marginTop: 2 },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    padding: spacing.md,
  },
  rowEmoji: { fontSize: 26 },
  rowTitle: { fontSize: fontSize.body, fontWeight: '600' },
  rowMeta: { fontSize: fontSize.caption, marginTop: 1 },
  rowStats: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.xs },
  rowStat: { fontSize: fontSize.small, fontWeight: '600' },

  empty: { textAlign: 'center', marginTop: spacing.xl, fontSize: fontSize.small },
})
