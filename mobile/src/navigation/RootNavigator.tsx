import { createBottomTabNavigator } from '@react-navigation/bottom-tabs'
import { NavigationContainer, type Theme as NavTheme } from '@react-navigation/native'
import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { Bike, Play, Users, UserRound } from 'lucide-react-native'
import { useEffect } from 'react'
import { ActivityIndicator, Image, StyleSheet, Text, View } from 'react-native'
import { ActivityDetailScreen } from '../screens/ActivityDetailScreen'
import { HistoryScreen } from '../screens/HistoryScreen'
import { HomeScreen } from '../screens/HomeScreen'
import { MemberDetailScreen } from '../screens/MemberDetailScreen'
import { MembersScreen } from '../screens/MembersScreen'
import { ProfileScreen } from '../screens/ProfileScreen'
import { TrackingNavigator } from './TrackingNavigator'
import { SystemStatusScreen } from '../screens/SystemStatusScreen'
import { LoginScreen } from '../screens/auth/LoginScreen'
import { RegisterScreen } from '../screens/auth/RegisterScreen'
import { useAuth } from '../stores/auth'
import { fontSize, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

/**
 * Navigation de l'application mobile.
 *
 *   non connecté  →  pile Connexion / Inscription
 *   connecté      →  onglets Accueil · Membres · Profil
 *
 * L'onglet central « Démarrer » est le geste principal de l'application : il
 * est au milieu de la barre, là où le pouce tombe naturellement, et porte une
 * pastille orange qui le distingue des autres.
 *
 * L'aiguillage se fait par la présence d'une session, pas par une navigation
 * impérative : quand un jeton est révoqué (compte désactivé, déconnexion
 * depuis un autre appareil), l'arbre bascule tout seul vers la connexion, où
 * que l'utilisateur se trouve.
 */

export type HomeStackParams = {
  HomeFeed: undefined
  History: undefined
  ActivityDetail: { uuid: string }
}

export type MembersStackParams = {
  MembersList: undefined
  MemberDetail: { uuid: string }
}

export type ProfileStackParams = {
  ProfileHome: undefined
  System: undefined
}

export type AuthStackParams = {
  Login: undefined
  Register: undefined
}

const Tabs = createBottomTabNavigator()
const HomeStack = createNativeStackNavigator<HomeStackParams>()
const MembersStack = createNativeStackNavigator<MembersStackParams>()
const ProfileStack = createNativeStackNavigator<ProfileStackParams>()
const AuthStack = createNativeStackNavigator<AuthStackParams>()

/* -------------------------------------------------------------------------- */

/**
 * Pile de l'onglet Accueil.
 *
 * L'historique et le détail d'une sortie vivent ici plutôt que dans un
 * cinquième onglet : la barre en compte déjà quatre, et un cinquième
 * rétrécirait des cibles tactiles qu'on vise parfois avec des gants.
 */
function HomeNavigator() {
  return (
    <HomeStack.Navigator screenOptions={{ headerShown: false }}>
      <HomeStack.Screen name="HomeFeed">
        {({ navigation }) => (
          <HomeScreen
            onOpenMembers={() => navigation.getParent()?.navigate('Membres')}
            onOpenHistory={() => navigation.navigate('History')}
          />
        )}
      </HomeStack.Screen>

      <HomeStack.Screen name="History">
        {({ navigation }) => (
          <HistoryScreen
            onBack={() => navigation.goBack()}
            onOpenActivity={(uuid) => navigation.navigate('ActivityDetail', { uuid })}
          />
        )}
      </HomeStack.Screen>

      <HomeStack.Screen name="ActivityDetail">
        {({ navigation, route }) => (
          <ActivityDetailScreen
            uuid={route.params.uuid}
            onBack={() => navigation.goBack()}
          />
        )}
      </HomeStack.Screen>
    </HomeStack.Navigator>
  )
}

function MembersNavigator() {
  return (
    <MembersStack.Navigator screenOptions={{ headerShown: false }}>
      <MembersStack.Screen name="MembersList">
        {({ navigation }) => (
          <MembersScreen
            onOpenMember={(uuid) => navigation.navigate('MemberDetail', { uuid })}
          />
        )}
      </MembersStack.Screen>

      <MembersStack.Screen name="MemberDetail">
        {({ navigation, route }) => (
          <MemberDetailScreen
            uuid={route.params.uuid}
            onBack={() => navigation.goBack()}
          />
        )}
      </MembersStack.Screen>
    </MembersStack.Navigator>
  )
}

function ProfileNavigator() {
  return (
    <ProfileStack.Navigator screenOptions={{ headerShown: false }}>
      <ProfileStack.Screen name="ProfileHome">
        {({ navigation }) => (
          <ProfileScreen onOpenSystem={() => navigation.navigate('System')} />
        )}
      </ProfileStack.Screen>

      <ProfileStack.Screen name="System">
        {({ navigation }) => <SystemStatusScreen onBack={() => navigation.goBack()} />}
      </ProfileStack.Screen>
    </ProfileStack.Navigator>
  )
}

function AuthNavigator() {
  return (
    <AuthStack.Navigator screenOptions={{ headerShown: false }}>
      <AuthStack.Screen name="Login">
        {({ navigation }) => (
          <LoginScreen onGoToRegister={() => navigation.navigate('Register')} />
        )}
      </AuthStack.Screen>

      <AuthStack.Screen name="Register">
        {({ navigation }) => <RegisterScreen onGoToLogin={() => navigation.goBack()} />}
      </AuthStack.Screen>
    </AuthStack.Navigator>
  )
}

function AppTabs() {
  const { colors } = useTheme()

  return (
    <Tabs.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.orangeText,
        tabBarInactiveTintColor: colors.textMuted,
        tabBarStyle: {
          backgroundColor: colors.surface,
          borderTopColor: colors.border,
        },
        tabBarLabelStyle: { fontSize: fontSize.caption, fontWeight: '600' },
        // Sans cela, les onglets sont trop bas sur les téléphones à encoche.
        tabBarHideOnKeyboard: true,
      }}
    >
      <Tabs.Screen
        name="Accueil"
        component={HomeNavigator}
        options={{
          tabBarIcon: ({ color, size }) => <Bike color={color} size={size} />,
        }}
      />

      {/*
        Onglet central, volontairement mis en avant. `unmountOnBlur` est
        DÉSACTIVÉ par défaut, et c'est ce qu'on veut : quitter l'onglet
        pendant une sortie ne doit rien interrompre — l'enregistrement vit de
        toute façon dans la tâche de fond et dans SQLite.
      */}
      <Tabs.Screen
        name="Démarrer"
        component={TrackingNavigator}
        options={{
          tabBarIcon: ({ focused }) => (
            <View
              style={[
                styles.startTab,
                { backgroundColor: focused ? colors.orange : colors.orangeSoft },
              ]}
            >
              <Play
                color={focused ? colors.black : colors.orangeText}
                size={20}
                fill={focused ? colors.black : colors.orangeText}
              />
            </View>
          ),
        }}
      />

      <Tabs.Screen
        name="Membres"
        component={MembersNavigator}
        options={{
          tabBarIcon: ({ color, size }) => <Users color={color} size={size} />,
        }}
      />

      <Tabs.Screen
        name="Profil"
        component={ProfileNavigator}
        options={{
          tabBarIcon: ({ color, size }) => <UserRound color={color} size={size} />,
        }}
      />
    </Tabs.Navigator>
  )
}

/* -------------------------------------------------------------------------- */

export function RootNavigator() {
  const { colors, isDark, ready: themeReady } = useTheme()
  const { user, ready: sessionReady, bootstrap } = useAuth()

  // Vérification de la session au démarrage : un jeton stocké peut avoir été
  // révoqué depuis un autre appareil.
  useEffect(() => {
    void bootstrap()
  }, [bootstrap])

  // On attend AUSSI le thème : afficher l'application en clair puis basculer
  // en sombre une fraction de seconde plus tard éblouirait précisément celui
  // qui a choisi le mode sombre.
  if (!sessionReady || !themeReady) {
    return <Splash />
  }

  // Le thème de React Navigation doit suivre le nôtre, sinon le fond des
  // transitions d'écran reste blanc en mode sombre.
  const navigationTheme: NavTheme = {
    dark: isDark,
    colors: {
      primary: colors.orange,
      background: colors.bg,
      card: colors.surface,
      text: colors.text,
      border: colors.border,
      notification: colors.orange,
    },
    fonts: {
      regular: { fontFamily: 'System', fontWeight: '400' },
      medium: { fontFamily: 'System', fontWeight: '500' },
      bold: { fontFamily: 'System', fontWeight: '700' },
      heavy: { fontFamily: 'System', fontWeight: '800' },
    },
  }

  return (
    <NavigationContainer theme={navigationTheme}>
      {user ? <AppTabs /> : <AuthNavigator />}
    </NavigationContainer>
  )
}

function Splash() {
  const { colors } = useTheme()

  return (
    <View style={[styles.splash, { backgroundColor: colors.bg }]}>
      <Image
        source={require('../../assets/icon.png')}
        style={styles.splashLogo}
        accessibilityLabel="Cyclo Dakar"
      />
      <ActivityIndicator color={colors.orange} />
      <Text style={[styles.splashText, { color: colors.textMuted }]}>Chargement…</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  startTab: {
    width: 44,
    height: 30,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
  },
  splash: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.lg,
  },
  splashLogo: { width: 96, height: 96, borderRadius: 48, backgroundColor: '#fff' },
  splashText: { fontSize: fontSize.small },
})
