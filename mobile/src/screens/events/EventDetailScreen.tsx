import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { CalendarClock, ChevronLeft, MapPin, Route, Users } from 'lucide-react-native'
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Avatar } from '../../components/Avatar'
import { Button } from '../../components/Button'
import { ApiError } from '../../lib/api'
import { cancelRegistration, fetchEvent, registerToEvent } from '../../lib/events'
import { formatDateTime, formatDistance } from '../../lib/format'
import { fontSize, radius, spacing } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'
import type { ClubEvent, EventParticipant } from '../../types/api'

interface EventDetailScreenProps {
  uuid: string
  onBack: () => void
}

/**
 * Fiche d'une sortie sur téléphone.
 *
 * L'inscription est le geste principal : le bouton est en 72 dp, comme celui
 * de l'enregistrement GPS — on le vise parfois debout, à côté du vélo.
 *
 * Le pointage des présences n'est PAS ici. REPORTÉ À LA PHASE 11 : il se fera
 * par scan du QR Code du membre, ce qui est le geste réel du jour J. Pointer
 * cinquante membres en les cherchant dans une liste sur un téléphone serait
 * inutilisable au départ d'une sortie.
 */
export function EventDetailScreen({ uuid, onBack }: EventDetailScreenProps) {
  const { colors, isDark } = useTheme()
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['event', uuid],
    queryFn: () => fetchEvent(uuid),
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['event', uuid] })
    void queryClient.invalidateQueries({ queryKey: ['events'] })
    void queryClient.invalidateQueries({ queryKey: ['stats', 'dashboard'] })
  }

  const register = useMutation({
    mutationFn: () => registerToEvent(uuid),
    onSuccess: refresh,
  })

  const unregister = useMutation({
    mutationFn: () => cancelRegistration(uuid),
    onSuccess: refresh,
  })

  const event = query.data
  const busy = register.isPending || unregister.isPending

  const actionError = [register.error, unregister.error].find(
    (error): error is ApiError => error instanceof ApiError,
  )

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <View style={[styles.header, { borderBottomColor: colors.border }]}>
        <Pressable onPress={onBack} hitSlop={12} accessibilityLabel="Retour">
          <ChevronLeft color={colors.text} size={24} />
        </Pressable>
        <Text style={[styles.headerTitle, { color: colors.text }]} numberOfLines={1}>
          {event?.title ?? 'Sortie'}
        </Text>
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {query.isLoading && <ActivityIndicator color={colors.orange} style={styles.spacer} />}

        {query.isError && (
          <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
            <Text style={[styles.alertText, { color: colors.danger }]}>
              Cette sortie est introuvable, ou votre connexion est coupée.
            </Text>
          </View>
        )}

        {event !== undefined && (
          <>
            <View
              style={[
                styles.card,
                { backgroundColor: colors.surface, borderColor: colors.border },
              ]}
            >
              <Text style={[styles.sport, { color: colors.textMuted }]}>
                {event.sport_label}
                {event.status !== 'PUBLISHED' && ` · ${event.status_label}`}
              </Text>

              <Detail icon={CalendarClock} label="Départ">
                {event.starts_at !== null ? formatDateTime(event.starts_at) : 'À préciser'}
              </Detail>
              <Detail icon={MapPin} label="Rendez-vous">
                {event.location_name}
              </Detail>
              {event.planned_distance_m !== null && (
                <Detail icon={Route} label="Distance prévue">
                  {formatDistance(event.planned_distance_m)}
                </Detail>
              )}
              <Detail icon={Users} label="Inscrits">
                {event.max_participants !== null
                  ? `${event.seats_taken} / ${event.max_participants}`
                  : `${event.seats_taken}`}
              </Detail>

              {event.difficulty_label !== null && (
                <Text style={[styles.difficulty, { color: colors.textMuted }]}>
                  <Text style={{ color: colors.text, fontWeight: '600' }}>
                    {event.difficulty_label}
                  </Text>
                  {event.difficulty_hint !== null && ` — ${event.difficulty_hint}`}
                </Text>
              )}

              {event.description !== null && event.description !== '' && (
                <Text style={[styles.description, { color: colors.text }]}>
                  {event.description}
                </Text>
              )}
            </View>

            {actionError !== undefined && (
              <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
                <Text style={[styles.alertText, { color: colors.danger }]}>
                  {actionError.message}
                </Text>
              </View>
            )}

            <Registration
              event={event}
              busy={busy}
              onRegister={() => register.mutate()}
              onCancel={() => unregister.mutate()}
            />

            <Participants event={event} />
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Detail({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof MapPin
  label: string
  children: React.ReactNode
}) {
  const { colors } = useTheme()

  return (
    <View style={styles.detail}>
      <Icon color={colors.textMuted} size={16} />
      <View style={styles.flex}>
        <Text style={[styles.detailLabel, { color: colors.textMuted }]}>{label}</Text>
        <Text style={[styles.detailValue, { color: colors.text }]}>{children}</Text>
      </View>
    </View>
  )
}

/**
 * « Je participe » / « Je me désiste ».
 *
 * Le libellé dit ce qui va réellement se passer : sur une sortie complète, le
 * bouton propose la liste d'attente. Un membre ne doit pas découvrir après
 * coup qu'il n'a pas de place.
 */
function Registration({
  event,
  busy,
  onRegister,
  onCancel,
}: {
  event: ClubEvent
  busy: boolean
  onRegister: () => void
  onCancel: () => void
}) {
  const { colors } = useTheme()
  const mine = event.my_registration

  if (!event.registrations_open && mine === null) {
    return (
      <Text style={[styles.closed, { color: colors.textMuted }]}>
        Les inscriptions sont fermées pour cette sortie.
      </Text>
    )
  }

  if (mine === null) {
    return (
      <View style={styles.actionBlock}>
        <Button
          title={event.is_full ? "Rejoindre la liste d'attente" : 'Je participe'}
          onPress={onRegister}
          disabled={busy || !event.registrations_open}
          loading={busy}
          large
        />
        {event.is_full && (
          <Text style={[styles.hint, { color: colors.textMuted }]}>
            La sortie est complète, mais une place se libère souvent.
          </Text>
        )}
      </View>
    )
  }

  return (
    <View style={styles.actionBlock}>
      <Text
        style={[
          styles.myStatus,
          mine.status === 'WAITLIST'
            ? { color: colors.warning }
            : { color: colors.greenHover },
        ]}
      >
        {mine.status === 'WAITLIST' && mine.queue_position !== null
          ? `Vous êtes ${mine.queue_position}ᵉ sur la liste d'attente.`
          : 'Vous êtes inscrit à cette sortie.'}
      </Text>

      <Button title="Je me désiste" onPress={onCancel} disabled={busy} variant="ghost" />
    </View>
  )
}

/**
 * Qui vient.
 *
 * Inscrits et liste d'attente séparés : confondre « a une place » et « attend
 * une place » est précisément ce qui fait qu'un membre se déplace pour rien.
 */
function Participants({ event }: { event: ClubEvent }) {
  const { colors } = useTheme()
  const participants = event.participants ?? []
  const registered = participants.filter((p) => p.registration_status === 'REGISTERED')
  const waiting = participants.filter((p) => p.registration_status === 'WAITLIST')

  return (
    <View
      style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
    >
      <Text style={[styles.cardTitle, { color: colors.text }]}>
        Participants{' '}
        <Text style={{ color: colors.textMuted, fontWeight: '400' }}>
          {registered.length} inscrit{registered.length > 1 ? 's' : ''}
          {waiting.length > 0 && ` · ${waiting.length} en attente`}
        </Text>
      </Text>

      {participants.length === 0 && (
        <Text style={[styles.hint, { color: colors.textMuted }]}>
          Personne n'est encore inscrit. Soyez le premier.
        </Text>
      )}

      {registered.map((participant) => (
        <ParticipantRow key={participant.member?.uuid} participant={participant} />
      ))}

      {waiting.length > 0 && (
        <>
          <Text style={[styles.groupTitle, { color: colors.textMuted }]}>
            LISTE D'ATTENTE
          </Text>
          {waiting.map((participant) => (
            <ParticipantRow key={participant.member?.uuid} participant={participant} />
          ))}
        </>
      )}
    </View>
  )
}

function ParticipantRow({ participant }: { participant: EventParticipant }) {
  const { colors } = useTheme()
  const member = participant.member

  if (member === undefined) {
    return null
  }

  return (
    <View style={styles.participant}>
      <Avatar initials={member.initials} photoUrl={member.photo_url} size={34} />
      <View style={styles.flex}>
        <Text style={[styles.participantName, { color: colors.text }]} numberOfLines={1}>
          {member.full_name}
        </Text>
        <Text style={[styles.participantMeta, { color: colors.textMuted }]}>
          {member.matricule}
          {participant.queue_position !== null && ` · ${participant.queue_position}ᵉ`}
          {/* « Non pointé » n'est pas « absent » : on ne l'affiche pas. */}
          {participant.attendance_status !== 'UNKNOWN' &&
            ` · ${participant.attendance_status_label}`}
        </Text>
      </View>
    </View>
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
  headerTitle: { flex: 1, fontSize: fontSize.h3, fontWeight: '700' },

  scroll: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
  spacer: { marginVertical: spacing.xl },

  card: {
    borderRadius: radius.md,
    borderWidth: 1,
    padding: spacing.lg,
    gap: spacing.md,
  },
  cardTitle: { fontSize: fontSize.body, fontWeight: '700' },
  sport: { fontSize: fontSize.small, fontWeight: '600' },

  detail: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  detailLabel: { fontSize: fontSize.caption },
  detailValue: { fontSize: fontSize.body, fontWeight: '600', marginTop: 1 },

  difficulty: { fontSize: fontSize.small, lineHeight: 19 },
  description: { fontSize: fontSize.small, lineHeight: 20 },

  actionBlock: { gap: spacing.sm },
  myStatus: { fontSize: fontSize.body, fontWeight: '700', textAlign: 'center' },
  hint: { fontSize: fontSize.small, textAlign: 'center', lineHeight: 18 },
  closed: { fontSize: fontSize.small, textAlign: 'center', paddingVertical: spacing.lg },

  groupTitle: {
    fontSize: fontSize.caption,
    fontWeight: '700',
    letterSpacing: 0.6,
    marginTop: spacing.sm,
  },
  participant: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  participantName: { fontSize: fontSize.small, fontWeight: '600' },
  participantMeta: { fontSize: fontSize.caption, marginTop: 1 },

  alert: { borderRadius: radius.md, padding: spacing.lg },
  alertText: { fontSize: fontSize.small },
})
