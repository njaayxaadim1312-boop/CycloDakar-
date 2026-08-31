import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { ApiError } from './src/lib/api'
import { SystemStatusScreen } from './src/screens/SystemStatusScreen'

/**
 * Racine de l'application mobile Cyclo Dakar.
 *
 * La navigation (@react-navigation) est installée mais pas encore branchée :
 * l'arborescence d'écrans arrive en phase 5, une fois l'authentification en
 * place. Pour l'instant, l'application ouvre directement l'écran de
 * vérification d'installation.
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
    <QueryClientProvider client={queryClient}>
      <SafeAreaProvider>
        <SystemStatusScreen />
      </SafeAreaProvider>
    </QueryClientProvider>
  )
}
