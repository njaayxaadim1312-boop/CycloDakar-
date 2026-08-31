import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, type RenderOptions } from '@testing-library/react-native'
import type { ReactElement } from 'react'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { api } from '../src/lib/api'
import { ThemeProvider } from '../src/theme/useTheme'
import type { CurrentUser, Member, MemberSearchResult } from '../src/types/api'

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
  activities: { available: false, phase: 8 },
  events: { available: false, phase: 9 },
  participations: { available: false, phase: 10 },
  finance: { visible: false },
  generated_at: '2026-08-31T16:00:00+00:00',
  ...overrides,
})
