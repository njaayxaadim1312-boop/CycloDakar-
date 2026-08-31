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

export type ThemeChoice = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'cd.theme'

function readStoredChoice(): ThemeChoice {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      return stored
    }
  } catch {
    /* stockage indisponible : on retombe sur « system » */
  }
  return 'system'
}

/**
 * Applique le choix sur `<html>`.
 *
 * « system » ne pose AUCUN attribut : c'est la règle `prefers-color-scheme` de
 * tokens.css qui décide alors. Poser `data-theme` uniquement pour un choix
 * explicite permet de suivre l'OS quand l'utilisateur ne s'est pas prononcé.
 */
function applyChoice(choice: ThemeChoice): void {
  const root = document.documentElement
  if (choice === 'system') {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', choice)
  }
}

interface ThemeContextValue {
  choice: ThemeChoice
  setChoice: (choice: ThemeChoice) => void
  isDark: boolean
  toggle: () => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

/**
 * Fournisseur de thème.
 *
 * L'état est partagé par un contexte plutôt que dupliqué dans chaque composant :
 * l'en-tête et les futurs réglages doivent basculer le MÊME thème, sinon
 * l'icône du bouton et l'apparence réelle se désynchronisent.
 *
 * Le club roule tôt le matin et tard le soir : le mode sombre n'est pas un
 * gadget, il évite d'être ébloui avant le lever du jour.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [choice, setChoice] = useState<ThemeChoice>(readStoredChoice)
  const [systemDark, setSystemDark] = useState(
    () => window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false,
  )

  useEffect(() => {
    applyChoice(choice)
    try {
      localStorage.setItem(STORAGE_KEY, choice)
    } catch {
      /* ignoré */
    }
  }, [choice])

  useEffect(() => {
    const media = window.matchMedia?.('(prefers-color-scheme: dark)')
    if (!media) return

    const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches)
    media.addEventListener('change', onChange)
    return () => media.removeEventListener('change', onChange)
  }, [])

  const isDark = choice === 'dark' || (choice === 'system' && systemDark)

  // La bascule part de l'apparence RÉELLEMENT affichée, pas du réglage : depuis
  // « système + OS en sombre », un clic doit donner « clair ».
  const toggle = useCallback(() => {
    setChoice(isDark ? 'light' : 'dark')
  }, [isDark])

  const value = useMemo(
    () => ({ choice, setChoice, isDark, toggle }),
    [choice, isDark, toggle],
  )

  return createElement(ThemeContext.Provider, { value }, children)
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext)
  if (!context) {
    throw new Error('useTheme doit être utilisé à l\'intérieur de <ThemeProvider>.')
  }
  return context
}
