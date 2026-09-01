import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, type RenderOptions } from '@testing-library/react-native'
import type { ReactElement } from 'react'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { api } from '../src/lib/api'
import { ThemeProvider } from '../src/theme/useTheme'
import type {
  Activity,
  ClubEvent,
  CurrentUser,
  Member,
  MemberSearchResult,
  PersonalStats,
} from '../src/types/api'

/**
 * Outils partagés des tests mobiles.
 *
 * L'API est simulée au niveau d'axios plutôt qu'au niveau des fonctions
 * `fetchMembers` / `searchMembers` : on teste ainsi la chaîne réelle, y compris
 * l'enveloppe `{ data }` et l'intercepteur d'erreurs, qui sont précisément
 * l'endroit où les régressions se glissent.
 */

/**
 * Encadre un écran des mêmes fournisseurs que l'application.
 *
 * `render` est ASYNCHRONE depuis @testing-library/react-native 14 : React 19
 * rend en mode concurrent, et la bibliothèque attend la fin du rendu avant de
 * rendre la main. Il faut donc l'attendre, sinon les requêtes ne trouvent rien.
 */
export async function renderScreen(ui: ReactElement, options?: RenderOptions) {
  const queryClient = new QueryClient({
    defaultOptions: {
      // Pas de nouvelle tentative en test : un échec doit être immédiat et
      // lisible, pas masqué par trois essais silencieux.
      queries: { retry: false, gcTime: 0 },
      mutations: { retry: false },
    },
  })

  return await render(
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <SafeAreaProvider
          initialMetrics={{
            frame: { x: 0, y: 0, width: 390, height: 844 },
            insets: { top: 47, left: 0, right: 0, bottom: 34 },
          }}
        >
          {ui}
        </SafeAreaProvider>
      </ThemeProvider>
    </QueryClientProvider>,
    options,
  )
}

/**
 * Branche des réponses simulées sur axios.
 * La clé est la fin du chemin appelé ; la correspondance la plus longue gagne,
 * pour que `/members/me` ne soit pas capté par `/me`.
 */
export function mockApi(routes: Record<string, unknown>) {
  return jest.spyOn(api, 'get').mockImplementation((url: string) => {
    const path = Object.keys(routes)
      .filter((p) => url.split('?')[0]!.endsWith(p))
      .sort((a, b) => b.length - a.length)[0]

    if (path === undefined) {
      return Promise.reject(new Error(`Route non simulée : ${url}`))
    }

    return Promise.resolve({ data: routes[path] })
  })
}

/* -------------------------------------------------------------------------- */
/* Jeux de données                                                            */
/* -------------------------------------------------------------------------- */

export const aUser = (overrides: Partial<CurrentUser> = {}): CurrentUser => ({
  uuid: 'u-1',
  name: 'Awa Ndiaye',
  email: 'awa@cyclodakar.sn',
  phone: '770000003',
  phone_formatted: '77 000 00 03',
  role: 'MEMBER',
  role_label: 'Membre',
  abilities: { collect: false, manage_finance: false, administer: false },
  is_active: true,
  last_login_at: null,
  created_at: null,
  ...overrides,
})

export const aMember = (overrides: Partial<Member> = {}): Member => ({
  uuid: 'm-1',
  matricule: 'CD-000042',
  first_name: 'Khadim',
  last_name: 'Ndiaye',
  full_name: 'Khadim Ndiaye',
  initials: 'KN',
  photo_url: null,
  status: 'ACTIVE',
  status_label: 'Actif',
  joined_at: '2024-03-01',
  seniority_years: 2,
  phone: '771234567',
  phone_formatted: '77 123 45 67',
  email: 'khadim@cyclodakar.sn',
  has_account: true,
  account: {
    uuid: 'u-2',
    role: 'COLLECTOR',
    role_label: 'Collecteur',
    is_active: true,
    last_login_at: null,
  },
  created_at: null,
  updated_at: null,
  permissions: {
    update: false,
    update_status: false,
    update_role: false,
    manage_qr: false,
    delete: false,
  },
  ...overrides,
})

export const aSearchResult = (
  overrides: Partial<MemberSearchResult> = {},
): MemberSearchResult => ({
  uuid: 's-1',
  matricule: 'CD-000042',
  full_name: 'Khadim Ndiaye',
  initials: 'KN',
  phone_formatted: '77 123 45 67',
  photo_url: null,
  status: 'ACTIVE',
  ...overrides,
})

export const dashboardStats = (overrides: Record<string, unknown> = {}) => ({
  members: {
    total: 12,
    active: 9,
    by_status: {
      ACTIVE: { label: 'Actif', count: 9 },
      PENDING: { label: 'En attente', count: 1 },
      SUSPENDED: { label: 'Suspendu', count: 1 },
      FORMER: { label: 'Ancien membre', count: 1 },
    },
    by_role: {
      MEMBER: { label: 'Membre', count: 1 },
      COLLECTOR: { label: 'Collecteur', count: 2 },
      TREASURER: { label: 'Trésorier', count: 1 },
      ADMIN: { label: 'Administrateur', count: 0 },
      SUPER_ADMIN: { label: 'Super administrateur', count: 1 },
    },
    with_account: 5,
    without_account: 7,
    joined_this_month: 6,
    growth: [{ month: '2026-08', label: 'août 26', count: 6 }],
  },
  activities: {
    available: true,
    total: 214,
    distance_m: 4_812_300,
    moving_time_s: 618_400,
    this_month: 19,
  },
  events: {
    available: true, upcoming: 3, my_upcoming: 1,
    next: {
      uuid: 'e-1', title: 'Grand Tour Cyclo Dakar',
      starts_at: '2026-09-08T07:30:00+00:00', location_name: 'Place de la Nation',
    },
  },
  participations: { available: false, phase: 10 },
  finance: { visible: false },
  generated_at: '2026-08-31T16:00:00+00:00',
  ...overrides,
})

/**
 * Cumuls et records personnels.
 *
 * Le jeu par défaut contient volontairement un record ABSENT
 * (`most_elevation`) : Dakar est plate, beaucoup de sorties finissent à 0 m,
 * et l'affichage doit montrer un tiret plutôt que « 0 m ».
 */
export const personalStats = (overrides: Partial<PersonalStats> = {}): PersonalStats => ({
  period: 'month',
  period_label: 'Ce mois-ci',
  period_from: '2026-08-01',
  totals: {
    activities: 7,
    distance_m: 214_500,
    moving_time_s: 28_900,
    duration_s: 31_200,
    elevation_gain_m: 340,
    avg_speed_mps: 7.422,
  },
  by_sport: {
    CYCLING: { label: 'Cyclisme', activities: 5, distance_m: 190_000, moving_time_s: 24_000 },
    RUNNING: { label: 'Course', activities: 2, distance_m: 24_500, moving_time_s: 4_900 },
    HIKING: { label: 'Randonnée', activities: 0, distance_m: 0, moving_time_s: 0 },
  },
  records: {
    longest_distance: {
      value: 118_400, activity_uuid: 'a-1', activity_title: 'Dakar — Popenguine',
      sport: 'CYCLING', achieved_at: '2026-04-12T06:30:00+00:00',
    },
    longest_duration: {
      value: 19_800, activity_uuid: 'a-1', activity_title: 'Dakar — Popenguine',
      sport: 'CYCLING', achieved_at: '2026-04-12T06:30:00+00:00',
    },
    max_speed: {
      value: 16.9, activity_uuid: 'a-2', activity_title: 'Corniche matin',
      sport: 'CYCLING', achieved_at: '2026-06-02T05:50:00+00:00',
    },
    most_elevation: null,
    best_pace: {
      value: 282, activity_uuid: 'a-3', activity_title: '10 km Ouakam',
      sport: 'RUNNING', achieved_at: '2026-07-19T06:10:00+00:00',
    },
  },
  trend: Array.from({ length: 12 }, (_, i) => ({
    week: `2026-06-${String(i + 1).padStart(2, '0')}`,
    label: `s${i + 1}`,
    distance_m: i % 3 === 0 ? 0 : (i + 1) * 8_000,
    activities: i % 3 === 0 ? 0 : 2,
  })),
  ...overrides,
})

export const anActivity = (overrides: Partial<Activity> = {}): Activity => ({
  uuid: 'a-1',
  title: 'Dakar — Popenguine',
  custom_title: null,
  notes: null,
  sport: 'CYCLING',
  sport_label: 'Cyclisme',
  uses_pace: false,
  status: 'COMPLETED',
  status_label: 'Terminée',
  visibility: 'CLUB',
  visibility_label: 'Club',
  started_at: '2026-04-12T06:30:00+00:00',
  ended_at: '2026-04-12T12:00:00+00:00',
  distance_m: 118_400,
  duration_s: 19_800,
  moving_time_s: 19_800,
  paused_time_s: 0,
  avg_speed_mps: 5.98,
  max_speed_mps: 16.9,
  elevation_gain_m: 210,
  elevation_loss_m: 205,
  min_altitude_m: 2,
  max_altitude_m: 74,
  avg_pace_s_per_km: null,
  best_pace_s_per_km: null,
  calories_kcal: null,
  polyline: null,
  bounds: null,
  start: null,
  end: null,
  zones: ['Ouakam', 'Popenguine'],
  points_count: 1_820,
  member: {
    uuid: 'm-me',
    full_name: 'Awa Ndiaye',
    initials: 'AN',
    photo_url: null,
  },
  synced_at: '2026-04-12T12:05:00+00:00',
  created_at: '2026-04-12T12:05:00+00:00',
  permissions: { update: true, delete: true },
  ...overrides,
})

/**
 * Une sortie officielle.
 *
 * Le jeu par defaut porte 24 inscrits sur 25 places, avec un inscrit et un
 * membre en liste d'attente : la configuration ou la distinction entre
 * « a une place » et « attend une place » doit rester visible.
 */
export const anEvent = (overrides: Partial<ClubEvent> = {}): ClubEvent => ({
  uuid: 'e-1',
  title: 'Grand Tour Cyclo Dakar',
  description: 'Depart groupe, ravitaillement a Keur Mbaye Fall.',
  sport: 'CYCLING',
  sport_label: 'Cyclisme',
  status: 'PUBLISHED',
  status_label: 'Annonce',
  starts_at: '2026-09-08T07:30:00+00:00',
  ends_at: '2026-09-08T11:00:00+00:00',
  location_name: 'Place de la Nation',
  start_lat: 14.6928,
  start_lng: -17.4467,
  planned_distance_m: 35_000,
  route_polyline: null,
  difficulty: 'MEDIUM',
  difficulty_label: 'Modere',
  difficulty_hint: 'Rythme soutenu, quelques relances',
  max_participants: 25,
  seats_taken: 24,
  seats_left: 1,
  is_full: false,
  registrations_open: true,
  my_registration: null,
  created_by: { uuid: 'u-1', name: 'Awa Ndiaye' },
  participants: [
    {
      member: {
        uuid: 'm-1', matricule: 'CD-000042', full_name: 'Khadim Ndiaye',
        initials: 'KN', photo_url: null,
      },
      registration_status: 'REGISTERED',
      registration_status_label: 'Inscrit',
      queue_position: null,
      registered_at: '2026-09-01T09:00:00+00:00',
      attendance_status: 'UNKNOWN',
      attendance_status_label: 'Non pointe',
      checked_in_at: null,
    },
    {
      member: {
        uuid: 'm-2', matricule: 'CD-000043', full_name: 'Aminata Cisse',
        initials: 'AC', photo_url: null,
      },
      registration_status: 'WAITLIST',
      registration_status_label: "Liste d'attente",
      queue_position: 1,
      registered_at: '2026-09-01T10:00:00+00:00',
      attendance_status: 'UNKNOWN',
      attendance_status_label: 'Non pointe',
      checked_in_at: null,
    },
  ],
  permissions: { update: false, delete: false, manage_attendance: false },
  created_at: '2026-09-01T08:00:00+00:00',
  ...overrides,
})
