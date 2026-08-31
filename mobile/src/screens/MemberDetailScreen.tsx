import { useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Avatar } from '../components/Avatar'
import { MemberStatusBadge, NoAccountBadge, RoleBadge } from '../components/Badge'
import { ApiError } from '../lib/api'
import { fetchMember } from '../lib/members'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

interface MemberDetailScreenProps {
  uuid: string
  onBack: () => void
}

/**
 * Fiche d'un membre.
 *
 * Le contenu dépend de qui regarde : le serveur omet les coordonnées pour un
 * membre ordinaire consultant la fiche d'un autre. On ne teste donc pas
 * `=== null` mais la présence du champ — un champ absent signifie « pas le
 * droit de voir », un champ `null` signifie « non renseigné ».
 */
export function MemberDetailScreen({ uuid, onBack }: MemberDetailScreenProps) {
  const { colors, isDark } = useTheme()

  const query = useQuery({
    queryKey: ['member', uuid],
    queryFn: () => fetchMember(uuid),
  })

  const member = query.data

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <Pressable onPress={onBack} hitSlop={12} style={styles.back}>
        <Text style={[styles.backText, { color: colors.orangeText }]}>← Membres</Text>
      </Pressable>

      {query.isLoading && (
        <ActivityIndicator style={styles.spinner} color={colors.orange} />
      )}

      {query.isError && (
        <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
          <Text style={[styles.alertText, { color: colors.danger }]}>
            {query.error instanceof ApiError && query.error.status === 404
              ? 'Ce membre est introuvable.'
              : "La fiche n'a pas pu être chargée."}
          </Text>
        </View>
      )}

      {member && (
        <ScrollView contentContainerStyle={styles.scroll}>
          <View
            style={[
              styles.card,
              { backgroundColor: colors.surface, borderColor: colors.border },
            ]}
          >
            <View style={styles.identity}>
              <Avatar photoUrl={member.photo_url} initials={member.initials} size={72} />
              <View style={styles.flex}>
                <Text style={[styles.name, { color: colors.text }]}>
                  {member.full_name}
                </Text>
                <Text style={[styles.matricule, { color: colors.orangeText }]}>
                  {member.matricule}
                </Text>
              </View>
            </View>

            <View style={styles.badges}>
              <MemberStatusBadge status={member.status} label={member.status_label} />
              {member.account ? (
                <RoleBadge role={member.account.role} label={member.account.role_label} />
              ) : (
                <NoAccountBadge />
              )}
            </View>

            {/* `undefined` = pas le droit de voir. On n'affiche alors rien
                plutôt qu'un tiret, qui laisserait croire à un champ vide. */}
            {member.phone_formatted !== undefined && (
              <Row label="Téléphone" value={member.phone_formatted ?? '—'} />
            )}
            {member.email !== undefined && (
              <Row label="Email" value={member.email ?? 'Non renseigné'} />
            )}
            {member.joined_at && (
              <Row
                label="Membre depuis"
                value={
                  new Date(member.joined_at).toLocaleDateString('fr-FR', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric',
                  }) +
                  (member.seniority_years > 0
                    ? ` · ${member.seniority_years} an${member.seniority_years > 1 ? 's' : ''}`
                    : '')
                }
              />
            )}
          </View>

          {!member.has_account && (
            <View style={[styles.note, { backgroundColor: colors.surface2 }]}>
              <Text style={[styles.noteText, { color: colors.textMuted }]}>
                Ce membre n'a pas de compte de connexion. Il figure dans l'effectif
                et dans les collectes ; son QR Code peut lui être remis imprimé.
              </Text>
            </View>
          )}

          {member.emergency_contact_name && (
            <View
              style={[
                styles.card,
                { backgroundColor: colors.surface, borderColor: colors.border },
              ]}
            >
              <Text style={[styles.cardTitle, { color: colors.text }]}>
                Contact d'urgence
              </Text>
              <Row
                label={member.emergency_contact_name}
                value={member.emergency_contact_phone ?? '—'}
              />
            </View>
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Row({ label, value }: { label: string; value: string }) {
  const { colors } = useTheme()

  return (
    <View style={[styles.row, { borderTopColor: colors.border }]}>
      <Text style={[styles.rowLabel, { color: colors.textMuted }]}>{label}</Text>
      <Text style={[styles.rowValue, { color: colors.text }]} numberOfLines={1}>
        {value}
      </Text>
    </View>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },

  back: { paddingHorizontal: spacing.lg, paddingVertical: spacing.md },
  backText: { fontSize: fontSize.small, fontWeight: '700' },

  spinner: { marginTop: spacing.xl },

  alert: {
    marginHorizontal: spacing.lg,
    borderRadius: radius.sm,
    padding: spacing.md,
  },
  alertText: { fontSize: fontSize.small, fontWeight: '600', lineHeight: 19 },

  scroll: { padding: spacing.lg, paddingTop: 0, gap: spacing.md },

  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.lg,
  },
  cardTitle: { fontSize: fontSize.h3, fontWeight: '700', marginBottom: spacing.xs },

  identity: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  name: { fontSize: fontSize.h2, fontWeight: '800' },
  matricule: { fontSize: fontSize.small, fontWeight: '700', marginTop: 2 },

  badges: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginTop: spacing.md,
    marginBottom: spacing.xs,
  },

  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
  },
  rowLabel: { fontSize: fontSize.small },
  rowValue: { fontSize: fontSize.body, fontWeight: '600', flexShrink: 1 },

  note: { borderRadius: radius.sm, padding: spacing.md },
  noteText: { fontSize: fontSize.small, lineHeight: 19 },
})
