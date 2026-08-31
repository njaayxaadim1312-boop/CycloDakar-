import { StatusBar } from 'expo-status-bar'
import { useState } from 'react'
import {
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
import { ApiError } from '../../lib/api'
import { useAuth } from '../../stores/auth'
import { fontSize, radius, spacing } from '../../theme/tokens'
import { useTheme } from '../../theme/useTheme'

interface RegisterScreenProps {
  onGoToLogin: () => void
}

export function RegisterScreen({ onGoToLogin }: RegisterScreenProps) {
  const { colors, isDark } = useTheme()
  const register = useAuth((state) => state.register)

  const [form, setForm] = useState({
    name: '',
    phone: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    setError(null)
    setSubmitting(true)

    try {
      await register({
        name: form.name,
        // Champs vides envoyés à null : une chaîne vide serait stockée telle
        // quelle et casserait l'unicité au deuxième membre sans email.
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        password: form.password,
        password_confirmation: form.password_confirmation,
      })
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : null)
    } finally {
      setSubmitting(false)
    }
  }

  const hasFieldError = Boolean(
    error?.fieldError('name') ??
      error?.fieldError('phone') ??
      error?.fieldError('email') ??
      error?.fieldError('password'),
  )

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
          <Text style={[styles.title, { color: colors.text }]}>Créer un compte</Text>
          <Text style={[styles.lede, { color: colors.textMuted }]}>
            Rejoignez la plateforme du club Cyclo Dakar.
          </Text>

          {error && !hasFieldError && (
            <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
              <Text style={[styles.alertText, { color: colors.danger }]}>
                {error.message}
              </Text>
            </View>
          )}

          <View style={styles.form}>
            <Field
              label="Nom complet"
              placeholder="Awa Ndiaye"
              autoCapitalize="words"
              textContentType="name"
              value={form.name}
              onChangeText={(name) => setForm({ ...form, name })}
              error={error?.fieldError('name')}
            />

            <Field
              label="Téléphone"
              placeholder="77 123 45 67"
              keyboardType="phone-pad"
              textContentType="telephoneNumber"
              value={form.phone}
              onChangeText={(phone) => setForm({ ...form, phone })}
              error={error?.fieldError('phone')}
            />

            <Field
              label="Adresse email (facultative)"
              placeholder="awa@example.sn"
              keyboardType="email-address"
              autoCapitalize="none"
              autoCorrect={false}
              textContentType="emailAddress"
              value={form.email}
              onChangeText={(email) => setForm({ ...form, email })}
              error={error?.fieldError('email')}
              hint="Nécessaire pour réinitialiser vous-même votre mot de passe."
            />

            <Field
              label="Mot de passe"
              placeholder="••••••••"
              autoCapitalize="none"
              revealable
              textContentType="newPassword"
              value={form.password}
              onChangeText={(password) => setForm({ ...form, password })}
              error={error?.fieldError('password')}
              hint="8 caractères minimum, avec au moins une lettre et un chiffre."
            />

            <Field
              label="Confirmer le mot de passe"
              placeholder="••••••••"
              autoCapitalize="none"
              revealable
              textContentType="newPassword"
              value={form.password_confirmation}
              onChangeText={(password_confirmation) =>
                setForm({ ...form, password_confirmation })
              }
              onSubmitEditing={() => void handleSubmit()}
              returnKeyType="go"
            />

            <Button
              title="Créer mon compte"
              loading={submitting}
              onPress={() => void handleSubmit()}
            />

            <Text style={[styles.help, { color: colors.textMuted }]}>
              Indiquez au moins un téléphone ou une adresse email : c'est votre
              identifiant de connexion.
            </Text>
          </View>

          <Pressable onPress={onGoToLogin} hitSlop={8} style={styles.footerLink}>
            <Text style={[styles.footerText, { color: colors.textMuted }]}>
              Déjà un compte ?{' '}
              <Text style={{ color: colors.orangeText, fontWeight: '700' }}>
                Se connecter
              </Text>
            </Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  scroll: { padding: spacing.xl, paddingBottom: spacing.xxl, gap: spacing.md },

  title: {
    fontSize: fontSize.h1,
    fontWeight: '800',
    letterSpacing: -0.6,
    marginTop: spacing.md,
  },
  lede: { fontSize: fontSize.body, lineHeight: 21 },

  alert: { borderRadius: radius.sm, padding: spacing.md },
  alertText: { fontSize: fontSize.small, fontWeight: '600', lineHeight: 19 },

  form: { gap: spacing.lg, marginTop: spacing.sm },
  help: { fontSize: fontSize.caption, lineHeight: 17 },

  footerLink: { alignItems: 'center', paddingVertical: spacing.md },
  footerText: { fontSize: fontSize.body },
})
