import * as Device from 'expo-device'
import { Platform } from 'react-native'
import { create } from 'zustand'
import { getData, postData, setUnauthenticatedHandler, tokenStore } from '../lib/api'
import type {
  AuthResult,
  CurrentUser,
  LoginPayload,
  MessageResult,
  RegisterPayload,
  RoleCode,
} from '../types/api'

/**
 * Session de l'utilisateur connecté (mobile).
 *
 * Miroir de `web/src/stores/auth.ts`, à deux différences près :
 *  - le jeton vit dans le trousseau sécurisé du téléphone, dont l'accès est
 *    asynchrone ;
 *  - le nom d'appareil est le vrai modèle du téléphone, ce qui rend la liste
 *    des sessions lisible (« Tecno Spark 10 » plutôt que « Android »).
 */

function deviceName(): string {
  const model = Device.modelName ?? Platform.OS
  return `${model} · Cyclo Dakar`
}

interface AuthState {
  user: CurrentUser | null
  /** La session initiale a-t-elle été vérifiée ? */
  ready: boolean

  bootstrap: () => Promise<void>
  login: (payload: LoginPayload) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: (allDevices?: boolean) => Promise<void>
  clear: () => Promise<void>
  refresh: () => Promise<void>
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  ready: false,

  async bootstrap() {
    const token = await tokenStore.get()

    if (!token) {
      set({ user: null, ready: true })
      return
    }

    try {
      const user = await getData<CurrentUser>('/me')
      set({ user, ready: true })
    } catch (error) {
      // Nuance importante en mobilité : hors réseau, on ne DÉCONNECTE PAS.
      // Un membre qui ouvre l'application au départ d'une sortie, sans data,
      // doit rester connecté — sinon il ne peut plus rien enregistrer.
      // Seul un refus explicite du serveur (401) vide la session, et c'est
      // l'intercepteur de `lib/api.ts` qui s'en charge.
      const offline =
        typeof error === 'object' && error !== null && 'isNetworkError' in error
          ? Boolean((error as { isNetworkError: unknown }).isNetworkError)
          : false

      if (offline) {
        set({ ready: true })
        return
      }

      await tokenStore.clear()
      set({ user: null, ready: true })
    }
  },

  async login(payload) {
    const result = await postData<AuthResult>('/auth/login', {
      ...payload,
      device_name: payload.device_name ?? deviceName(),
    })

    await tokenStore.set(result.token)
    set({ user: result.user, ready: true })
  },

  async register(payload) {
    const result = await postData<AuthResult>('/auth/register', {
      ...payload,
      device_name: payload.device_name ?? deviceName(),
    })

    await tokenStore.set(result.token)
    set({ user: result.user, ready: true })
  },

  async logout(allDevices = false) {
    try {
      await postData<MessageResult>('/auth/logout', { all_devices: allDevices })
    } catch {
      // Même hors ligne, une demande de déconnexion doit vider la session
      // locale : le téléphone peut être prêté ou perdu.
    } finally {
      await get().clear()
    }
  },

  async clear() {
    await tokenStore.clear()
    set({ user: null, ready: true })
  },

  async refresh() {
    const user = await getData<CurrentUser>('/me')
    set({ user })
  },
}))

/** Un 401 sur n'importe quelle requête vide la session. */
setUnauthenticatedHandler(() => {
  void useAuth.getState().clear()
})

/* -------------------------------------------------------------------------- */

export const useCurrentUser = () => useAuth((state) => state.user)

const ROLE_LEVEL: Record<RoleCode, number> = {
  MEMBER: 10,
  COLLECTOR: 20,
  TREASURER: 30,
  ADMIN: 40,
  SUPER_ADMIN: 50,
}

/**
 * Affichage seulement : masquer un écran n'est pas une autorisation.
 * Le serveur revérifie le rôle à chaque requête.
 */
export function hasAtLeastRole(user: CurrentUser | null, role: RoleCode): boolean {
  if (!user) return false
  return ROLE_LEVEL[user.role] >= ROLE_LEVEL[role]
}
