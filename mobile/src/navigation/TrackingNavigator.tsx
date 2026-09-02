import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { useEffect, useState } from 'react'
import { ActivityIndicator, View } from 'react-native'
import { StartScreen } from '../screens/tracking/StartScreen'
import { SummaryScreen } from '../screens/tracking/SummaryScreen'
import { TrackingScreen } from '../screens/tracking/TrackingScreen'
import { useTracking } from '../stores/tracking'
import { useTheme } from '../theme/useTheme'

export type TrackingStackParams = {
  Start: undefined
  Tracking: undefined
  Summary: { uuid: string }
}

const Stack = createNativeStackNavigator<TrackingStackParams>()

/**
 * Parcours d'enregistrement d'une sortie.
 *
 *   Start ──▶ Tracking ──▶ Summary ──▶ Start
 *
 * Deux comportements méritent d'être signalés :
 *
 * - **la reprise**. Au montage, on demande au magasin s'il existe une sortie
 *   inachevée en base. C'est le cas si Android a tué l'application en pleine
 *   sortie — chose fréquente sur les téléphones à gestion de batterie
 *   agressive. On repart directement sur l'écran de suivi, sans que le membre
 *   ait à comprendre ce qui s'est passé.
 *
 *   **On attend la réponse de la base avant de monter la pile**, et ce détail
 *   est tout le mécanisme. `initialRouteName` n'est lu qu'au PREMIER rendu :
 *   monter d'abord puis découvrir la sortie une fraction de seconde plus tard
 *   laissait le membre sur « Démarrer » alors que son enregistrement tournait
 *   toujours en arrière-plan. Il croyait sa sortie perdue, et un second appui
 *   sur Démarrer en aurait ouvert une deuxième par-dessus ;
 *
 * - **`gestureEnabled: false` sur le suivi**. Un balayage involontaire, le
 *   téléphone dans une poche ou tenu d'une main au guidon, ne doit pas
 *   pouvoir quitter l'enregistrement.
 */
export function TrackingNavigator() {
  const { colors } = useTheme()
  const { activity, restore } = useTracking()

  // Faux tant que la base n'a pas répondu : on ne sait pas encore s'il y a une
  // sortie à reprendre, et prétendre le savoir est justement l'erreur.
  const [interroge, setInterroge] = useState(false)

  useEffect(() => {
    void restore().finally(() => setInterroge(true))
  }, [restore])

  const recovering = activity !== null

  if (!interroge) {
    // Quelques dizaines de millisecondes de lecture SQLite. Mieux vaut un
    // instant vide qu'un écran « Démarrer » affiché par erreur au-dessus d'une
    // sortie en cours.
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
        <ActivityIndicator color={colors.orange} />
      </View>
    )
  }

  return (
    <Stack.Navigator
      screenOptions={{ headerShown: false }}
      // Une sortie retrouvée en base fait démarrer la pile sur le suivi.
      initialRouteName={recovering ? 'Tracking' : 'Start'}
    >
      <Stack.Screen name="Start">
        {({ navigation }) => (
          <StartScreen onStarted={() => navigation.navigate('Tracking')} />
        )}
      </Stack.Screen>

      <Stack.Screen name="Tracking" options={{ gestureEnabled: false }}>
        {({ navigation }) => (
          <TrackingScreen
            onFinished={(uuid) => navigation.replace('Summary', { uuid })}
            onDiscarded={() => navigation.replace('Start')}
          />
        )}
      </Stack.Screen>

      <Stack.Screen name="Summary">
        {({ navigation, route }) => (
          <SummaryScreen
            uuid={route.params.uuid}
            onClose={() => navigation.replace('Start')}
          />
        )}
      </Stack.Screen>
    </Stack.Navigator>
  )
}
