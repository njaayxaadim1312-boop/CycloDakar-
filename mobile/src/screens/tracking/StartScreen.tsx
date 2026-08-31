import { StatusBar } from 'expo-status-bar'
import { useEffect, useState } from 'react'
import { Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button } from '../../components/Button'
import { useTracking } from '../../stores/tracking'
import { fontSize, radius, spacing, touch } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'
import type { SportCode } from '../../types/api'

interface StartScreenProps {
  onStarted: () => void
}

const SPORTS: { code: SportCode; label: string; emoji: string; hint: string }[] = [
  { code: 'CYCLING', label: 'Cyclisme', emoji: '🚴', hint: 'Vitesse en km/h · point GPS chaque seconde' },
  { code: 'RUNNING', label: 'Course', emoji: '🏃', hint: 'Allure en min/km · point GPS chaque seconde' },
  { code: 'HIKING', label: 'Randonnée', emoji: '🥾', hint: 'Allure en min/km · point toutes les 3 s' },
]

/**
 * Choix du sport et démarrage.
 *
 * L'explication des autorisations vient AVANT la demande système. Un membre à
 * qui l'on demande « autoriser la position en permanence ? » sans contexte
 * refuse — et se retrouve avec une application qui s'arrête dès qu'il éteint
 * son écran, sans comprendre pourquoi.
 */
export function StartScreen({ onStarted }: StartScreenProps) {
  const { colors, isDark } = useTheme()
  const { permission, checkPermissions, requestPermissions, start, starting, error } =
    useTracking()

  const [sport, setSport] = useState<SportCode>('CYCLING')

  useEffect(() => {
    void checkPermissions()
  }, [checkPermissions])

  async function handleStart() {
    if (permission === 'unknown' || permission === 'denied') {
      const granted = await requestPermissions()
      if (granted === 'denied') return
    }

    if (await start(sport)) {
      onStarted()
    }
  }

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <ScrollView contentContainerStyle={styles.scroll}>
        <Text style={[styles.title, { color: colors.text }]}>Nouvelle sortie</Text>
        <Text style={[styles.lede, { color: colors.textMuted }]}>
          Choisissez votre activité. Le parcours sera enregistré même écran éteint.
        </Text>

        {/* --- Choix du sport ---------------------------------------------- */}
        <View style={styles.sports}>
          {SPORTS.map((option) => {
            const active = sport === option.code

            return (
              <Pressable
                key={option.code}
                onPress={() => setSport(option.code)}
                accessibilityRole="radio"
                accessibilityState={{ selected: active }}
                accessibilityLabel={option.label}
                style={[
                  styles.sport,
                  {
                    backgroundColor: active ? colors.orangeSoft : colors.surface,
                    borderColor: active ? colors.orange : colors.border,
                    borderWidth: active ? 2 : StyleSheet.hairlineWidth,
                  },
                ]}
              >
                <Text style={styles.sportEmoji}>{option.emoji}</Text>
                <View style={styles.flex}>
                  <Text style={[styles.sportLabel, { color: colors.text }]}>
                    {option.label}
                  </Text>
                  <Text style={[styles.sportHint, { color: colors.textMuted }]}>
                    {option.hint}
                  </Text>
                </View>
              </Pressable>
            )
          })}
        </View>

        {/* --- Autorisations ------------------------------------------------ */}
        {permission === 'foreground-only' && (
          <View style={[styles.notice, { backgroundColor: colors.warningSoft }]}>
            <Text style={[styles.noticeTitle, { color: colors.warning }]}>
              Enregistrement limité
            </Text>
            <Text style={[styles.noticeBody, { color: colors.text }]}>
              La position n'est autorisée que lorsque l'application est ouverte. Votre
              sortie s'arrêtera dès que vous éteindrez l'écran. Autorisez « Toujours »
              dans les réglages pour enregistrer un parcours complet.
            </Text>
            <Pressable onPress={() => void Linking.openSettings()} hitSlop={8}>
              <Text style={[styles.noticeLink, { color: colors.orangeText }]}>
                Ouvrir les réglages →
              </Text>
            </Pressable>
          </View>
        )}

        {permission === 'denied' && (
          <View style={[styles.notice, { backgroundColor: colors.dangerSoft }]}>
            <Text style={[styles.noticeTitle, { color: colors.danger }]}>
              Position refusée
            </Text>
            <Text style={[styles.noticeBody, { color: colors.text }]}>
              Sans accès à votre position, aucun parcours ne peut être enregistré.
            </Text>
            <Pressable onPress={() => void Linking.openSettings()} hitSlop={8}>
              <Text style={[styles.noticeLink, { color: colors.orangeText }]}>
                Ouvrir les réglages →
              </Text>
            </Pressable>
          </View>
        )}

        {/* Explication AVANT la demande système : c'est ce qui fait la
            différence entre un « Toujours autoriser » et un refus. */}
        {(permission === 'unknown' || permission === 'granted') && (
          <View style={[styles.notice, { backgroundColor: colors.surface2 }]}>
            <Text style={[styles.noticeTitle, { color: colors.text }]}>
              Pourquoi la position en arrière-plan ?
            </Text>
            <Text style={[styles.noticeBody, { color: colors.textMuted }]}>
              Pour continuer à tracer votre parcours quand vous rangez votre téléphone
              ou que l'écran s'éteint. Sans cette autorisation, l'enregistrement
              s'arrêterait au bout de quelques secondes de poche.
              {'\n\n'}
              Votre position n'est utilisée que pendant une sortie, et n'est partagée
              avec personne d'autre que le club — et seulement si vous le choisissez.
            </Text>
          </View>
        )}

        {error && (
          <View style={[styles.notice, { backgroundColor: colors.dangerSoft }]}>
            <Text style={[styles.noticeBody, { color: colors.danger }]}>{error}</Text>
          </View>
        )}
      </ScrollView>

      {/* --- Bouton principal ---------------------------------------------- */}
      <View style={[styles.footer, { borderTopColor: colors.border }]}>
        <Button
          title="Démarrer"
          large
          loading={starting}
          disabled={permission === 'denied'}
          onPress={() => void handleStart()}
        />
      </View>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  scroll: { padding: spacing.lg, gap: spacing.md },

  title: { fontSize: fontSize.h1, fontWeight: '800', letterSpacing: -0.6 },
  lede: { fontSize: fontSize.body, lineHeight: 21 },

  sports: { gap: spacing.sm, marginTop: spacing.sm },
  sport: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.lg,
    borderRadius: radius.md,
    minHeight: touch.min + 20,
  },
  sportEmoji: { fontSize: 32 },
  sportLabel: { fontSize: fontSize.h3, fontWeight: '700' },
  sportHint: { fontSize: fontSize.caption, marginTop: 2 },

  notice: { borderRadius: radius.md, padding: spacing.lg, gap: spacing.xs },
  noticeTitle: { fontSize: fontSize.body, fontWeight: '700' },
  noticeBody: { fontSize: fontSize.small, lineHeight: 20 },
  noticeLink: { fontSize: fontSize.small, fontWeight: '700', marginTop: spacing.xs },

  footer: {
    padding: spacing.lg,
    borderTopWidth: StyleSheet.hairlineWidth,
  },
})
