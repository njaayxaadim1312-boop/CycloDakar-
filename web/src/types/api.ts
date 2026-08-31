/**
 * Types du contrat d'API Cyclo Dakar (v1).
 *
 * Ce fichier reflète les réponses de `backend/routes/api.php`. Il sera généré
 * automatiquement depuis le schéma OpenAPI à partir de la phase 19 ; d'ici là
 * il est maintenu à la main et fait foi côté client.
 */

/** Montant en francs CFA. Toujours un ENTIER — le XOF n'a pas de centimes. */
export type Fcfa = number

export type SportCode = 'CYCLING' | 'RUNNING' | 'HIKING'

export type RoleCode =
  | 'MEMBER'
  | 'COLLECTOR'
  | 'TREASURER'
  | 'ADMIN'
  | 'SUPER_ADMIN'

export type PaymentMethodCode =
  | 'CASH'
  | 'WAVE'
  | 'ORANGE_MONEY'
  | 'FREE_MONEY'
  | 'TRANSFER'
  | 'OTHER'

export interface HealthCheck {
  ok: boolean
  message: string
  driver?: string
  latency_ms?: number
}

export interface Health {
  application: string
  api_version: string
  environment: string
  laravel: string
  php: string
  timezone: string
  server_time: string
  status: 'healthy' | 'degraded'
  checks: {
    database: HealthCheck
    storage: HealthCheck
  }
}

export interface SportConfig {
  code: SportCode
  label: string
  icon: string
  uses_pace: boolean
  sample_interval_s: number
  min_distance_m: number
  max_accuracy_m: number
  max_speed_mps: number
}

/**
 * Seuils de l'algorithme GPS servis par le backend.
 * Le mobile s'en sert pour filtrer exactement comme le serveur recalcule
 * (voir docs/gps.md) — une seule source de vérité, pas deux.
 */
export interface GpsConfig {
  warmup_points: number
  warmup_accuracy_m: number
  stale_fix_max_age_s: number
  max_acceleration_mps2: number
  idle_speed_mps: number
  auto_pause_after_s: number
  min_segment_m: number
  elevation_threshold_m: number
  elevation_smoothing_window: number
  simplify_tolerance_m: number
  polyline_precision: number
  sync_batch_size: number
  zone_grid_degrees: number
}

export interface LatLng {
  lat: number
  lng: number
}

export interface AppConfig {
  club: {
    name: string
    founded_year: number
    city: string
    country: string
    motto: string
    timezone: string
  }
  currency: { code: string; symbol: string; decimals: number }
  sports: SportConfig[]
  gps: GpsConfig
  map: {
    provider: 'osm' | 'mapbox'
    default_center: LatLng
    default_zoom: number
    mapbox_token: string | null
  }
  payment_methods: { code: PaymentMethodCode; label: string }[]
  roles: { code: RoleCode; label: string }[]
  video: {
    formats: string[]
    durations_s: number[]
    default_format: string
    default_duration_s: number
    themes: string[]
  }
  uploads: {
    max_size_kb: number
    image_mimes: string[]
    document_mimes: string[]
  }
}

/* -------------------------------------------------------------------------- */
/* Authentification (phase 2)                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Capacités calculées par le serveur.
 *
 * Elles servent à MASQUER ce qui est inaccessible — jamais à autoriser :
 * l'autorisation réelle est refaite à chaque requête côté Laravel. Un client
 * modifié ne gagne donc aucun droit, il ne fait que s'afficher différemment.
 */
export interface UserAbilities {
  collect: boolean
  manage_finance: boolean
  administer: boolean
}

export interface CurrentUser {
  uuid: string
  name: string
  email: string | null
  phone: string | null
  phone_formatted: string | null
  role: RoleCode
  role_label: string
  abilities: UserAbilities
  is_active: boolean
  last_login_at: string | null
  created_at: string | null
}

/** Réponse de POST /auth/login et /auth/register. */
export interface AuthResult {
  token: string
  user: CurrentUser
}

export interface RegisterPayload {
  name: string
  email?: string | null
  phone?: string | null
  password: string
  password_confirmation: string
  device_name?: string
}

export interface LoginPayload {
  /** Adresse email OU numéro de téléphone, sous n'importe quelle forme. */
  login: string
  password: string
  device_name?: string
}

export interface MessageResult {
  message: string
}
