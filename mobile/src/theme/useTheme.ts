import AsyncStorage from '@react-native-async-storage/async-storage'
import {
  createContext,
  createElement,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useColorScheme } from 'react-native'
import { palettes, type Palette } from './tokens'

export type ThemeChoice = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'cd.theme'

export interface Theme {
  colors: Palette
  isDark: boolean
  choice: ThemeChoice
  setChoice: (choice: ThemeChoice) => void
  /** `false` tant que la préférence enregistrée n'a pas été relue. */
  ready: boolean
}

const ThemeContext = createContext<Theme | null>(null)

/**
 * Thème de l'application mobile.
 *
 * Trois choix : clair, sombre, ou suivre le système. Le réglage est persisté :
 * un membre qui choisit le mode sombre parce qu'il roule avant le lever du
 * jour ne doit pas avoir à le refaire à chaque ouverture.
 *
 * Le contexte est nécessaire ici (contrairement à un simple hook) parce que le
 * sélecteur de l'écran Profil et le reste de l'application doivent partager le
 * MÊME état — sinon l'icône et l'apparence réelle se désynchronisent.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const systemScheme = useColorScheme()
  const [choice, setChoiceState] = useState<ThemeChoice>('system')
  const [ready, setReady] = useState(false)

  // Relecture de la préférence au démarrage. L'application s'affiche d'abord
  // en « système » : c'est un choix raisonnable le temps d'une lecture disque.
  useEffect(() => {
    let cancelled = false

    AsyncStorage.getItem(STORAGE_KEY)
      .then((stored) => {
        if (cancelled) return
        if (stored === 'light' || stored === 'dark' || stored === 'system') {
          setChoiceState(stored)
        }
      })
      .catch(() => {
        /* stockage indisponible : on reste sur « système » */
      })
      .finally(() => {
        if (!cancelled) setReady(true)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const setChoice = useCallback((next: ThemeChoice) => {
    setChoiceState(next)
    void AsyncStorage.setItem(STORAGE_KEY, next).catch(() => {
      /* l'application reste utilisable même si l'écriture échoue */
    })
  }, [])

  const isDark = choice === 'dark' || (choice === 'system' && systemScheme === 'dark')

  const value = useMemo<Theme>(
    () => ({
      colors: isDark ? palettes.dark : palettes.light,
      isDark,
      choice,
      setChoice,
      ready,
    }),
    [isDark, choice, setChoice, ready],
  )

  return createElement(ThemeContext.Provider, { value }, children)
}

export function useTheme(): Theme {
  const context = useContext(ThemeContext)

  if (!context) {
    throw new Error("useTheme doit être utilisé à l'intérieur de <ThemeProvider>.")
  }

  return context
}
