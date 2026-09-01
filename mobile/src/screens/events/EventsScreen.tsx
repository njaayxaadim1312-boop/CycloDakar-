import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { ChevronRight, MapPin, Route, Users } from 'lucide-react-native'
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
import { fetchEvents } from '../../lib/events'
import { formatDateTime, formatDistance } from '../../lib/format'
import { fontSize, radius, spacing } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'
import type { ClubEvent, SportCode } from '../../types/api'

interface EventsScreenProps {
  onOpenEvent: (uuid: string) => void
}

const SPORT_EMOJI: Record<SportCode, string> = {
  CYCLING: '🚴',
  RUNNING: '🏃',
  HIKING: '🥾',
}

const SCOPES: ReadonlyArray<{ value: 'upcoming' | 'past'; label: string }> = [
  { value: 'upcoming', label: 'À venir' },
  { value: 'past', label: 'Passées' },
]

/**
 * Calendrier des sorties du club.
 *
 * Vue « à venir » par défaut : un membre qui ouvre cet onglet cherche la
 * prochaine sortie. Le filtre « Mes inscriptions » répond à l'autre question
 * fréquente — « je me suis inscrit à quoi, déjà ? ».
 */
export function EventsScreen({ onOpenEvent }: EventsScreenProps) {
  const { colors, isDark } = useTheme()
  const [scope, setScope] = useState<'upcoming' | 'past'>('upcoming')
  const [mine, setMine] = useState(false)

  const query = useQuery({
    queryKey: ['events', scope, mine],
    queryFn: () => fetchEvents({ scope, mine, per_page: 30 }),
    placeholderData: keepPreviousData,
  })

  const events = query.data?.data ?? []

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <View style={styles.header}>
        <Text style={[styles.title, { color: colors.text }]}>Événements</Text>
        <Text style={[styles.subtitle, { color: colors.textMuted }]}>
          Les sorties officielles du club.
        </Text>
      </View>

      <FlatList
        data={events}
        keyExtractor={(item) => item.uuid}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl
            refreshing={query.isFetching && !query.isLoading}
            onRefresh={() => void query.refetch()}
            tintColor={colors.orange}
            colors={[colors.orange]}
          />
        }
        ListHeaderComponent={
          <View style={styles.filters}>
            <View style={[styles.scopes, { backgroundColor: colors.surface2 }]}>
              {SCOPES.map((entry) => {
                const active = entry.value === scope

                return (
                  <Pressable
                    key={entry.value}
                    onPress={() => setScope(entry.value)}
                    accessibilityRole="button"
                    accessibilityState={{ selected: active }}
                    style={[styles.scope, active && { backgroundColor: colors.orange }]}
                  >
                    <Text
                      style={[
                        styles.scopeLabel,
                        { color: active ? colors.black : colors.textMuted },
                      ]}
                    >
                      {entry.label}
                    </Text>
                  </Pressable>
                )
              })}
            </View>

            <Pressable
              onPress={() => setMine((current) => !current)}
              accessibilityRole="button"
              accessibilityState={{ selected: mine }}
              style={[
                styles.chip,
                {
                  backgroundColor: mine ? colors.orangeSoft : colors.surface,
                  borderColor: mine ? colors.orange : colors.border,
                },
              ]}
            >
              <Text
                style={[
                  styles.chipLabel,
                  { color: mine ? colors.orangeText : colors.textMuted },
                ]}
              >
                Mes inscriptions
              </Text>
            </Pressable>
          </View>
        }
        renderItem={({ item }) => (
          <EventRow event={item} onPress={() => onOpenEvent(item.uuid)} />
        )}
        ListEmptyComponent={
          query.isLoading ? (
            <ActivityIndicator color={colors.orange} style={styles.spacer} />
          ) : (
            <Text style={[styles.empty, { color: colors.textMuted }]}>
              {scope === 'past'
                ? "Aucune sortie passée n'est enregistrée."
                : "Aucune sortie n'est prévue pour le moment."}
            </Text>
          )
        }
      />
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function EventRow({ event, onPress }: { event: ClubEvent; onPress: () => void }) {
  const { colors } = useTheme()
  const cancelled = event.status === 'CANCELLED'
  const mine = event.my_registration

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
      <Text style={styles.rowEmoji}>{SPORT_EMOJI[event.sport]}</Text>

      <View style={styles.flex}>
        <View style={styles.rowTitleLine}>
          <Text
            style={[
              styles.rowTitle,
              { color: cancelled ? colors.textMuted : colors.text },
              cancelled && styles.strike,
            ]}
            numberOfLines={1}
          >
            {event.title}
          </Text>

          {/* Répéter « Annoncé » sur chaque ligne n'apprend rien ; un
              « Annulé » doit sauter aux yeux. */}
          {event.status !== 'PUBLISHED' && (
            <Text style={[styles.statusPill, { color: colors.textMuted, backgroundColor: colors.surface2 }]}>
              {event.status_label}
            </Text>
          )}
        </View>

        <Text style={[styles.rowMeta, { color: colors.textMuted }]} numberOfLines={1}>
          {event.starts_at !== null ? formatDateTime(event.starts_at) : 'Date à préciser'}
        </Text>

        <View style={styles.rowFacts}>
          <Fact icon={MapPin} text={event.location_name} />
          {event.planned_distance_m !== null && (
            <Fact icon={Route} text={formatDistance(event.planned_distance_m)} />
          )}
          <Fact
            icon={Users}
            text={
              event.max_participants !== null
                ? `${event.seats_taken} / ${event.max_participants}`
                : `${event.seats_taken}`
            }
            warn={event.is_full}
          />
        </View>

        {mine !== null && (
          <Text
            style={[
              styles.badge,
              mine.status === 'WAITLIST'
                ? { color: colors.warning, backgroundColor: colors.warningSoft }
                : { color: colors.greenHover, backgroundColor: colors.successSoft },
            ]}
          >
            {mine.status === 'WAITLIST' && mine.queue_position !== null
              ? `${mine.queue_position}ᵉ en attente`
              : 'Inscrit'}
          </Text>
        )}
      </View>

      <ChevronRight color={colors.textMuted} size={18} />
    </Pressable>
  )
}

function Fact({
  icon: Icon,
  text,
  warn,
}: {
  icon: typeof MapPin
  text: string
  warn?: boolean
}) {
  const { colors } = useTheme()
  const color = warn === true ? colors.warning : colors.textMuted

  return (
    <View style={styles.fact}>
      <Icon color={color} size={13} />
      <Text style={[styles.factText, { color }]} numberOfLines={1}>
        {text}
      </Text>
    </View>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  header: { paddingHorizontal: spacing.lg, paddingBottom: spacing.sm },
  title: { fontSize: fontSize.h2, fontWeight: '800' },
  subtitle: { fontSize: fontSize.small, marginTop: 2 },

  list: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xl },
  filters: { gap: spacing.sm, marginBottom: spacing.sm },

  scopes: { flexDirection: 'row', borderRadius: radius.pill, padding: 3 },
  scope: {
    flex: 1,
    paddingVertical: spacing.sm,
    borderRadius: radius.pill,
    alignItems: 'center',
  },
  scopeLabel: { fontSize: fontSize.small, fontWeight: '600' },

  chip: {
    alignSelf: 'flex-start',
    borderWidth: 1,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm - 2,
  },
  chipLabel: { fontSize: fontSize.small, fontWeight: '600' },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    padding: spacing.md,
  },
  rowEmoji: { fontSize: 26 },
  rowTitleLine: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  rowTitle: { flexShrink: 1, fontSize: fontSize.body, fontWeight: '600' },
  strike: { textDecorationLine: 'line-through' },
  statusPill: {
    fontSize: fontSize.caption,
    fontWeight: '700',
    paddingHorizontal: spacing.sm,
    paddingVertical: 1,
    borderRadius: radius.pill,
    overflow: 'hidden',
  },
  rowMeta: { fontSize: fontSize.caption, marginTop: 1 },
  rowFacts: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md, marginTop: spacing.xs },
  fact: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  factText: { fontSize: fontSize.caption },

  badge: {
    alignSelf: 'flex-start',
    marginTop: spacing.xs,
    fontSize: fontSize.caption,
    fontWeight: '700',
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    borderRadius: radius.pill,
    overflow: 'hidden',
  },

  spacer: { marginVertical: spacing.xl },
  empty: { textAlign: 'center', marginTop: spacing.xl, fontSize: fontSize.small },
})
