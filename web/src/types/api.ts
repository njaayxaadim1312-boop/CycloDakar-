/**
 * Types du contrat d'API Cyclo Dakar (v1).
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
  /**
   * L'image de fond du compte — le « fond d'écran » du membre.
   *
   * `null` quand il n'en a pas choisi : c'est au client de décider quoi
   * afficher. Le serveur ne renvoie pas d'image par défaut, sans quoi on
   * ne distinguerait plus « n'a rien choisi » de « a choisi ceci ».
   */
  cover_url: string | null
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

/**
 * Reste à collecter, sur le tableau de bord.
 *
 * `visible: false` quand le compte n'a pas le droit de collecter : un zéro
 * laisserait croire que le club n'attend rien.
 */
export type ParticipationDashboard =
  | { visible: false }
  | {
      visible: true
      available: true
      open_campaigns: number
      expected_amount: Fcfa
      collected_amount: Fcfa
      remaining_amount: Fcfa
      lines: number
    }

export interface DashboardStats {
  members: MemberStats
  activities: ClubActivityStats
  events: ClubEventStats
  /** `visible: false` sous le rôle de collecteur — et non un zéro. */
  participations: ParticipationDashboard
  /** `visible: false` quand le club garde la caisse privée. */
  /**
   * La caisse.
   *
   * `visible: false` quand le lecteur n'y a pas droit : on ne montre alors
   * RIEN, plutôt qu'un zéro qui laisserait croire que la caisse est vide.
   *
   * `committed` est renvoyé À CÔTÉ du solde, jamais déduit de lui : une
   * dépense en attente n'a aucune ligne au grand livre (règle I4).
   */
  finance: {
    visible: boolean
    available?: boolean
    phase?: number
    balance?: Fcfa
    committed?: Fcfa
    pending_expenses?: number
  }
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
/* Participations (phase 10)                                                   */
/* -------------------------------------------------------------------------- */

export type ParticipationStatusCode = 'DRAFT' | 'OPEN' | 'CLOSED' | 'CANCELLED'

export type ParticipationLineStatusCode =
  | 'NON_PAYE'
  | 'PARTIELLEMENT_PAYE'
  | 'PAYE'
  | 'ANNULE'

/**
 * Le suivi du bureau, en trois chiffres.
 *
 * Calculé par le serveur, jamais déduit ici : deux clients qui
 * additionneraient différemment afficheraient deux « restes à collecter », ce
 * qui est inacceptable sur de l'argent.
 */
export interface ParticipationTally {
  expected_amount: Fcfa
  collected_amount: Fcfa
  remaining_amount: Fcfa
  members: number
  paid_members: number
  progress_percent: number
}

/**
 * Une dette : ce qu'un membre doit sur une collecte.
 *
 * `paid_amount` et `status` sont **dérivés** des paiements réels et exposés en
 * lecture seule. Aucune route ne les accepte en entrée.
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

/** Une campagne de collecte. Tous les montants sont des entiers de FCFA. */
export interface Participation {
  uuid: string
  name: string
  description: string | null

  status: ParticipationStatusCode
  status_label: string

  /** Part unitaire attendue de chaque membre. */
  expected_amount: Fcfa

  starts_on: string | null
  due_on: string | null
  is_overdue: boolean

  tally: ParticipationTally

  event?: { uuid: string; title: string; starts_at: string | null }
  created_by?: { uuid: string; name: string }
  lines?: ParticipationLine[]

  permissions: {
    update: boolean
    delete: boolean
    assign: boolean
  } | null

  created_at: string | null
}

/* -------------------------------------------------------------------------- */
/* Encaissements — PHASE 12                                                   */
/* -------------------------------------------------------------------------- */

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

  /**
   * Dépenses décidées mais pas encore approuvées.
   *
   * Informatif, et JAMAIS déduit du solde : une dépense en attente n'a aucune
   * ligne au grand livre. La confondre avec le solde ferait décider le
   * trésorier sur un chiffre faux (docs/finance.md, règle I4).
   */
  committed: Fcfa
  balance_after_commitments: Fcfa
  pending_expenses: number

  complete: boolean
  incomplete_reason: string | null
}

/* -------------------------------------------------------------------------- */
/* Caisse, dépenses et journal — PHASE 13                                     */
/* -------------------------------------------------------------------------- */

export type TransactionDirectionCode = 'IN' | 'OUT'

export type ExpenseStatusCode = 'PENDING' | 'APPROVED' | 'REJECTED'

/** Un poste du grand livre. Le SENS en fait partie. */
export interface LedgerCategory {
  code: string
  name: string
  /**
   * `IN` ou `OUT`. Sans ce champ, un formulaire de recette proposerait
   * « Transport », et le rapport annuel serait faux sans que rien ne
   * s'en aperçoive.
   */
  direction: TransactionDirectionCode
}

/** Un justificatif de dépense. */
export interface ExpenseAttachment {
  uuid: string
  name: string
  mime_type: string
  size_bytes: number
  is_image: boolean
  /**
   * URL d'une route CONTRÔLÉE, jamais un chemin dans `/storage`.
   *
   * Une facture porte un fournisseur, un montant, parfois un numéro de compte :
   * elle n'a rien à faire dans un répertoire public.
   */
  url: string
}

/**
 * Une dépense.
 *
 * `is_commitment` dit que le montant est **engagé mais pas encore sorti**.
 * Sans lui, une interface additionnerait des dépenses dont une partie n'a
 * jamais quitté la caisse, et le solde n'aurait plus l'air de correspondre.
 */
export interface Expense {
  uuid: string
  /** Entier de FCFA. */
  amount: Fcfa
  label: string
  description: string | null
  supplier: string | null
  reference: string | null

  status: ExpenseStatusCode
  status_label: string
  moved_money: boolean
  is_commitment: boolean

  category?: { code: string; name: string }
  event?: { uuid: string; title: string } | null

  spent_on: string
  created_at: string | null

  requested_by?: { uuid: string; name: string }
  approved_by?: { uuid: string; name: string } | null
  decided_at: string | null
  decision_reason: string | null

  attachments?: ExpenseAttachment[]

  /**
   * Décidées par le serveur. Le client s'en sert pour MASQUER, jamais pour
   * autoriser : un approbateur ne peut pas approuver sa propre dépense, et
   * cette règle ne se devine pas côté client.
   */
  permissions: {
    approve: boolean
    reject: boolean
    update: boolean
  } | null
}

export interface ExpenseInput {
  category: string
  /** Entier de FCFA. */
  amount: number
  label: string
  description?: string | null
  supplier?: string | null
  reference?: string | null
  event?: string | null
  spent_on?: string
}

/** Une ligne du journal de caisse. */
export interface LedgerEntry {
  uuid: string
  direction: TransactionDirectionCode
  direction_label: string
  /** Toujours POSITIF : le signe est porté par `direction`. */
  amount: Fcfa
  /**
   * Le solde figé à l'écriture, LU et jamais recalculé à l'affichage.
   *
   * Il suit l'ordre d'ENREGISTREMENT, pas la date métier : trié par
   * `occurred_on`, il n'est donc pas monotone dès qu'une saisie a été
   * antidatée. C'est la réalité d'une caisse tenue à la main.
   */
  balance_after: Fcfa
  label: string
  source_type: string
  source_label: string
  category?: { code: string; name: string } | null
  event?: { uuid: string; title: string } | null
  reverses?: { uuid: string; label: string } | null
  reason: string | null
  occurred_on: string
  created_at: string | null
  author?: { uuid: string; name: string }
}

export interface IncomeInput {
  category: string
  amount: number
  label: string
  event?: string | null
  occurred_on?: string
}

/**
 * Le tableau de bord de la caisse.
 *
 * `committed` et `receivable` ne sont PAS de la trésorerie, et portent
 * volontairement d'autres noms que `balance` : les additionner ferait croire au
 * bureau qu'il peut engager une dépense sur de l'argent qui n'est pas arrivé.
 */
export interface CashDashboard {
  balance: Fcfa
  /** Dépenses décidées mais pas encore approuvées. Informatif. */
  committed: Fcfa
  balance_after_commitments: Fcfa

  income: Fcfa
  expenses: Fcfa
  net: Fcfa

  by_category: Array<{
    direction: TransactionDirectionCode
    code: string
    name: string
    amount: Fcfa
    operations: number
  }>

  /** Ce qui reste à percevoir sur les collectes ouvertes. Pas de la caisse. */
  receivable: Fcfa
}

/**
 * Un rapport financier de période.
 *
 * Tout y est CALCULÉ depuis le grand livre, rien n'est stocké : un rapport qui
 * conserverait ses totaux finirait par contredire le journal — après une
 * contre-passation, par exemple — et deux chiffres qui se contredisent sur de
 * l'argent, c'est la confiance du bureau perdue.
 */
export interface FinancialReport {
  period: { from: string; to: string; label: string }
  account: { name: string }

  summary: {
    /** Solde à la veille du premier jour, en DATE MÉTIER. */
    opening_balance: Fcfa
    income: Fcfa
    expenses: Fcfa
    net: Fcfa
    closing_balance: Fcfa
    /**
     * Dépenses engagées à la date d'ÉDITION, pas à la fin de la période.
     *
     * Une dépense en attente n'a pas de date de sortie — elle n'a pas encore
     * eu lieu. La rattacher à la période donnerait un chiffre qui changerait
     * chaque fois qu'on ressort le rapport.
     */
    committed_today: Fcfa
  }

  by_category: {
    income: Array<{ code: string; name: string; amount: Fcfa; operations: number }>
    expenses: Array<{ code: string; name: string; amount: Fcfa; operations: number }>
  }

  /** Situation des collectes à la date d'édition, volontairement hors période. */
  participations: { expected: Fcfa; collected: Fcfa; remaining: Fcfa }

  /** Un point par jour où il s'est passé quelque chose. */
  daily: Array<{ date: string; income: Fcfa; expenses: Fcfa; balance: Fcfa }>

  entries: Array<{
    date: string
    label: string
    category: string
    direction: TransactionDirectionCode
    income: Fcfa
    expense: Fcfa
    balance_after: Fcfa
    author: string
    reason: string | null
  }>

  generated_at: string
}

/* -------------------------------------------------------------------------- */
/* Classements et défis — PHASE 16                                            */
/* -------------------------------------------------------------------------- */

export type ChallengeMetricCode = 'distance' | 'activities' | 'duration' | 'elevation'

export type ChallengeStatusCode = 'DRAFT' | 'PUBLISHED' | 'CANCELLED'

export type LeaderboardPeriod = 'week' | 'month' | 'year'

/**
 * Une ligne de classement.
 *
 * `value` est en **unité SI** — mètres, secondes, ou nombre de sorties selon la
 * mesure. La conversion se fait à l'affichage, comme partout ailleurs.
 *
 * `rank` gère les ex æquo à la convention du sport : deux membres à égalité
 * partagent le rang, et le suivant saute une place.
 */
export interface LeaderboardEntry {
  rank: number
  member: {
    uuid: string
    full_name: string
    initials: string
    matricule: string
    photo_url: string | null
  }
  member_id: number
  value: number
  /** Le nombre de sorties qui composent ce total. */
  activities: number
}

/**
 * Le rang du lecteur, même hors du classement affiché.
 *
 * `rank: null` signifie « pas classé » — il n'a rien fait sur la période. Ce
 * n'est pas la même chose qu'un rang absent, qui voudrait dire « pas encore
 * chargé ».
 */
export interface MyRank {
  rank: number | null
  value: number
  activities: number
  total: number
  /**
   * Présent seulement quand le lecteur est classé.
   *
   * C'est ce qui permet à l'interface de surligner sa ligne dans la liste sans
   * avoir à deviner : `CurrentUser` ne porte pas la fiche club, et comparer
   * sur le nom serait faux le jour où deux membres s'appellent pareil.
   */
  member?: {
    uuid: string
    full_name: string
    initials: string
    matricule: string
    photo_url: string | null
  }
}

export interface LeaderboardMeta {
  period: LeaderboardPeriod
  period_key: string
  metric: ChallengeMetricCode
  metric_label: string
  unit: string
  sport: SportCode | null
  /**
   * Ce classement est-il FIGÉ ?
   *
   * Une période close ne bouge plus ; une période en cours peut encore
   * changer. Le membre qui regarde a le droit de savoir lequel des deux il a
   * sous les yeux — et l'interface le lui dit.
   */
  frozen: boolean
  me: MyRank | null
}

/** Un défi du club. */
export interface Challenge {
  uuid: string
  title: string
  description: string | null

  metric: ChallengeMetricCode
  metric_label: string
  /** `m`, `s` ou `sorties`. */
  unit: string
  /** En unité SI. « 500 km » vaut 500000. */
  target: number

  sport: SportCode | null
  sport_label: string | null

  icon: string

  starts_on: string
  ends_on: string
  days_left: number | null
  is_running: boolean
  accepts_entries: boolean

  status: ChallengeStatusCode
  status_label: string

  participants: number
  finishers: number

  /**
   * `null` quand le lecteur ne participe pas.
   *
   * Différent d'une progression à zéro, qui voudrait dire « inscrit, rien
   * fait ». L'interface ne doit pas avoir à deviner.
   */
  my_progress: {
    value: number
    percent: number
    completed_at: string | null
    joined_at: string | null
  } | null

  created_by?: { uuid: string; name: string }

  permissions: {
    update: boolean
    delete: boolean
    join: boolean
  } | null
}

/** Une ligne du classement d'un défi. */
export interface ChallengeStanding {
  rank: number
  member: { uuid: string; full_name: string; initials: string; photo_url: string | null }
  progress: number
  percent: number
  /** Non nul = a réussi le défi. Les finisseurs passent devant. */
  completed_at: string | null
}

/**
 * Un badge : un défi réellement réussi.
 *
 * Ce n'est pas une récompense inventée — c'est un défi avec ses règles, sa
 * période et sa date de réussite. Une taxonomie de badges détachée des défis
 * aurait demandé d'inventer des distinctions que le club n'a pas demandées.
 */
export interface Badge {
  challenge: {
    uuid: string
    title: string
    icon: string
    metric: ChallengeMetricCode
    target: number
    unit: string
  }
  completed_at: string
}

export interface ChallengeInput {
  title: string
  description?: string | null
  metric: ChallengeMetricCode
  /** En unité SI. */
  target: number
  sport?: SportCode | null
  starts_on: string
  ends_on: string
  icon?: string
  status?: ChallengeStatusCode
}

/* -------------------------------------------------------------------------- */
/* Notifications — PHASE 17                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Une notification.
 *
 * `code` est un identifiant STABLE — `payment.received`, `event.reminder` — et
 * non le nom de la classe PHP. Le client choisit son icône et sa destination
 * dessus ; exposer le nom de classe ferait fuiter l'arborescence du code dans
 * l'API, et la renommer casserait les notifications déjà en base.
 *
 * `payload` porte ce qui est propre à chaque type — un numéro de reçu, un
 * montant. Un client qui ne connaît pas un type doit pouvoir l'afficher quand
 * même : titre, corps et destination suffisent, et une version d'application
 * plus ancienne que le serveur continue donc de fonctionner.
 */
export interface AppNotification {
  id: string
  code: string
  title: string
  body: string
  url: string | null
  icon: string
  read: boolean
  read_at: string | null
  created_at: string | null
  payload: Record<string, unknown>
}

export interface NotificationsMeta {
  unread: number
  current_page: number
  last_page: number
  total: number
  has_more: boolean
}

/** Un appareil enregistré pour le push. Pas de jeton, pas de push. */
export interface DeviceInput {
  token: string
  device_name?: string | null
  platform?: string | null
}
