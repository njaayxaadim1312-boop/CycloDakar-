import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ActivityIndicator, Image, StyleSheet, Text, View } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { ApiError } from './src/lib/api'
import { HomeScreen } from './src/screens/HomeScreen'
import { SystemStatusScreen } from './src/screens/SystemStatusScreen'
import { LoginScreen } from './src/screens/auth/LoginScreen'
import { RegisterScreen } from './src/screens/auth/RegisterScreen'
import { useAuth } from './src/stores/auth'
import { fontSize, spacing } from './src/theme/tokens'
import { useTheme } from './src/theme/useTheme'

/**
 * Racine de l'application mobile Cyclo Dakar.
 *
 * L'aiguillage entre écrans est fait à la main pour l'instant : @react-navigation
 * est installé mais l'arborescence complète (onglets, pile, écran de tracking)
 * arrive en PHASE 5. Câbler une navigation complète ici pour trois écrans
 * ajouterait de la mécanique sans rien apporter.
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

type AuthScreen = 'login' | 'register'
type AppScreen = 'home' | 'system'

function Root() {
  const { user, ready, bootstrap } = useAuth()

  const [authScreen, setAuthScreen] = useState<AuthScreen>('login')
  const [appScreen, setAppScreen] = useState<AppScreen>('home')

  // Vérification de la session au démarrage : un jeton stocké peut avoir été
  // révoqué depuis un autre appareil.
  useEffect(() => {
    void bootstrap()
  }, [bootstrap])

  if (!ready) {
    return <Splash />
  }

  if (!user) {
    return authScreen === 'login' ? (
      <LoginScreen onGoToRegister={() => setAuthScreen('register')} />
    ) : (
      <RegisterScreen onGoToLogin={() => setAuthScreen('login')} />
    )
  }

  return appScreen === 'home' ? (
    <HomeScreen onOpenSystem={() => setAppScreen('system')} />
  ) : (
    <SystemStatusScreen onBack={() => setAppScreen('home')} />
  )
}

/** Écran d'attente pendant la vérification de session. */
function Splash() {
  const { colors } = useTheme()

  return (
    <View style={[styles.splash, { backgroundColor: colors.bg }]}>
      <Image
        source={require('./assets/icon.png')}
        style={styles.splashLogo}
        accessibilityLabel="Cyclo Dakar"
      />
      <ActivityIndicator color={colors.orange} />
      <Text style={[styles.splashText, { color: colors.textMuted }]}>Chargement…</Text>
    </View>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <SafeAreaProvider>
        <Root />
      </SafeAreaProvider>
    </QueryClientProvider>
  )
}

const styles = StyleSheet.create({
  splash: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.lg,
  },
  splashLogo: { width: 96, height: 96, borderRadius: 48, backgroundColor: '#fff' },
  splashText: { fontSize: fontSize.small },
})
