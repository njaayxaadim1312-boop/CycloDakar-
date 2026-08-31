import { useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import {
  Image,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { fetchDashboardStats } from '../lib/stats'
import { useCurrentUser } from '../stores/auth'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { MemberStatusCode } from '../types/api'

interface HomeScreenProps {
  onOpenMembers: () => void
}

/**
 * Accueil de l'application connectée.
 *
 * Même règle que sur le web : **aucun chiffre inventé**. Les effectifs sont
 * réels ; les modules à venir affichent leur phase, jamais un zéro qui
 * passerait pour une mesure.
 *
 * Le bouton « Démarrer une sortie » est le geste principal de l'application :
 * il est volontairement surdimensionné (72 dp), visé en roulant, parfois avec
 * des gants. Il arrive en phase 6 et reste ici désactivé plutôt qu'absent —
 * sa place est réservée, l'utilisateur sait où le chercher.
 */
export function HomeScreen({ onOpenMembers }: HomeScreenProps) {
  const { colors, isDark } = useTheme()
  const user = useCurrentUser()

  const stats = useQuery({
    queryKey: ['stats', 'dashboard'],
    queryFn: fetchDashboardStats,
  })

  const members = stats.data?.members

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <ScrollView
        contentContainerStyle={styles.scroll}
        refreshControl={
          <RefreshControl
            refreshing={stats.isFetching}
            onRefresh={() => void stats.refetch()}
            tintColor={colors.orange}
            colors={[colors.orange]}
          />
        }
      >
        {/* --- En-tête orange ---------------------------------------------- */}
        <View style={[styles.hero, { backgroundColor: colors.orange }]}>
          <View style={styles.heroRow}>
            <Image
              source={require('../../assets/icon.png')}
              style={styles.logo}
              accessibilityLabel="Logo Cyclo Dakar"
            />
            <View style={styles.flex}>
              <Text style={styles.heroKicker}>{user?.role_label}</Text>
              <Text style={styles.heroTitle} numberOfLines={1}>
                {/* Le prénom seul : plus chaleureux, et plus court sur mobile. */}
                Bonjour {user?.name.split(' ')[0]}
              </Text>
            </View>
          </View>
          <Text style={styles.heroMotto}>Ensemble, plus loin, plus forts !</Text>
        </View>

        {/* --- Action principale ------------------------------------------- */}
        <Pressable
          disabled
          accessibilityRole="button"
          accessibilityState={{ disabled: true }}
          style={[styles.startButton, { backgroundColor: colors.disabledBg }]}
        >
          <Text style={[styles.startLabel, { color: colors.disabledText }]}>
            Démarrer une sortie
          </Text>
          <Text style={[styles.startHint, { color: colors.disabledText }]}>
            Enregistrement GPS · phase 6
          </Text>
        </Pressable>

        {/* --- Effectif du club --------------------------------------------- */}
        <View
          style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
        >
          <View style={styles.cardHead}>
            <Text style={[styles.cardTitle, { color: colors.text }]}>Le club</Text>
            <Pressable onPress={onOpenMembers} hitSlop={8}>
              <Text style={[styles.link, { color: colors.orangeText }]}>Annuaire →</Text>
            </Pressable>
          </View>

          {stats.isLoading && (
            <Text style={[styles.muted, { color: colors.textMuted }]}>Chargement…</Text>
          )}

          {stats.isError && (
            <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
              <Text style={[styles.alertText, { color: colors.danger }]}>
                Statistiques indisponibles. Vérifiez votre connexion.
              </Text>
            </View>
          )}

          {members && (
            <>
              <View style={styles.statRow}>
                <Stat value={members.active} label="membres actifs" primary />
                <Stat value={members.joined_this_month} label="ce mois-ci" />
                <Stat value={members.without_account} label="sans compte" />
              </View>

              <View style={styles.breakdown}>
                {(
                  Object.entries(members.by_status) as [
                    MemberStatusCode,
                    { label: string; count: number },
                  ][]
                )
                  .filter(([, entry]) => entry.count > 0)
                  .map(([code, entry]) => (
                    <View key={code} style={styles.breakdownRow}>
                      <View
                        style={[styles.dot, { backgroundColor: statusColor(code, colors) }]}
                      />
                      <Text style={[styles.breakdownLabel, { color: colors.text }]}>
                        {entry.label}
                      </Text>
                      <Text style={[styles.breakdownCount, { color: colors.textMuted }]}>
                        {entry.count}
                      </Text>
                    </View>
                  ))}
              </View>
            </>
          )}
        </View>

        {/* --- Ce qui arrive ------------------------------------------------ */}
        <View
          style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
        >
          <Text style={[styles.cardTitle, { color: colors.text }]}>Prochainement</Text>
          <Text style={[styles.cardSub, { color: colors.textMuted }]}>
            Les modules arrivent phase par phase.
          </Text>

          <Upcoming emoji="🚴" label="Enregistrement GPS des sorties" phase={6} />
          <Upcoming emoji="🗺️" label="Carte et statistiques du parcours" phase={7} />
          <Upcoming emoji="📅" label="Événements du club" phase={9} />
          <Upcoming emoji="📷" label="Scanner un QR Code membre" phase={11} />
          <Upcoming emoji="💰" label="Mes participations" phase={12} />
        </View>
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

type Colors = ReturnType<typeof useTheme>['colors']

function statusColor(status: MemberStatusCode, colors: Colors): string {
  return {
    ACTIVE: colors.green,
    PENDING: colors.warning,
    SUSPENDED: colors.danger,
    FORMER: colors.borderStrong,
  }[status]
}

function Stat({
  value,
  label,
  primary,
}: {
  value: number
  label: string
  primary?: boolean
}) {
  const { colors } = useTheme()

  return (
    <View style={styles.stat}>
      <Text
        style={[
          styles.statValue,
          { color: primary ? colors.orangeText : colors.text },
        ]}
      >
        {value}
      </Text>
      <Text style={[styles.statLabel, { color: colors.textMuted }]}>{label}</Text>
    </View>
  )
}

function Upcoming({
  emoji,
  label,
  phase,
}: {
  emoji: string
  label: string
  phase: number
}) {
  const { colors } = useTheme()

  return (
    <View style={[styles.upcoming, { backgroundColor: colors.surface2 }]}>
      <Text style={styles.upcomingEmoji}>{emoji}</Text>
      <Text style={[styles.upcomingLabel, { color: colors.text }]}>{label}</Text>
      <Text style={[styles.upcomingPhase, { color: colors.textMuted }]}>P{phase}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  scroll: { padding: spacing.lg, paddingBottom: spacing.xxl, gap: spacing.md },

  hero: { borderRadius: radius.lg, padding: spacing.lg, gap: spacing.sm },
  heroRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  logo: { width: 48, height: 48, borderRadius: 24, backgroundColor: '#fff' },
  heroKicker: {
    fontSize: fontSize.caption,
    fontWeight: '700',
    letterSpacing: 0.8,
    color: 'rgba(0,0,0,0.6)',
    textTransform: 'uppercase',
  },
  heroTitle: { fontSize: fontSize.h2, fontWeight: '800', color: '#1A1A1A' },
  heroMotto: { fontSize: fontSize.small, color: 'rgba(0,0,0,0.7)' },

  // 72 dp : la cible du geste principal, visée en roulant.
  startButton: {
    minHeight: 72,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
  },
  startLabel: { fontSize: fontSize.h3, fontWeight: '800' },
  startHint: { fontSize: fontSize.caption },

  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  cardHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  cardTitle: { fontSize: fontSize.h3, fontWeight: '700' },
  cardSub: { fontSize: fontSize.small, marginBottom: spacing.xs },
  link: { fontSize: fontSize.small, fontWeight: '700' },
  muted: { fontSize: fontSize.small, marginTop: spacing.sm },

  alert: { borderRadius: radius.sm, padding: spacing.md, marginTop: spacing.sm },
  alertText: { fontSize: fontSize.small, fontWeight: '600', lineHeight: 19 },

  statRow: { flexDirection: 'row', marginTop: spacing.md },
  stat: { flex: 1, alignItems: 'center', gap: 2 },
  statValue: { fontSize: fontSize.h1, fontWeight: '800' },
  statLabel: { fontSize: fontSize.caption, textAlign: 'center' },

  breakdown: { marginTop: spacing.lg, gap: spacing.sm },
  breakdownRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  dot: { width: 8, height: 8, borderRadius: 4 },
  breakdownLabel: { flex: 1, fontSize: fontSize.small },
  breakdownCount: { fontSize: fontSize.small, fontWeight: '700' },

  upcoming: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.sm,
    marginTop: spacing.sm,
  },
  upcomingEmoji: { fontSize: 20 },
  upcomingLabel: { flex: 1, fontSize: fontSize.body, fontWeight: '600' },
  upcomingPhase: { fontSize: fontSize.caption, fontWeight: '700' },
})
