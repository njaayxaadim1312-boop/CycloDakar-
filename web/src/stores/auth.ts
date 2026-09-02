import { create } from 'zustand'
import {
  getData,
  postData,
  setUnauthenticatedHandler,
  tokenStore,
} from '@/lib/api'
import type {
  AuthResult,
  CurrentUser,
  LoginPayload,
  MessageResult,
  RegisterPayload,
  RoleCode,
} from '@/types/api'

/**
 * Session de l'utilisateur connecté.
 *
 * Le jeton vit dans le localStorage, la fiche utilisateur en mémoire. Au
 * démarrage, `bootstrap()` vérifie auprès du serveur que le jeton stocké est
 * encore valide : un jeton révoqué depuis un autre appareil ne doit pas
 * laisser croire à une session ouverte.
 */

/** Nom d'appareil envoyé au serveur, pour distinguer les sessions. */
function deviceName(): string {
  const ua = navigator.userAgent
  const browser =
    /Edg\//.test(ua) ? 'Edge'
    : /Chrome\//.test(ua) ? 'Chrome'
    : /Firefox\//.test(ua) ? 'Firefox'
    : /Safari\//.test(ua) ? 'Safari'
    : 'Navigateur'

  const os =
    /Windows/.test(ua) ? 'Windows'
    : /Android/.test(ua) ? 'Android'
    : /iPhone|iPad/.test(ua) ? 'iOS'
    : /Mac OS/.test(ua) ? 'macOS'
    : /Linux/.test(ua) ? 'Linux'
    : ''

  return os ? `${browser} · ${os}` : browser
}

interface AuthState {
  user: CurrentUser | null
  /** `true` tant que la session initiale n'a pas été vérifiée. */
  loading: boolean
  /** La vérification initiale a-t-elle eu lieu ? */
  ready: boolean

  bootstrap: () => Promise<void>
  login: (payload: LoginPayload) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: (allDevices?: boolean) => Promise<void>
  /** Vidage local, sans appel serveur (jeton déjà invalide). */
  clear: () => void
  refresh: () => Promise<void>
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  loading: true,
  ready: false,

  async bootstrap() {
    if (!tokenStore.get()) {
      set({ user: null, loading: false, ready: true })
      return
    }

    try {
      const user = await getData<CurrentUser>('/me')
      set({ user, loading: false, ready: true })
    } catch {
      // Jeton expiré, révoqué, ou serveur injoignable : dans tous les cas on
      // n'affiche pas l'application comme si la session était ouverte.
      tokenStore.clear()
      set({ user: null, loading: false, ready: true })
    }
  },

  async login(payload) {
    const result = await postData<AuthResult>('/auth/login', {
      ...payload,
      device_name: payload.device_name ?? deviceName(),
    })

    tokenStore.set(result.token)
    set({ user: result.user, loading: false, ready: true })
  },

  async register(payload) {
    const result = await postData<AuthResult>('/auth/register', {
      ...payload,
      device_name: payload.device_name ?? deviceName(),
    })

    tokenStore.set(result.token)
    set({ user: result.user, loading: false, ready: true })
  },

  async logout(allDevices = false) {
    try {
      await postData<MessageResult>('/auth/logout', { all_devices: allDevices })
    } catch {
      // Même si l'appel échoue (hors ligne, jeton déjà mort), on doit vider la
      // session locale : l'utilisateur a demandé à se déconnecter.
    } finally {
      get().clear()
    }
  },

  clear() {
    tokenStore.clear()
    set({ user: null, loading: false, ready: true })
  },

  async refresh() {
    const user = await getData<CurrentUser>('/me')
    set({ user })
  },
}))

/**
 * Un 401 renvoyé par n'importe quelle requête vide la session : le jeton a été
 * révoqué (déconnexion depuis un autre appareil, compte désactivé, mot de passe
 * réinitialisé). L'application redirige alors vers la connexion.
 */
setUnauthenticatedHandler(() => {
  useAuth.getState().clear()
})

/* -------------------------------------------------------------------------- */
/* Sélecteurs                                                                 */
/* -------------------------------------------------------------------------- */

export const useCurrentUser = () => useAuth((state) => state.user)
export const useIsAuthenticated = () => useAuth((state) => state.user !== null)

/**
 * L'utilisateur a-t-il au moins ce rôle ?
 *
 * Sert uniquement à l'affichage. Le serveur revérifie systématiquement :
 * masquer un bouton n'est pas une mesure de sécurité, c'est du confort.
 */
const ROLE_LEVEL: Record<RoleCode, number> = {
  MEMBER: 10,
  // 15 : encadrer une sortie n'ouvre aucun accès à l'argent. Miroir de
  // `UserRole::level()`.
  RIDE_LEADER: 15,
  COLLECTOR: 20,
  TREASURER: 30,
  ADMIN: 40,
  SUPER_ADMIN: 50,
}

export function hasAtLeastRole(user: CurrentUser | null, role: RoleCode): boolean {
  if (!user) return false
  return ROLE_LEVEL[user.role] >= ROLE_LEVEL[role]
}

/** L'utilisateur peut-il accéder à une entrée de menu réservée à `roles` ? */
export function canAccess(user: CurrentUser | null, roles?: RoleCode[]): boolean {
  if (!roles || roles.length === 0) return true
  if (!user) return false
  return roles.includes(user.role)
}
