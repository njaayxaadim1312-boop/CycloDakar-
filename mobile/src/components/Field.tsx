import { useState } from 'react'
import {
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
} from 'react-native'
import { fontSize, radius, spacing, touch } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

interface FieldProps extends TextInputProps {
  label: string
  /** Message d'erreur renvoyé par l'API pour ce champ. */
  error?: string
  hint?: string
  /** Ajoute un bouton « Afficher / Masquer » sur un champ mot de passe. */
  revealable?: boolean
}

/**
 * Champ de saisie.
 *
 * Hauteur volontairement généreuse (48 dp minimum) : les formulaires sont
 * souvent remplis dehors, debout, parfois d'une seule main.
 */
export function Field({
  label,
  error,
  hint,
  revealable,
  secureTextEntry,
  style,
  ...props
}: FieldProps) {
  const { colors } = useTheme()
  const [focused, setFocused] = useState(false)
  const [revealed, setRevealed] = useState(false)

  const borderColor = error
    ? colors.danger
    : focused
      ? colors.orange
      : colors.borderStrong

  return (
    <View style={styles.wrap}>
      <Text style={[styles.label, { color: colors.text }]}>{label}</Text>

      <View>
        <TextInput
          {...props}
          secureTextEntry={revealable ? !revealed : secureTextEntry}
          onFocus={(e) => {
            setFocused(true)
            props.onFocus?.(e)
          }}
          onBlur={(e) => {
            setFocused(false)
            props.onBlur?.(e)
          }}
          placeholderTextColor={colors.textMuted}
          accessibilityLabel={label}
          style={[
            styles.input,
            {
              borderColor,
              backgroundColor: colors.surface,
              color: colors.text,
              paddingRight: revealable ? 84 : spacing.md,
            },
            style,
          ]}
        />

        {revealable && (
          <Pressable
            onPress={() => setRevealed((v) => !v)}
            style={styles.reveal}
            hitSlop={8}
            accessibilityRole="button"
            accessibilityLabel={revealed ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
          >
            <Text style={[styles.revealText, { color: colors.orangeText }]}>
              {revealed ? 'Masquer' : 'Afficher'}
            </Text>
          </Pressable>
        )}
      </View>

      {hint && !error && (
        <Text style={[styles.hint, { color: colors.textMuted }]}>{hint}</Text>
      )}

      {error && (
        <Text style={[styles.error, { color: colors.danger }]} accessibilityRole="alert">
          {error}
        </Text>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: 6 },
  label: { fontSize: fontSize.small, fontWeight: '700' },
  input: {
    minHeight: touch.min,
    borderWidth: 1,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.md,
    fontSize: fontSize.body,
  },
  reveal: {
    position: 'absolute',
    right: spacing.md,
    top: 0,
    bottom: 0,
    justifyContent: 'center',
  },
  revealText: { fontSize: fontSize.small, fontWeight: '700' },
  hint: { fontSize: fontSize.caption, lineHeight: 16 },
  error: { fontSize: fontSize.caption, fontWeight: '600', lineHeight: 16 },
})
