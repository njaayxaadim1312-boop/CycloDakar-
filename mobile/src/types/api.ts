/**
 * Types du contrat d'API Cyclo Dakar (v1) — mobile.
 *
 * Miroir de `web/src/types/api.ts`. Le web et le mobile consomment la meme
 * API : ces deux fichiers doivent rester identiques.
 *
 * Ce fichier reflète les réponses de `backend/routes/api.php`. Il sera généré
 * automatiquement depuis le schéma OpenAPI à partir de la phase 19 ; d'ici là
 * il est maintenu à la main et fait foi côté client.
 */

/** Montant en francs CFA. Toujours un ENTIER — le XOF n'a pas de centimes. */
export type Fcfa = number

export type SportCode = 'CYCLING' | 'RUNNING' | 'HIKING' | 'WALKING'

export type RoleCode =
  | 'MEMBER'
  /** Chef de groupe : planifie les sorties, trace l'itinéraire, pointe. */
  | 'RIDE_LEADER'
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
  /**
   * Encadrer une sortie : la planifier, en tracer l'itinéraire, pointer.
   *
   * Volontairement distinct de `collect` : c'est cette séparation qui permet
   * de nommer un chef de groupe sans lui confier la caisse.
   */
  lead_rides: boolean
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

/** Sorties officielles — mesurées depuis la phase 9. */
export interface ClubEventStats {
  available: true
  upcoming: number
  /** `null` pour un compte sans fiche membre. */
  my_upcoming: number | null
  next: {
    uuid: string
    title: string
    starts_at: string | null
    location_name: string
  } | null
}

export interface DashboardStats {
  members: MemberStats
  activities: ClubActivityStats
  events: ClubEventStats
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

/** Objectifs hebdomadaires. Unités SI : mètres et secondes. */
export interface WeeklyGoals {
  distance_m: number
  moving_time_s: number
  activities: number
}

/**
 * Un anneau.
 *
 * `percent` vaut `null` quand l'objectif est à zéro — désactivé, et non
 * atteint à 100 %. Il peut dépasser 100 : le serveur n'écrête pas, une
 * semaine à 150 % mérite de se voir.
 */
export interface RingMetric {
  value: number
  goal: number
  percent: number | null
  completed: boolean
}

export interface WeekDay {
  date: string
  label: string
  distance_m: number
  active: boolean
}

/** Anneaux de la semaine en cours, quelle que soit la période affichée. */
export interface WeekRings {
  week_start: string
  metrics: {
    distance_m: RingMetric
    moving_time_s: RingMetric
    activities: RingMetric
  }
  days: WeekDay[]
}

export interface PersonalStats {
  period: StatsPeriod
  period_label: string
  /** `null` pour la période « depuis toujours ». */
  period_from: string | null
  totals: PersonalTotals
  goals: WeeklyGoals
  by_sport: Record<string, SportBreakdown>
  /** Records sur TOUTE la carrière, jamais sur la période affichée. */
  records: PersonalRecords
  trend: WeeklyTrendPoint[]
  /** Toujours la semaine en cours, même quand `period` vaut `year`. */
  rings: WeekRings
}

/* -------------------------------------------------------------------------- */
/* Événements (phase 9)                                                        */
/* -------------------------------------------------------------------------- */

export type EventStatusCode = 'DRAFT' | 'PUBLISHED' | 'ONGOING' | 'DONE' | 'CANCELLED'
export type EventDifficultyCode = 'EASY' | 'MEDIUM' | 'HARD'
export type RegistrationStatusCode = 'REGISTERED' | 'WAITLIST' | 'CANCELLED'
export type AttendanceStatusCode = 'UNKNOWN' | 'PRESENT' | 'ABSENT'

/** L'inscription du membre connecté. `null` s'il n'est pas inscrit. */
export interface MyRegistration {
  status: RegistrationStatusCode
  status_label: string
  /** Rang dans la file d'attente, `null` dès qu'une place est obtenue. */
  queue_position: number | null
  attendance_status: AttendanceStatusCode
  registered_at: string | null
}

export interface EventParticipant {
  member?: {
    uuid: string
    matricule: string
    full_name: string
    initials: string
    photo_url: string | null
  }
  registration_status: RegistrationStatusCode
  registration_status_label: string
  queue_position: number | null
  registered_at: string | null
  attendance_status: AttendanceStatusCode
  attendance_status_label: string
  checked_in_at: string | null
  checked_in_by?: { uuid: string; name: string }
  activity_uuid?: string | null
}

/**
 * Une sortie officielle du club.
 *
 * `planned_distance_m` est en MÈTRES, comme toutes les distances de l'API.
 * `seats_left` vaut `null` quand la sortie n'est pas limitée — et non un
 * grand nombre, qui laisserait croire à une limite haute.
 */
export interface ClubEvent {
  uuid: string
  title: string
  description: string | null

  sport: SportCode
  sport_label: string

  status: EventStatusCode
  status_label: string

  starts_at: string | null
  ends_at: string | null

  location_name: string
  start_lat: number | null
  start_lng: number | null

  planned_distance_m: number | null
  route_polyline: string | null

  difficulty: EventDifficultyCode | null
  difficulty_label: string | null
  difficulty_hint: string | null

  max_participants: number | null
  seats_taken: number
  seats_left: number | null
  is_full: boolean

  registrations_open: boolean
  my_registration: MyRegistration | null

  created_by?: { uuid: string; name: string }
  participants?: EventParticipant[]

  permissions: {
    update: boolean
    delete: boolean
    manage_attendance: boolean
  } | null

  created_at: string | null
}

/** Compteurs renvoyés à chaque mouvement d'inscription. */
export interface EventTally {
  registered: number
  waitlist: number
  cancelled: number
  present: number
  max_participants: number | null
  seats_left: number | null
}

/* -------------------------------------------------------------------------- */
/* Encaissements — PHASE 12                                                   */
/* -------------------------------------------------------------------------- */

export type ParticipationStatusCode = 'DRAFT' | 'OPEN' | 'CLOSED' | 'CANCELLED'

export type ParticipationLineStatusCode =
  | 'NON_PAYE'
  | 'PARTIELLEMENT_PAYE'
  | 'PAYE'
  | 'ANNULE'

/**
 * Une dette : ce qu'un membre doit sur une collecte.
 *
 * `paid_amount` et `status` sont **dérivés** des paiements réels et exposés en
 * lecture seule. Aucune route ne les accepte en entrée.
 *
 * Miroir de `web/src/types/api.ts`. Le mobile n'affiche pas encore les
 * campagnes de collecte elles-mêmes : il n'a besoin que de la ligne de dette,
 * pour l'écran « ce que je dois » et pour l'encaissement après un scan.
 */
export interface ParticipationLine {
  id: number
  member?: {
    uuid: string
    matricule: string
    full_name: string
    initials: string
    photo_url: string | null
    phone_formatted: string | null
  }
  expected_amount: Fcfa
  paid_amount: Fcfa
  remaining_amount: Fcfa
  status: ParticipationLineStatusCode
  status_label: string
  collector?: { uuid: string; name: string } | null
  /** La collecte d'origine — présente sur l'écran de terrain du collecteur. */
  participation?: {
    uuid: string
    name: string
    status: ParticipationStatusCode
    due_on: string | null
  }
  last_payment_at: string | null
  note: string | null
  /**
   * Le droit d'encaisser SUR CETTE LIGNE, décidé par le serveur.
   *
   * Un collecteur n'encaisse que les dettes qui lui sont assignées ; le
   * trésorier passe partout. Deviner cette règle ici produirait un bouton qui
   * répond 403 — au bord d'une route, avec un membre qui attend.
   */
  can_pay: boolean
}

/**
 * Un reçu.
 *
 * Ce que ce type ne contient PAS mérite d'être noté : aucun solde de caisse.
 * Le solde n'est pas l'affaire de celui qui paie sa cotisation, et le glisser
 * dans un reçu l'exposerait à tout membre par la porte de derrière.
 *
 * Une annulation est **visible**, avec son motif : cacher qu'un reçu a été
 * annulé serait le meilleur moyen qu'un membre se croie à jour.
 */
export interface Payment {
  uuid: string
  /** `RC-2026-000042`. C'est ce qu'un membre montre quand il conteste. */
  receipt_number: string

  /** Entier de FCFA, jamais formaté ni arrondi. */
  amount: Fcfa

  method: PaymentMethodCode
  method_label: string
  reference: string | null
  note: string | null

  /** Date métier, distincte du jour de la saisie. */
  paid_on: string
  created_at: string

  member?: { uuid: string; matricule: string; full_name: string }
  participation?: { uuid: string; name: string }
  collector?: { uuid: string; name: string }

  cancelled: boolean
  cancelled_at: string | null
  cancellation_reason: string | null
  cancelled_by?: string | null
}

/**
 * Saisie d'un encaissement.
 *
 * `idempotency_key` est OBLIGATOIRE et c'est délibéré : elle protège le membre
 * d'un double débit quand le réseau lâche entre l'envoi et la réponse. Le
 * client la fabrique une fois et la RÉUTILISE à l'identique sur chaque
 * tentative de la même saisie — c'est ce qui distingue un rejeu d'un second
 * versement volontaire du même montant, lequel est légitime.
 *
 * Ce qui n'y figure pas et n'y figurera jamais : `collected_by`, `paid_amount`,
 * `status`. Le serveur les détermine (docs/finance.md, règle I3).
 */
export interface PaymentInput {
  /** L'uuid du membre — aucune clé interne ne circule. */
  member: string
  /** Entier de FCFA. */
  amount: number
  method: PaymentMethodCode
  reference?: string | null
  note?: string | null
  idempotency_key: string
  paid_on?: string
}

/** Ce qu'un membre doit et ce qu'il a payé. */
export interface MyDues {
  dues: ParticipationLine[]
  payments: Payment[]
}

export interface MyDuesTotals {
  expected_amount: Fcfa
  paid_amount: Fcfa
  remaining_amount: Fcfa
}

/**
 * Ce qu'un collecteur a encaissé sur une période.
 *
 * Les annulations sont comptées **à part**. Ce n'est pas une statistique de
 * confort : c'est le contrôle contre le risque qu'un collecteur encaisse et
 * garde (docs/finance.md §6). Les mélanger masquerait exactement ce qu'on
 * cherche à voir.
 */
export interface CollectorTally {
  collector: { uuid: string; name: string }
  collected_amount: Fcfa
  collected_count: number
  cancelled_amount: Fcfa
  cancelled_count: number
}

/**
 * L'état de la caisse.
 *
 * `complete` est à `false` tant que les dépenses ne sont pas saisies
 * (phase 13). Aucune interface ne doit présenter ce montant comme le solde
 * réel du club : confondre « tout ce qui est enregistré » et « tout ce qui
 * existe » ruinerait la confiance du bureau.
 */
export interface CashState {
  name: string
  opening_balance: Fcfa
  balance: Fcfa
  /** Le même solde recalculé depuis le grand livre. Un écart = une anomalie. */
  derived_balance: Fcfa
  complete: boolean
  incomplete_reason: string | null
}
