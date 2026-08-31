import axios, {
  type AxiosError,
  type AxiosInstance,
  type InternalAxiosRequestConfig,
} from 'axios'

/**
 * Client HTTP unique de l'application web.
 *
 * Le web et le mobile parlent à la MÊME API (docs/architecture.md) : ce fichier
 * est le miroir de `mobile/src/lib/api.ts`. Toute évolution du contrat doit
 * être répercutée des deux côtés.
 */

const TOKEN_STORAGE_KEY = 'cd.auth.token'

/** Forme de succès de l'API : { data, meta? } */
export interface ApiEnvelope<T> {
  data: T
  meta?: Record<string, unknown>
}

/** Forme d'erreur de l'API : { message, errors?, code? } */
export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
  code?: string
}

/**
 * Erreur normalisée. Les composants n'ont jamais à connaître axios : ils
 * reçoivent toujours ce type, y compris quand le réseau est coupé — cas
 * fréquent pour un club qui roule sur la Corniche.
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

  /** Première erreur de validation pour un champ donné. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

export const tokenStore = {
  get(): string | null {
    try {
      return localStorage.getItem(TOKEN_STORAGE_KEY)
    } catch {
      // Navigation privée ou stockage bloqué : on dégrade sans casser.
      return null
    }
  },
  set(token: string): void {
    try {
      localStorage.setItem(TOKEN_STORAGE_KEY, token)
    } catch {
      /* ignoré volontairement */
    }
  },
  clear(): void {
    try {
      localStorage.removeItem(TOKEN_STORAGE_KEY)
    } catch {
      /* ignoré volontairement */
    }
  },
}

/**
 * En développement, `VITE_API_URL` vaut `/api/v1` : Vite relaie vers
 * `php artisan serve` (voir vite.config.ts), donc une seule origine, pas de
 * CORS. En production, on pointe l'URL complète de l'API.
 */
const baseURL = import.meta.env.VITE_API_URL ?? '/api/v1'

export const api: AxiosInstance = axios.create({
  baseURL,
  timeout: 20_000,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = tokenStore.get()
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`)
  }
  return config
})

/** Callback déclenché sur 401, branché par le store d'authentification. */
let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler
}

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorBody>) => {
    // Pas de réponse du tout : serveur éteint, Wi-Fi coupé, timeout.
    if (!error.response) {
      return Promise.reject(
        new ApiError(
          error.code === 'ECONNABORTED'
            ? 'Le serveur met trop de temps à répondre.'
            : 'Serveur injoignable. Vérifiez votre connexion.',
          0,
          { isNetworkError: true, code: 'NETWORK_ERROR' },
        ),
      )
    }

    const { status, data } = error.response

    // Token expiré ou révoqué : on nettoie et on laisse l'app rediriger.
    if (status === 401) {
      tokenStore.clear()
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

/** GET renvoyant directement le contenu de `data`. */
export async function getData<T>(url: string, params?: unknown): Promise<T> {
  const response = await api.get<ApiEnvelope<T>>(url, { params })
  return response.data.data
}

/** POST renvoyant directement le contenu de `data`. */
export async function postData<T>(url: string, body?: unknown): Promise<T> {
  const response = await api.post<ApiEnvelope<T>>(url, body)
  return response.data.data
}
