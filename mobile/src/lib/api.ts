import Constants from 'expo-constants'
import * as SecureStore from 'expo-secure-store'
import axios, {
  type AxiosError,
  type AxiosInstance,
  type InternalAxiosRequestConfig,
} from 'axios'
import { Platform } from 'react-native'

/**
 * Client HTTP de l'application mobile.
 *
 * Miroir de `web/src/lib/api.ts` : le mobile et le web consomment la MÊME API
 * Laravel (docs/architecture.md). Deux différences propres au mobile :
 *
 *  1. le token est stocké dans le trousseau sécurisé du téléphone
 *     (Keychain iOS / Keystore Android), pas dans un stockage en clair ;
 *  2. l'URL de l'API dépend de l'environnement d'exécution — un émulateur
 *     Android ne voit pas « localhost » de la machine hôte.
 */

const TOKEN_KEY = 'cd_auth_token'

/**
 * Résolution de l'URL de l'API en développement.
 *
 *  - Émulateur Android : 10.0.2.2 est l'alias de la machine hôte.
 *  - Téléphone réel via Expo Go : on réutilise l'IP du serveur Metro, qui est
 *    par construction celle du PC sur le réseau Wi-Fi. C'est ce qui évite de
 *    devoir coder son IP en dur à chaque changement de réseau.
 *  - Simulateur iOS et Expo Web : localhost fonctionne.
 */
function resolveApiUrl(): string {
  const configured = process.env.EXPO_PUBLIC_API_URL
  if (configured) return configured

  const port = 8000
  const path = '/api/v1'

  // hostUri ressemble à « 192.168.1.42:8081 » quand Metro sert sur le réseau.
  const hostUri = Constants.expoConfig?.hostUri ?? Constants.expoGoConfig?.debuggerHost
  const host = hostUri?.split(':')[0]

  if (host && host !== 'localhost' && host !== '127.0.0.1') {
    return `http://${host}:${port}${path}`
  }

  if (Platform.OS === 'android') {
    return `http://10.0.2.2:${port}${path}`
  }

  return `http://127.0.0.1:${port}${path}`
}

export const API_URL = resolveApiUrl()

export interface ApiEnvelope<T> {
  data: T
  meta?: Record<string, unknown>
}

export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
  code?: string
}

/**
 * Erreur normalisée. Le mode hors ligne est la norme en sortie, pas
 * l'exception : `isNetworkError` permet à l'appelant de basculer en file
 * d'attente locale au lieu d'afficher une erreur au membre.
 */
export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors: Record<string, string[]>
  readonly isNetworkError: boolean

  constructor(
    message: string,
    status: number,
    options: {
      code?: string
      errors?: Record<string, string[]>
      isNetworkError?: boolean
    } = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = options.code
    this.errors = options.errors ?? {}
    this.isNetworkError = options.isNetworkError ?? false
  }

  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

/**
 * Trousseau sécurisé du téléphone. SecureStore n'est pas disponible sur le
 * web : on y dégrade proprement plutôt que de planter au démarrage.
 */
export const tokenStore = {
  async get(): Promise<string | null> {
    if (Platform.OS === 'web') return null
    try {
      return await SecureStore.getItemAsync(TOKEN_KEY)
    } catch {
      return null
    }
  },
  async set(token: string): Promise<void> {
    if (Platform.OS === 'web') return
    try {
      await SecureStore.setItemAsync(TOKEN_KEY, token)
    } catch {
      /* ignoré volontairement */
    }
  },
  async clear(): Promise<void> {
    if (Platform.OS === 'web') return
    try {
      await SecureStore.deleteItemAsync(TOKEN_KEY)
    } catch {
      /* ignoré volontairement */
    }
  },
}

export const api: AxiosInstance = axios.create({
  baseURL: API_URL,
  // 20 s : sur un réseau mobile sénégalais, 5 s serait trop court et
  // provoquerait de faux échecs de synchronisation.
  timeout: 20_000,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const token = await tokenStore.get()
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`)
  }
  return config
})

let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler
}

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiErrorBody>) => {
    if (!error.response) {
      return Promise.reject(
        new ApiError(
          error.code === 'ECONNABORTED'
            ? 'Le serveur met trop de temps à répondre.'
            : 'Pas de connexion. Vos données restent enregistrées sur le téléphone.',
          0,
          { isNetworkError: true, code: 'NETWORK_ERROR' },
        ),
      )
    }

    const { status, data } = error.response

    if (status === 401) {
      await tokenStore.clear()
      onUnauthenticated?.()
    }

    return Promise.reject(
      new ApiError(data?.message ?? 'Une erreur est survenue.', status, {
        code: data?.code,
        errors: data?.errors,
      }),
    )
  },
)

export async function getData<T>(url: string, params?: unknown): Promise<T> {
  const response = await api.get<ApiEnvelope<T>>(url, { params })
  return response.data.data
}

export async function postData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.post<ApiEnvelope<T>>(url, body)
  return response.data.data
}
