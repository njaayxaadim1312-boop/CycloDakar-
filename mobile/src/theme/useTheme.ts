import { useColorScheme } from 'react-native'
import { palettes, type Palette } from './tokens'

export interface Theme {
  colors: Palette
  isDark: boolean
}

/**
 * Thème suivant l'apparence du système.
 *
 * Le club roule avant le lever du jour : le mode sombre évite d'être ébloui
 * quand on consulte l'écran au guidon. Un sélecteur manuel (clair / sombre /
 * système) sera ajouté à l'écran Profil en phase 5.
 */
export function useTheme(): Theme {
  const scheme = useColorScheme()
  const isDark = scheme === 'dark'

  return {
    colors: isDark ? palettes.dark : palettes.light,
    isDark,
  }
}
