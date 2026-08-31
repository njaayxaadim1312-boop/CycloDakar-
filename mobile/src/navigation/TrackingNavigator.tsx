import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { useEffect } from 'react'
import { StartScreen } from '../screens/tracking/StartScreen'
import { SummaryScreen } from '../screens/tracking/SummaryScreen'
import { TrackingScreen } from '../screens/tracking/TrackingScreen'
import { useTracking } from '../stores/tracking'

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
 *   ait à comprendre ce qui s'est passé ;
 *
 * - **`gestureEnabled: false` sur le suivi**. Un balayage involontaire, le
 *   téléphone dans une poche ou tenu d'une main au guidon, ne doit pas
 *   pouvoir quitter l'enregistrement.
 */
export function TrackingNavigator() {
  const { activity, restore } = useTracking()

  useEffect(() => {
    void restore()
  }, [restore])

  const recovering = activity !== null

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
