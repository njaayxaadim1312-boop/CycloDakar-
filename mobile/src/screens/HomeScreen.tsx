import { StatusBar } from 'expo-status-bar'
import { useState } from 'react'
import {
  Alert,
  Image,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button } from '../components/Button'
import { useAuth, useCurrentUser } from '../stores/auth'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

interface HomeScreenProps {
  onOpenSystem: () => void
}

/**
 * Accueil de l'application connectée.
 *
 * Version de la phase 2 : elle confirme que la session tient et donne accès à
 * la déconnexion. Les tuiles d'action (démarrer une sortie, événements, scan
 * QR) arrivent avec la navigation en phase 5 ; elles sont affichées ici
 * désactivées, avec leur phase, pour que le membre voie où va l'application.
 */
export function HomeScreen({ onOpenSystem }: HomeScreenProps) {
  const { colors, isDark } = useTheme()
  const user = useCurrentUser()
  const logout = useAuth((state) => state.logout)
  const [signingOut, setSigningOut] = useState(false)

  function confirmLogout() {
    Alert.alert(
      'Se déconnecter',
      'Voulez-vous vous déconnecter de cet appareil ?',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Se déconnecter',
          style: 'destructive',
          onPress: () => {
            setSigningOut(true)
            void logout().finally(() => setSigningOut(false))
          },
        },
      ],
    )
  }

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <ScrollView contentContainerStyle={styles.scroll}>
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

        {/* --- Fiche du compte --------------------------------------------- */}
        <View
          style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
        >
          <Text style={[styles.cardTitle, { color: colors.text }]}>Mon compte</Text>

          <Row label="Nom" value={user?.name ?? '—'} colors={colors} />
          <Row
            label="Téléphone"
            value={user?.phone_formatted ?? '—'}
            colors={colors}
          />
          <Row label="Email" value={user?.email ?? 'Non renseigné'} colors={colors} />
          <Row label="Rôle" value={user?.role_label ?? '—'} colors={colors} />

          <Button
            title="Se déconnecter"
            variant="ghost"
            loading={signingOut}
            onPress={confirmLogout}
            style={styles.logoutButton}
          />
        </View>

        {/* --- Ce qui arrive ------------------------------------------------ */}
        <View
          style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
        >
          <Text style={[styles.cardTitle, { color: colors.text }]}>Prochainement</Text>
          <Text style={[styles.cardSub, { color: colors.textMuted }]}>
            Les modules arrivent phase par phase.
          </Text>

          <Upcoming emoji="🚴" label="Démarrer une sortie GPS" phase={6} colors={colors} />
          <Upcoming emoji="📊" label="Mes activités et statistiques" phase={8} colors={colors} />
          <Upcoming emoji="📅" label="Événements du club" phase={9} colors={colors} />
          <Upcoming emoji="📷" label="Scanner un QR Code membre" phase={11} colors={colors} />
          <Upcoming emoji="💰" label="Mes participations" phase={12} colors={colors} />
        </View>

        <Pressable onPress={onOpenSystem} hitSlop={8} style={styles.systemLink}>
          <Text style={[styles.systemLinkText, { color: colors.orangeText }]}>
            État du système et diagnostic →
          </Text>
        </Pressable>
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

type Colors = ReturnType<typeof useTheme>['colors']

function Row({ label, value, colors }: { label: string; value: string; colors: Colors }) {
  return (
    <View style={[styles.row, { borderTopColor: colors.border }]}>
      <Text style={[styles.rowLabel, { color: colors.textMuted }]}>{label}</Text>
      <Text style={[styles.rowValue, { color: colors.text }]} numberOfLines={1}>
        {value}
      </Text>
    </View>
  )
}

function Upcoming({
  emoji,
  label,
  phase,
  colors,
}: {
  emoji: string
  label: string
  phase: number
  colors: Colors
}) {
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

  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  cardTitle: { fontSize: fontSize.h3, fontWeight: '700' },
  cardSub: { fontSize: fontSize.small, marginBottom: spacing.xs },

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

  logoutButton: { marginTop: spacing.md },

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

  systemLink: { alignItems: 'center', paddingVertical: spacing.md },
  systemLinkText: { fontSize: fontSize.small, fontWeight: '700' },
})
