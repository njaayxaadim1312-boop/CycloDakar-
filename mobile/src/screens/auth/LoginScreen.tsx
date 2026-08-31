import { StatusBar } from 'expo-status-bar'
import { useState } from 'react'
import {
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button } from '../../components/Button'
import { Field } from '../../components/Field'
import { API_URL, ApiError } from '../../lib/api'
import { useAuth } from '../../stores/auth'
import { fontSize, radius, spacing } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'

interface LoginScreenProps {
  onGoToRegister: () => void
}

export function LoginScreen({ onGoToRegister }: LoginScreenProps) {
  const { colors, isDark } = useTheme()
  const login = useAuth((state) => state.login)

  const [form, setForm] = useState({ login: '', password: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    setError(null)
    setSubmitting(true)

    try {
      await login(form)
      // Pas de navigation ici : l'arbre d'écrans bascule tout seul dès que la
      // session existe (voir App.tsx).
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : null)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
        >
          <View style={styles.header}>
            <Image
              source={require('../../../assets/icon.png')}
              style={styles.logo}
              accessibilityLabel="Logo Cyclo Dakar"
            />
            <Text style={[styles.wordmark, { color: colors.text }]}>
              CYCLO <Text style={{ color: colors.orangeText }}>DAKAR</Text>
            </Text>
            <Text style={[styles.motto, { color: colors.textMuted }]}>
              Ensemble, plus loin, plus forts !
            </Text>
          </View>

          <Text style={[styles.title, { color: colors.text }]}>Connexion</Text>

          {error && !error.fieldError('login') && (
            <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
              <Text style={[styles.alertText, { color: colors.danger }]}>
                {error.message}
              </Text>
            </View>
          )}

          <View style={styles.form}>
            <Field
              label="Téléphone ou email"
              placeholder="77 123 45 67"
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="default"
              textContentType="username"
              value={form.login}
              onChangeText={(login) => setForm({ ...form, login })}
              error={error?.fieldError('login')}
              hint="Votre numéro dans n'importe quel format."
            />

            <Field
              label="Mot de passe"
              placeholder="••••••••"
              autoCapitalize="none"
              autoCorrect={false}
              revealable
              textContentType="password"
              value={form.password}
              onChangeText={(password) => setForm({ ...form, password })}
              error={error?.fieldError('password')}
              onSubmitEditing={() => void handleSubmit()}
              returnKeyType="go"
            />

            <Button
              title="Se connecter"
              loading={submitting}
              onPress={() => void handleSubmit()}
              style={styles.submit}
            />
          </View>

          <Pressable onPress={onGoToRegister} hitSlop={8} style={styles.footerLink}>
            <Text style={[styles.footerText, { color: colors.textMuted }]}>
              Pas encore de compte ?{' '}
              <Text style={{ color: colors.orangeText, fontWeight: '700' }}>
                Créer un compte
              </Text>
            </Text>
          </Pressable>

          <Text style={[styles.help, { color: colors.textMuted }]}>
            Mot de passe oublié ? Ouvrez « Mot de passe oublié » depuis le site du
            club, ou contactez un responsable.
          </Text>

          {/* Diagnostic : la première cause de blocage en développement est une
              mauvaise adresse de serveur. On l'affiche donc franchement. */}
          {__DEV__ && (
            <Text style={[styles.debug, { color: colors.textMuted }]}>
              API : {API_URL}
            </Text>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  scroll: { padding: spacing.xl, paddingBottom: spacing.xxl, gap: spacing.lg },

  header: { alignItems: 'center', gap: 4, marginTop: spacing.lg },
  logo: { width: 84, height: 84, borderRadius: 42, backgroundColor: '#fff' },
  wordmark: {
    fontSize: fontSize.h2,
    fontWeight: '800',
    letterSpacing: -0.5,
    marginTop: spacing.sm,
  },
  motto: { fontSize: fontSize.small },

  title: { fontSize: fontSize.h1, fontWeight: '800', letterSpacing: -0.6 },

  alert: { borderRadius: radius.sm, padding: spacing.md },
  alertText: { fontSize: fontSize.small, fontWeight: '600', lineHeight: 19 },

  form: { gap: spacing.lg },
  submit: { marginTop: spacing.xs },

  footerLink: { alignItems: 'center', paddingVertical: spacing.sm },
  footerText: { fontSize: fontSize.body },

  help: { fontSize: fontSize.caption, textAlign: 'center', lineHeight: 17 },
  debug: { fontSize: 11, textAlign: 'center', fontFamily: 'monospace' },
})
