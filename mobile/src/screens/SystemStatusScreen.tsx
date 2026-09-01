import { useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import {
  ActivityIndicator,
  Image,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { API_URL, getData } from '../lib/api'
import { formatFcfa } from '../lib/format'
import { SPORT_EMOJI } from '../lib/sports'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { AppConfig, Health, SportCode } from '../types/api'

/**
 * Écran de vérification de l'installation (phase 1).
 *
 * Il prouve que le téléphone atteint l'API Laravel — c'est LE point qui casse
 * le plus souvent en développement mobile (pare-feu Windows, mauvaise IP,
 * émulateur qui ne voit pas localhost). L'écran affiche l'URL réellement
 * utilisée pour rendre le diagnostic immédiat.
 *
 * Il sera remplacé par l'accueil à la phase 5.
 */

interface SystemStatusScreenProps {
  /** Fourni quand l'ecran est ouvert depuis l'accueil (utilisateur connecte). */
  onBack?: () => void
}

export function SystemStatusScreen({ onBack }: SystemStatusScreenProps = {}) {
  const { colors, isDark } = useTheme()

  const health = useQuery({
    queryKey: ['health'],
    queryFn: () => getData<Health>('/health'),
    retry: 1,
  })

  const config = useQuery({
    queryKey: ['config'],
    queryFn: () => getData<AppConfig>('/config'),
    retry: 1,
    staleTime: 5 * 60_000,
  })

  const refreshing = health.isFetching || config.isFetching

  const refetchAll = () => {
    void health.refetch()
    void config.refetch()
  }

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <ScrollView
        contentContainerStyle={styles.scroll}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={refetchAll}
            tintColor={colors.orange}
            colors={[colors.orange]}
          />
        }
      >
        {/* --- En-tête ------------------------------------------------------ */}
        <View style={styles.header}>
          <Image
            source={require('../../assets/icon.png')}
            style={styles.logo}
            accessibilityLabel="Logo Cyclo Dakar"
          />
          <View style={styles.headerText}>
            <Text style={[styles.wordmark, { color: colors.text }]}>
              CYCLO <Text style={{ color: colors.orangeText }}>DAKAR</Text>
            </Text>
            <Text style={[styles.motto, { color: colors.textMuted }]}>
              Ensemble, plus loin, plus forts
            </Text>
          </View>
        </View>

        {onBack && (
          <Pressable onPress={onBack} hitSlop={8} style={styles.back}>
            <Text style={[styles.backText, { color: colors.orangeText }]}>
              ← Retour à l'accueil
            </Text>
          </Pressable>
        )}

        <Text style={[styles.kicker, { color: colors.orangeText }]}>
          DIAGNOSTIC
        </Text>
        <Text style={[styles.title, { color: colors.text }]}>
          Connexion à la plateforme
        </Text>
        <Text style={[styles.lede, { color: colors.textMuted }]}>
          Cet écran vérifie que le téléphone atteint bien l'API du club. Tirez vers
          le bas pour relancer le test.
        </Text>

        {/* --- Connexion à l'API -------------------------------------------- */}
        <View style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}>
          <View style={styles.cardHead}>
            <Text style={[styles.cardTitle, { color: colors.text }]}>Connexion à l'API</Text>
            <StatusDot
              loading={health.isLoading}
              ok={health.isSuccess && health.data.status === 'healthy'}
              colors={colors}
            />
          </View>

          <Text style={[styles.mono, { color: colors.textMuted }]}>{API_URL}</Text>

          {health.isLoading && (
            <ActivityIndicator style={{ marginTop: spacing.md }} color={colors.orange} />
          )}

          {health.isError && (
            <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
              <Text style={[styles.alertTitle, { color: colors.danger }]}>
                Serveur injoignable
              </Text>
              <Text style={[styles.alertBody, { color: colors.text }]}>
                Vérifiez que « php artisan serve --host=0.0.0.0 » tourne sur le PC et
                que le téléphone est sur le même Wi-Fi. Sur Windows, le pare-feu doit
                autoriser le port 8000.
              </Text>
            </View>
          )}

          {health.data && (
            <View style={styles.factGrid}>
              <Fact label="Laravel" value={health.data.laravel} colors={colors} />
              <Fact label="PHP" value={health.data.php} colors={colors} />
              <Fact
                label="Base de données"
                value={health.data.checks.database.ok ? 'Connectée' : 'En échec'}
                colors={colors}
              />
              <Fact
                label="Latence"
                value={
                  health.data.checks.database.latency_ms !== undefined
                    ? `${health.data.checks.database.latency_ms} ms`
                    : '—'
                }
                colors={colors}
              />
            </View>
          )}
        </View>

        {/* --- Configuration du club ---------------------------------------- */}
        {config.data && (
          <View
            style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
          >
            <Text style={[styles.cardTitle, { color: colors.text }]}>
              Configuration du club
            </Text>
            <Text style={[styles.cardSub, { color: colors.textMuted }]}>
              Chargée depuis GET /config — mêmes valeurs que le web.
            </Text>

            {config.data.sports.map((sport) => (
              <View
                key={sport.code}
                style={[styles.sportRow, { backgroundColor: colors.surface2 }]}
              >
                <Text style={styles.sportEmoji}>{SPORT_EMOJI[sport.code]}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.sportLabel, { color: colors.text }]}>{sport.label}</Text>
                  <Text style={[styles.sportMeta, { color: colors.textMuted }]}>
                    Point GPS toutes les {sport.sample_interval_s} s · précision
                    exigée ≤ {sport.max_accuracy_m} m
                  </Text>
                </View>
              </View>
            ))}

            <View style={styles.factGrid}>
              <Fact label="Fuseau" value={config.data.club.timezone} colors={colors} />
              <Fact label="Monnaie" value={formatFcfa(5000)} colors={colors} />
              <Fact
                label="Cartographie"
                value={config.data.map.provider === 'osm' ? 'OpenStreetMap' : 'Mapbox'}
                colors={colors}
              />
              <Fact
                label="Paiements"
                value={`${config.data.payment_methods.length} moyens`}
                colors={colors}
              />
            </View>
          </View>
        )}

        {/* --- Palette ------------------------------------------------------ */}
        <View style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}>
          <Text style={[styles.cardTitle, { color: colors.text }]}>Identité visuelle</Text>
          <Text style={[styles.cardSub, { color: colors.textMuted }]}>
            Palette officielle du prototype.
          </Text>
          <View style={styles.swatchRow}>
            <Swatch color={colors.orange} name="Orange" colors={colors} />
            <Swatch color={colors.black} name="Noir" colors={colors} />
            <Swatch color={colors.blue} name="Bleu" colors={colors} />
            <Swatch color={colors.green} name="Vert" colors={colors} />
          </View>
        </View>

        <Text style={[styles.footer, { color: colors.textMuted }]}>
          Prochaine étape : membres et QR Code (phase 3), puis GPS (phase 6).
        </Text>
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function StatusDot({
  loading,
  ok,
  colors,
}: {
  loading: boolean
  ok: boolean
  colors: ReturnType<typeof useTheme>['colors']
}) {
  const color = loading ? colors.borderStrong : ok ? colors.green : colors.danger
  return (
    <View style={styles.statusDotWrap}>
      <View style={[styles.statusDot, { backgroundColor: color }]} />
      <Text style={[styles.statusDotLabel, { color: colors.textMuted }]}>
        {loading ? 'Test…' : ok ? 'OK' : 'Échec'}
      </Text>
    </View>
  )
}

function Fact({
  label,
  value,
  colors,
}: {
  label: string
  value: string
  colors: ReturnType<typeof useTheme>['colors']
}) {
  return (
    <View style={styles.fact}>
      <Text style={[styles.factLabel, { color: colors.textMuted }]}>{label.toUpperCase()}</Text>
      <Text style={[styles.factValue, { color: colors.text }]}>{value}</Text>
    </View>
  )
}

function Swatch({
  color,
  name,
  colors,
}: {
  color: string
  name: string
  colors: ReturnType<typeof useTheme>['colors']
}) {
  return (
    <View style={styles.swatch}>
      <View style={[styles.swatchBox, { backgroundColor: color, borderColor: colors.border }]} />
      <Text style={[styles.swatchName, { color: colors.textMuted }]}>{name}</Text>
    </View>
  )
}

/* -------------------------------------------------------------------------- */

const styles = StyleSheet.create({
  safe: { flex: 1 },
  scroll: { padding: spacing.lg, paddingBottom: spacing.xxl, gap: spacing.md },

  header: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  logo: { width: 52, height: 52, borderRadius: 26, backgroundColor: '#fff' },
  headerText: { flex: 1 },
  wordmark: { fontSize: fontSize.h3, fontWeight: '800', letterSpacing: -0.4 },
  motto: { fontSize: fontSize.caption, marginTop: 1 },

  kicker: {
    fontSize: fontSize.caption,
    fontWeight: '700',
    letterSpacing: 0.8,
    marginTop: spacing.sm,
  },
  title: { fontSize: fontSize.h1, fontWeight: '800', letterSpacing: -0.6, marginTop: 2 },
  lede: { fontSize: fontSize.body, lineHeight: 21 },

  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  cardHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  cardTitle: { fontSize: fontSize.h3, fontWeight: '700' },
  cardSub: { fontSize: fontSize.small },

  mono: { fontSize: fontSize.small, fontFamily: 'monospace' },

  statusDotWrap: { flexDirection: 'row', alignItems: 'center', gap: spacing.xs },
  statusDot: { width: 10, height: 10, borderRadius: 5 },
  statusDotLabel: { fontSize: fontSize.caption, fontWeight: '600' },

  alert: { borderRadius: radius.sm, padding: spacing.md, marginTop: spacing.sm, gap: 4 },
  alertTitle: { fontWeight: '700', fontSize: fontSize.small },
  alertBody: { fontSize: fontSize.small, lineHeight: 19 },

  factGrid: { flexDirection: 'row', flexWrap: 'wrap', marginTop: spacing.sm },
  fact: { width: '50%', paddingVertical: spacing.xs },
  factLabel: { fontSize: 10, letterSpacing: 0.6, fontWeight: '600' },
  factValue: { fontSize: fontSize.body, fontWeight: '700', marginTop: 1 },

  sportRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.sm,
    marginTop: spacing.sm,
  },
  sportEmoji: { fontSize: 26 },
  sportLabel: { fontSize: fontSize.body, fontWeight: '700' },
  sportMeta: { fontSize: fontSize.caption, marginTop: 1 },

  swatchRow: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
  swatch: { flex: 1, gap: 4 },
  swatchBox: { height: 48, borderRadius: radius.sm, borderWidth: StyleSheet.hairlineWidth },
  swatchName: { fontSize: fontSize.caption, textAlign: 'center' },

  footer: { fontSize: fontSize.caption, textAlign: 'center', marginTop: spacing.sm },
  back: { paddingVertical: spacing.xs },
  backText: { fontSize: fontSize.small, fontWeight: '700' },
})
