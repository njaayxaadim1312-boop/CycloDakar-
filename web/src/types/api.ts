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

/* -------------------------------------------------------------------------- */
/* Membres (phase 3)                                                           */
/* -------------------------------------------------------------------------- */

export type MemberStatusCode = 'ACTIVE' | 'PENDING' | 'SUSPENDED' | 'FORMER'

/** Ce que le visiteur a le droit de faire sur une fiche, décidé par le serveur. */
export interface MemberPermissions {
  update: boolean
  update_status: boolean
  update_role: boolean
  manage_qr: boolean
  delete: boolean
}

/** Compte de connexion associé. `null` pour un membre sans smartphone. */
export interface MemberAccount {
  uuid: string
  role: RoleCode
  role_label: string
  is_active: boolean
  last_login_at: string | null
}

/**
 * Fiche membre.
 *
 * Plusieurs champs sont optionnels non pas parce qu'ils peuvent être vides,
 * mais parce que le SERVEUR les omet selon qui regarde : les coordonnées ne
 * sont visibles que des collecteurs et de l'intéressé, les notes des seuls
 * administrateurs. Un `undefined` signifie donc « pas le droit de voir », un
 * `null` signifie « non renseigné ».
 */
export interface Member {
  uuid: string
  matricule: string
  first_name: string
  last_name: string
  full_name: string
  initials: string
  photo_url: string | null
  status: MemberStatusCode
  status_label: string
  joined_at: string | null
  seniority_years: number

  phone?: string | null
  phone_formatted?: string | null
  email?: string | null

  birth_date?: string | null
  gender?: string | null
  emergency_contact_name?: string | null
  emergency_contact_phone?: string | null
  notes?: string | null

  qr_token?: string

  account?: MemberAccount | null
  has_account: boolean

  created_at: string | null
  updated_at: string | null

  permissions?: MemberPermissions
}

/** Résultat allégé de la recherche terrain. */
export interface MemberSearchResult {
  uuid: string
  matricule: string
  full_name: string
  initials: string
  phone_formatted: string | null
  photo_url: string | null
  status: MemberStatusCode
}

export interface MemberFilters {
  search?: string
  status?: MemberStatusCode | ''
  role?: RoleCode | ''
  has_account?: '0' | '1' | ''
  sort?: 'name' | 'matricule' | 'recent' | 'seniority'
  page?: number
  per_page?: number
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    has_more: boolean
  }
}

/* -------------------------------------------------------------------------- */
/* Tableau de bord (phase 4)                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Bloc d'un module pas encore livré.
 *
 * `available: false` et non un zéro : « aucune activité » et « module pas
 * encore livré » ne veulent pas dire la même chose, et sur un tableau de bord
 * qui affichera un solde de caisse, confondre les deux ruinerait la confiance.
 */
export interface PendingModule {
  available: false
  phase: number
}

export interface CountedLabel {
  label: string
  count: number
}

export interface MemberStats {
  total: number
  active: number
  by_status: Record<MemberStatusCode, CountedLabel>
  by_role: Record<RoleCode, CountedLabel>
  with_account: number
  without_account: number
  joined_this_month: number
  growth: { month: string; label: string; count: number }[]
}

/** Activité sportive du club — mesurée depuis la phase 6. */
export interface ClubActivityStats {
  available: true
  total: number
  distance_m: number
  moving_time_s: number
  this_month: number
}

export interface DashboardStats {
  members: MemberStats
  activities: ClubActivityStats
  events: PendingModule
  participations: PendingModule
  /** `visible: false` quand le club garde la caisse privée. */
  finance: { visible: boolean; available?: false; phase?: number }
  generated_at: string
}

/* -------------------------------------------------------------------------- */
/* Activités et GPS (phase 6)                                                  */
/* -------------------------------------------------------------------------- */

export type ActivityStatusCode = 'RECORDING' | 'COMPLETED' | 'DISCARDED'
export type ActivityVisibilityCode = 'PRIVATE' | 'CLUB' | 'PUBLIC'

export interface Coordinate {
  lat: number
  lng: number
}

export interface ActivityBounds {
  min_lat: number
  min_lng: number
  max_lat: number
  max_lng: number
}

/** Un kilomètre de la sortie. */
export interface ActivitySplit {
  km: number
  duration_s: number
  pace_s_per_km: number
  speed_mps: number
}

/**
 * Qualité du signal GPS de la sortie.
 *
 * Réservé au propriétaire : c'est SA mesure, et le premier élément à regarder
 * quand une trace lui paraît fausse.
 */
export interface ActivitySignal {
  raw_points_count: number
  filtered_out: number
  quality_percent: number | null
}

/**
 * Une sortie enregistrée.
 *
 * **Toutes les grandeurs sont en unités SI** : mètres, secondes, m/s. La
 * conversion en km, km/h et min/km est faite à l'affichage — voir
 * `lib/format.ts`. C'est ce qui évite les bugs de type « km stocké, mètres
 * affichés ».
 */
export interface Activity {
  uuid: string
  title: string
  custom_title: string | null
  notes?: string | null

  sport: SportCode
  sport_label: string
  uses_pace: boolean

  status: ActivityStatusCode
  status_label: string
  visibility: ActivityVisibilityCode
  visibility_label: string

  started_at: string | null
  ended_at: string | null

  distance_m: number
  duration_s: number
  moving_time_s: number
  paused_time_s: number
  avg_speed_mps: number
  max_speed_mps: number
  elevation_gain_m: number
  elevation_loss_m: number
  min_altitude_m: number | null
  max_altitude_m: number | null
  avg_pace_s_per_km: number | null
  best_pace_s_per_km: number | null
  calories_kcal: number | null

  /** Trace simplifiée, encodée au format Google Polyline (~1 Ko). */
  polyline: string | null
  bounds: ActivityBounds | null
  start: Coordinate | null
  end: Coordinate | null
  zones: string[]

  points_count: number
  signal?: ActivitySignal

  member?: {
    uuid: string
    full_name: string
    initials: string
    photo_url: string | null
  }

  stats?: {
    splits: ActivitySplit[]
    elevation_profile: { d: number; a: number }[]
    speed_histogram: Record<string, number>
  }

  synced_at: string | null
  created_at: string | null

  permissions?: {
    update: boolean
    delete: boolean
  }
}

/** Réponse de POST /activities/{uuid}/points. */
export interface PointsIngestResult {
  received: number
  accepted: number
  rejected: number
  rejection_reasons: Record<string, number>
  last_seq: number | null
  total_points: number
}

/* -------------------------------------------------------------------------- */
/* Cumuls et records personnels (phase 8)                                      */
/* -------------------------------------------------------------------------- */

export type StatsPeriod = 'week' | 'month' | 'year' | 'all'

export interface PersonalTotals {
  activities: number
  distance_m: number
  moving_time_s: number
  duration_s: number
  elevation_gain_m: number
  avg_speed_mps: number
}

export interface SportBreakdown {
  label: string
  activities: number
  distance_m: number
  moving_time_s: number
}

/**
 * Un record personnel.
 *
 * `null` et non zéro quand il n'existe pas : « pas encore de record » et
 * « record de 0 km » ne veulent pas dire la même chose, et l'affichage montre
 * un tiret dans le premier cas.
 */
export interface PersonalRecord {
  value: number
  activity_uuid: string
  activity_title: string
  sport: SportCode
  achieved_at: string | null
}

export interface PersonalRecords {
  longest_distance: PersonalRecord | null
  longest_duration: PersonalRecord | null
  max_speed: PersonalRecord | null
  most_elevation: PersonalRecord | null
  best_pace: PersonalRecord | null
}

export interface WeeklyTrendPoint {
  week: string
  label: string
  distance_m: number
  activities: number
}

export interface PersonalStats {
  period: StatsPeriod
  period_label: string
  /** `null` pour la période « depuis toujours ». */
  period_from: string | null
  totals: PersonalTotals
  by_sport: Record<string, SportBreakdown>
  /** Records sur TOUTE la carrière, jamais sur la période affichée. */
  records: PersonalRecords
  trend: WeeklyTrendPoint[]
}
