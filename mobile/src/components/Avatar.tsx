import { Image, StyleSheet, Text, View } from 'react-native'

interface AvatarProps {
  photoUrl?: string | null
  /**
   * Initiales du membre. Tolérées absentes : l'API les fournit toujours, mais
   * un avatar ne doit jamais pouvoir faire planter l'écran entier.
   */
  initials?: string | null
  size?: number
}

/**
 * Photo du membre, ou ses initiales à défaut.
 *
 * Beaucoup de fiches n'auront jamais de photo. Une silhouette grise générique
 * rendrait une liste de 200 membres illisible ; des initiales colorées restent
 * identifiables d'un coup d'œil, et la couleur est dérivée des initiales, donc
 * stable pour une même personne.
 */
export function Avatar({ photoUrl, initials, size = 44 }: AvatarProps) {
  const letters = (initials ?? '').trim().slice(0, 2).toUpperCase() || '?'
  const radius = size / 2

  if (photoUrl) {
    return (
      <Image
        source={{ uri: photoUrl }}
        style={{ width: size, height: size, borderRadius: radius }}
        accessibilityIgnoresInvertColors
      />
    )
  }

  return (
    <View
      style={[
        styles.fallback,
        {
          width: size,
          height: size,
          borderRadius: radius,
          backgroundColor: colorFor(letters),
        },
      ]}
    >
      <Text style={[styles.letters, { fontSize: Math.round(size * 0.38) }]}>
        {letters}
      </Text>
    </View>
  )
}

/** Teintes pâles dérivées de la charte, lisibles avec du texte noir. */
const PALETTE = ['#FFD9A6', '#BFD7EA', '#C6EFC6', '#E8D5F2', '#FFE0B2', '#D3E4CD']

function colorFor(initials: string): string {
  let hash = 0
  for (let i = 0; i < initials.length; i++) {
    hash = initials.charCodeAt(i) + ((hash << 5) - hash)
  }
  return PALETTE[Math.abs(hash) % PALETTE.length] ?? PALETTE[0]!
}

const styles = StyleSheet.create({
  fallback: { alignItems: 'center', justifyContent: 'center' },
  letters: { fontWeight: '800', color: '#1A1A1A' },
})
