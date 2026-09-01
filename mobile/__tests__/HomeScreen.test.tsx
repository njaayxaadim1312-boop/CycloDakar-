import { fireEvent, screen } from '@testing-library/react-native'
import { ApiError, api } from '../src/lib/api'
import { HomeScreen } from '../src/screens/HomeScreen'
import { useAuth } from '../src/stores/auth'
import {
  anActivity,
  aUser,
  dashboardStats,
  mockApi,
  personalStats,
  renderScreen,
} from './helpers'

/**
 * Accueil mobile — **l'exercice, et rien d'autre**.
 *
 * L'écran s'ouvrait autrefois sur les effectifs du club. Ce qui est protégé
 * ici, c'est le renversement : le membre voit SES chiffres, et l'écran ne
 * parle plus ni d'effectifs, ni de caisse. Un membre sort son téléphone pour
 * enregistrer une sortie.
 *
 * La règle d'origine tient toujours : **aucun chiffre inventé**. Un compte
 * sans fiche membre le lit en clair plutôt que de voir des zéros.
 */
describe('HomeScreen', () => {
  beforeEach(() => {
    useAuth.setState({ user: aUser({ name: 'Awa Ndiaye' }), ready: true })
  })

  afterEach(() => {
    jest.restoreAllMocks()
    useAuth.setState({ user: null, ready: true })
  })

  const props = () => ({
    onOpenHistory: jest.fn(),
    onOpenActivity: jest.fn(),
    onOpenEvents: jest.fn(),
  })

  const routes = (overrides: Record<string, unknown> = {}) => ({
    '/stats/me': { data: personalStats() },
    '/stats/dashboard': { data: dashboardStats() },
    '/activities': {
      data: [anActivity()],
      meta: { current_page: 1, last_page: 1, per_page: 3, total: 1, has_more: false },
    },
    ...overrides,
  })

  it('salue le membre par son prénom seul', async () => {
    // Plus chaleureux, et surtout plus court sur un écran de téléphone.
    mockApi(routes())

    await renderScreen(<HomeScreen {...props()} />)

    expect(await screen.findByText('Bonjour Awa')).toBeOnTheScreen()
    expect(screen.queryByText('Bonjour Awa Ndiaye')).toBeNull()
  })

  it('ouvre sur MES chiffres de la semaine', async () => {
    mockApi(routes())

    await renderScreen(<HomeScreen {...props()} />)

    expect(await screen.findByText('CETTE SEMAINE')).toBeOnTheScreen()
    // 24 500 m parcourus sur un objectif de 20 000 m.
    expect(screen.getByText('24,5 km')).toBeOnTheScreen()
    expect(screen.getByText('/ 20,0 km')).toBeOnTheScreen()
  })

  it('ne parle plus des effectifs ni de la caisse', async () => {
    // Le renversement est le point de cette version : ces mots ne doivent
    // plus apparaître sur l'écran d'accueil.
    mockApi(routes())

    await renderScreen(<HomeScreen {...props()} />)
    await screen.findByText('CETTE SEMAINE')

    expect(screen.queryByText(/membres actifs/i)).toBeNull()
    expect(screen.queryByText(/sans compte/i)).toBeNull()
    expect(screen.queryByText(/caisse/i)).toBeNull()
    expect(screen.queryByText(/solde/i)).toBeNull()
  })

  it('montre la régularité de la semaine, jours creux compris', async () => {
    mockApi(routes())

    await renderScreen(<HomeScreen {...props()} />)

    expect(await screen.findByText('Régularité')).toBeOnTheScreen()
    // Deux jours actifs sur sept dans le jeu d'essai.
    expect(screen.getByText("2 jours d'activité sur les sept.")).toBeOnTheScreen()
  })

  it('liste mes dernières sorties', async () => {
    mockApi(routes())

    await renderScreen(<HomeScreen {...props()} />)

    expect(await screen.findByText('Dakar — Popenguine')).toBeOnTheScreen()
    expect(screen.getByText('118 km')).toBeOnTheScreen()
  })

  it('invite à enregistrer quand il n’y a encore rien', async () => {
    mockApi(
      routes({
        '/activities': {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 3, total: 0, has_more: false },
        },
      }),
    )

    await renderScreen(<HomeScreen {...props()} />)

    expect(
      await screen.findByText(/Touchez « Démarrer » pour votre première sortie/),
    ).toBeOnTheScreen()
  })

  it("le dit franchement quand le compte n'a pas de fiche membre", async () => {
    // Des cumuls à zéro laisseraient croire à une absence de sorties alors
    // que le problème est ailleurs.
    // Le reste de l'écran doit continuer de répondre normalement : seule
    // la requête des cumuls échoue.
    const rest = routes()

    jest.spyOn(api, 'get').mockImplementation((...args: unknown[]) => {
      const url = String(args[0]).split('?')[0] as string

      if (url.includes('/stats/me')) {
        return Promise.reject(
          new ApiError('Aucune fiche membre.', 404, { code: 'NO_MEMBER_PROFILE' }),
        )
      }

      const path = Object.keys(rest)
        .filter((candidate) => url.endsWith(candidate))
        .sort((a, b) => b.length - a.length)[0]

      return path === undefined
        ? Promise.reject(new Error(`Route non simulée : ${url}`))
        : Promise.resolve({ data: rest[path as keyof typeof rest] })
    })

    await renderScreen(<HomeScreen {...props()} />)

    expect(
      await screen.findByText(/pas encore rattaché à une fiche membre/),
    ).toBeOnTheScreen()
  })

  it('mène à mes sorties', async () => {
    mockApi(routes())

    const handlers = props()
    await renderScreen(<HomeScreen {...handlers} />)

    await fireEvent.press(await screen.findByText('Tout voir →'))

    expect(handlers.onOpenHistory).toHaveBeenCalled()
  })

  it('ouvre la sortie touchée', async () => {
    mockApi(routes())

    const handlers = props()
    await renderScreen(<HomeScreen {...handlers} />)

    await fireEvent.press(await screen.findByText('Dakar — Popenguine'))

    expect(handlers.onOpenActivity).toHaveBeenCalledWith('a-1')
  })

  it('mène à la prochaine sortie du club', async () => {
    mockApi(routes())

    const handlers = props()
    await renderScreen(<HomeScreen {...handlers} />)

    await fireEvent.press(await screen.findByText('Grand Tour Cyclo Dakar'))

    expect(handlers.onOpenEvents).toHaveBeenCalled()
  })
})
