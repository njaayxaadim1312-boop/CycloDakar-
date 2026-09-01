import { useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { ChevronRight } from 'lucide-react-native'
import { useEffect, useRef } from 'react'
import {
  Animated,
  Easing,
  Image,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { ActivityRings, type RingConfig } from '../components/ActivityRings'
import { ApiError } from '../lib/api'
import { fetchActivities } from '../lib/activities'
import {
  formatDate,
  formatDistance,
  formatDurationLong,
  formatSpeed,
} from '../lib/format'
import { SPORT_EMOJI } from '../lib/sports'
import { fetchDashboardStats, fetchPersonalStats } from '../lib/stats'
import { useCurrentUser } from '../stores/auth'
import { brand, fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { Activity } from '../types/api'

interface HomeScreenProps {
  onOpenHistory: () => void
  onOpenActivity: (uuid: string) => void
  onOpenEvents: () => void
}

const RING_CONFIGS: RingConfig[] = [
  { key: 'distance_m', label: 'Distance', color: brand.orange },
  { key: 'moving_time_s', label: 'Mouvement', color: brand.blue },
  { key: 'activities', label: 'Sorties', color: brand.green },
]

/**
 * Accueil : **l'exercice, et rien d'autre**.
 *
 * L'écran s'ouvrait sur les effectifs du club. Il s'ouvre désormais sur ce que
 * le membre a fait cette semaine. Les effectifs, les participations et la
 * caisse ne sont plus ici du tout — ils vivent sur le web, derrière la page
 * « Gestion du club ». Un membre sort son téléphone pour enregistrer une
 * sortie, pas pour consulter un solde.
 *
 * L'ordre de lecture : mes anneaux, ma régularité, mes dernières sorties, la
 * prochaine sortie du club.
 */
export function HomeScreen({
  onOpenHistory,
  onOpenActivity,
  onOpenEvents,
}: HomeScreenProps) {
  const { colors, isDark } = useTheme()
  const user = useCurrentUser()

  const stats = useQuery({
    queryKey: ['stats', 'me', 'week'],
    queryFn: () => fetchPersonalStats('week'),
    retry: (count, error) =>
      !(error instanceof ApiError && error.code === 'NO_MEMBER_PROFILE') && count < 2,
  })

  const recent = useQuery({
    queryKey: ['activities', 'mine', 'recent'],
    queryFn: () => fetchActivities({ mine: true, per_page: 3 }),
  })

  const club = useQuery({
    queryKey: ['stats', 'dashboard'],
    queryFn: fetchDashboardStats,
  })

  const noProfile =
    stats.error instanceof ApiError && stats.error.code === 'NO_MEMBER_PROFILE'

  const rings = stats.data?.rings

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style="light" />

      <ScrollView
        contentContainerStyle={styles.scroll}
        refreshControl={
          <RefreshControl
            refreshing={stats.isFetching && !stats.isLoading}
            onRefresh={() => {
              void stats.refetch()
              void recent.refetch()
            }}
            tintColor={colors.orange}
            colors={[colors.orange]}
          />
        }
      >
        {/* --- Bandeau : l'affiche du club, en verre ---------------------- */}
        <View style={styles.hero}>
          <Image
            source={require('../../assets/hero.jpg')}
            style={StyleSheet.absoluteFill}
            resizeMode="cover"
            accessible={false}
          />
          {/* L'affiche est très contrastée : sans ce voile, le texte blanc
              tomberait tantôt sur du noir, tantôt sur un gilet fluo. */}
          <View style={styles.heroVeil} />

          <View style={styles.heroBody}>
            <Text style={styles.heroKicker}>CETTE SEMAINE</Text>
            <Text style={styles.heroTitle} numberOfLines={1}>
              Bonjour {user?.name.split(' ')[0] ?? ''}
            </Text>

            <FadeIn>
              {rings !== undefined ? (
                <ActivityRings rings={rings} configs={RING_CONFIGS} size={172} />
              ) : (
                <View style={styles.ringPlaceholder} />
              )}
            </FadeIn>

            {rings !== undefined && (
              <View style={styles.legend}>
                {RING_CONFIGS.map((config) => {
                  const metric = rings.metrics[config.key]
                  const format =
                    config.key === 'distance_m'
                      ? formatDistance
                      : config.key === 'moving_time_s'
                        ? formatDurationLong
                        : String

                  return (
                    <View key={config.key} style={styles.legendItem}>
                      <View style={[styles.dot, { backgroundColor: config.color }]} />
                      <Text style={styles.legendValue}>{format(metric.value)}</Text>
                      <Text style={styles.legendGoal}>/ {format(metric.goal)}</Text>
                    </View>
                  )
                })}
              </View>
            )}
          </View>
        </View>

        {noProfile && (
          <View style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}>
            <Text style={[styles.muted, { color: colors.textMuted }]}>
              Votre compte n'est pas encore rattaché à une fiche membre. Contactez
              le bureau du club pour pouvoir enregistrer vos sorties.
            </Text>
          </View>
        )}

        {/* --- Régularité -------------------------------------------------- */}
        {rings !== undefined && (
          <View
            style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
          >
            <Text style={[styles.cardTitle, { color: colors.text }]}>Régularité</Text>

            <View style={styles.week}>
              {rings.days.map((day) => (
                <View
                  key={day.date}
                  style={[
                    styles.day,
                    {
                      backgroundColor: day.active ? colors.orange : colors.surface2,
                    },
                  ]}
                >
                  <Text
                    style={[
                      styles.dayLabel,
                      { color: day.active ? colors.black : colors.textMuted },
                    ]}
                  >
                    {day.label}
                  </Text>
                </View>
              ))}
            </View>

            {/* Le nombre de jours actifs dit ce que le cumul ne dit pas :
                rouler 40 km en une fois n'est pas rouler 40 km en quatre. */}
            <Text style={[styles.muted, { color: colors.textMuted }]}>
              {rings.days.filter((day) => day.active).length} jour
              {rings.days.filter((day) => day.active).length > 1 ? 's' : ''} d'activité
              sur les sept.
            </Text>
          </View>
        )}

        {/* --- Dernières sorties ------------------------------------------ */}
        <View
          style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
        >
          <View style={styles.cardHead}>
            <Text style={[styles.cardTitle, { color: colors.text }]}>Mes sorties</Text>
            <Pressable onPress={onOpenHistory} hitSlop={8}>
              <Text style={[styles.link, { color: colors.orangeText }]}>Tout voir →</Text>
            </Pressable>
          </View>

          {recent.isSuccess && recent.data.data.length === 0 && (
            <Text style={[styles.muted, { color: colors.textMuted }]}>
              Aucune sortie enregistrée. Touchez « Démarrer » pour votre première
              sortie — vélo, course, randonnée ou marche.
            </Text>
          )}

          {recent.data?.data.map((activity) => (
            <ActivityRow
              key={activity.uuid}
              activity={activity}
              onPress={() => onOpenActivity(activity.uuid)}
            />
          ))}
        </View>

        {/* --- Prochaine sortie du club ------------------------------------ */}
        {club.data?.events.next != null && (
          <Pressable
            onPress={onOpenEvents}
            accessibilityRole="button"
            style={({ pressed }) => [
              styles.card,
              styles.nextEvent,
              {
                backgroundColor: pressed ? colors.surface2 : colors.surface,
                borderColor: colors.border,
              },
            ]}
          >
            <View style={styles.flex}>
              <Text style={[styles.muted, { color: colors.textMuted }]}>
                Prochaine sortie du club
              </Text>
              <Text style={[styles.nextTitle, { color: colors.text }]} numberOfLines={1}>
                {club.data.events.next.title}
              </Text>
              <Text style={[styles.muted, { color: colors.textMuted }]} numberOfLines={1}>
                {club.data.events.next.starts_at !== null &&
                  formatDate(club.data.events.next.starts_at)}
                {' · '}
                {club.data.events.next.location_name}
              </Text>
            </View>
            <ChevronRight color={colors.textMuted} size={18} />
          </Pressable>
        )}
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Apparition en fondu montant.
 *
 * `useNativeDriver` est possible ici — opacité et translation sont toutes deux
 * pilotables nativement, donc l'animation ne repasse pas par le fil JavaScript
 * et reste fluide même pendant le chargement des données.
 */
function FadeIn({ children }: { children: React.ReactNode }) {
  const value = useRef(new Animated.Value(0)).current

  useEffect(() => {
    Animated.timing(value, {
      toValue: 1,
      duration: 420,
      easing: Easing.out(Easing.cubic),
      useNativeDriver: true,
    }).start()
  }, [value])

  return (
    <Animated.View
      style={{
        opacity: value,
        transform: [
          { translateY: value.interpolate({ inputRange: [0, 1], outputRange: [12, 0] }) },
        ],
      }}
    >
      {children}
    </Animated.View>
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
        styles.activity,
        { backgroundColor: pressed ? colors.surface2 : 'transparent' },
      ]}
    >
      <Text style={styles.activityEmoji}>{SPORT_EMOJI[activity.sport]}</Text>

      <View style={styles.flex}>
        <Text style={[styles.activityTitle, { color: colors.text }]} numberOfLines={1}>
          {activity.title}
        </Text>
        <Text style={[styles.muted, { color: colors.textMuted }]}>
          {activity.started_at !== null ? formatDate(activity.started_at) : '—'}
        </Text>
        <View style={styles.activityStats}>
          <Text style={[styles.activityStat, { color: colors.orangeText }]}>
            {formatDistance(activity.distance_m)}
          </Text>
          <Text style={[styles.activityStat, { color: colors.textMuted }]}>
            {formatDurationLong(activity.moving_time_s)}
          </Text>
          <Text style={[styles.activityStat, { color: colors.textMuted }]}>
            {formatSpeed(activity.avg_speed_mps)}
          </Text>
        </View>
      </View>

      <ChevronRight color={colors.textMuted} size={16} />
    </Pressable>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  scroll: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },

  hero: {
    overflow: 'hidden',
    borderRadius: radius.lg,
    minHeight: 380,
  },
  heroVeil: {
    position: 'absolute',
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    backgroundColor: 'rgba(0,0,0,0.62)',
  },
  heroBody: { alignItems: 'center', gap: spacing.md, padding: spacing.lg },
  heroKicker: {
    fontSize: fontSize.caption,
    fontWeight: '800',
    letterSpacing: 1.4,
    color: 'rgba(255,255,255,0.7)',
  },
  heroTitle: { fontSize: fontSize.h1, fontWeight: '800', color: '#FFFFFF' },
  ringPlaceholder: {
    width: 172,
    height: 172,
    borderRadius: 86,
    backgroundColor: 'rgba(255,255,255,0.08)',
  },

  legend: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: spacing.md },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: spacing.xs },
  dot: { width: 8, height: 8, borderRadius: 4 },
  legendValue: { fontSize: fontSize.small, fontWeight: '700', color: '#FFFFFF' },
  legendGoal: { fontSize: fontSize.small, color: 'rgba(255,255,255,0.6)' },

  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  cardHead: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between' },
  cardTitle: { fontSize: fontSize.body, fontWeight: '700' },
  link: { fontSize: fontSize.small, fontWeight: '600' },
  muted: { fontSize: fontSize.small, lineHeight: 19 },

  week: { flexDirection: 'row', gap: spacing.xs, marginVertical: spacing.xs },
  day: {
    flex: 1,
    aspectRatio: 1,
    borderRadius: radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dayLabel: { fontSize: fontSize.caption, fontWeight: '800' },

  activity: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    borderRadius: radius.sm,
    paddingVertical: spacing.sm,
  },
  activityEmoji: { fontSize: 26 },
  activityTitle: { fontSize: fontSize.small, fontWeight: '600' },
  activityStats: { flexDirection: 'row', gap: spacing.md, marginTop: 2 },
  activityStat: { fontSize: fontSize.caption, fontWeight: '600' },

  nextEvent: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  nextTitle: { fontSize: fontSize.body, fontWeight: '700' },
})
