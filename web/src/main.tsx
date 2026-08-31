import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import { ApiError } from './lib/api'
import './index.css'

/**
 * Client de requêtes partagé.
 *
 * Politique de nouvelle tentative pensée pour le contexte réel du club :
 * la connexion est souvent intermittente, mais réessayer une erreur 4xx
 * (validation, permission) est inutile et masque le vrai problème.
 */
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        if (error instanceof ApiError) {
          // Erreur réseau : le serveur peut revenir, on insiste un peu.
          if (error.isNetworkError) return failureCount < 2
          // Erreur métier : réessayer ne changera rien.
          if (error.status >= 400 && error.status < 500) return false
        }
        return failureCount < 1
      },
    },
    mutations: {
      // Une mutation (paiement, dépense) n'est JAMAIS rejouée
      // automatiquement : voir docs/finance.md, risque F2.
      retry: false,
    },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
