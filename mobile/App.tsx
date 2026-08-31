import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { GestureHandlerRootView } from 'react-native-gesture-handler'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { ApiError } from './src/lib/api'
import { RootNavigator } from './src/navigation/RootNavigator'
import { ThemeProvider } from './src/theme/useTheme'

/**
 * Racine de l'application mobile Cyclo Dakar.
 *
 * L'ordre des fournisseurs compte :
 *  - `GestureHandlerRootView` doit envelopper toute l'application, sinon les
 *    gestes de navigation (retour par balayage) ne fonctionnent pas ;
 *  - `ThemeProvider` est au-dessus de la navigation, dont il colore le fond.
 */

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      // Politique adaptée au terrain : on insiste sur une coupure réseau
      // (fréquente en sortie), jamais sur une erreur métier.
      retry: (failureCount, error) => {
        if (error instanceof ApiError) {
          if (error.isNetworkError) return failureCount < 2
          if (error.status >= 400 && error.status < 500) return false
        }
        return failureCount < 1
      },
    },
    mutations: {
      // Un paiement ou une synchronisation ne sont JAMAIS rejoués
      // automatiquement : voir docs/finance.md (risque F2) et docs/gps.md.
      retry: false,
    },
  },
})

export default function App() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider>
          <SafeAreaProvider>
            <RootNavigator />
          </SafeAreaProvider>
        </ThemeProvider>
      </QueryClientProvider>
    </GestureHandlerRootView>
  )
}
