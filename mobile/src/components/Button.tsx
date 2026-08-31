import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  type PressableProps,
  type StyleProp,
  type ViewStyle,
} from 'react-native'
import { fontSize, radius, spacing, touch } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

type Variant = 'primary' | 'dark' | 'ocean' | 'ghost' | 'danger'

interface ButtonProps extends Omit<PressableProps, 'style'> {
  title: string
  variant?: Variant
  loading?: boolean
  /** Bouton surdimensionné, pour les actions utilisées en roulant. */
  large?: boolean
  style?: StyleProp<ViewStyle>
}

/**
 * Bouton pilule de la charte (voir assets/brand/prototype-design-system.jpg).
 *
 * Le variant `primary` pose du texte NOIR sur l'orange : blanc sur #FF8C00
 * ne donne que 2,5:1 de contraste, illisible en plein soleil, alors que le
 * noir atteint 7,9:1.
 */
export function Button({
  title,
  variant = 'primary',
  loading = false,
  large = false,
  disabled,
  style,
  ...props
}: ButtonProps) {
  const { colors } = useTheme()
  const isDisabled = disabled || loading

  const palette: Record<Variant, { bg: string; fg: string; border?: string }> = {
    primary: { bg: colors.orange, fg: colors.black },
    dark: { bg: colors.black, fg: '#FFFFFF' },
    ocean: { bg: colors.blue, fg: '#FFFFFF' },
    ghost: { bg: 'transparent', fg: colors.text, border: colors.borderStrong },
    danger: { bg: colors.danger, fg: '#FFFFFF' },
  }

  const { bg, fg, border } = palette[variant]

  return (
    <Pressable
      {...props}
      disabled={isDisabled}
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      style={({ pressed }) => [
        styles.base,
        {
          minHeight: large ? touch.field : touch.min,
          backgroundColor: isDisabled ? colors.disabledBg : bg,
          borderWidth: border ? 1 : 0,
          borderColor: border,
          // Retour tactile discret : l'utilisateur doit sentir que ça a pris,
          // même sans regarder l'écran.
          opacity: pressed && !isDisabled ? 0.85 : 1,
          transform: [{ scale: pressed && !isDisabled ? 0.98 : 1 }],
        },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={isDisabled ? colors.disabledText : fg} />
      ) : (
        <Text
          style={[
            styles.label,
            {
              color: isDisabled ? colors.disabledText : fg,
              fontSize: large ? fontSize.h3 : fontSize.body,
            },
          ]}
        >
          {title}
        </Text>
      )}
    </Pressable>
  )
}

const styles = StyleSheet.create({
  base: {
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radius.pill,
    paddingHorizontal: spacing.xl,
  },
  label: { fontWeight: '700' },
})
