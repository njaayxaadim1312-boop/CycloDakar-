import { fireEvent, screen } from '@testing-library/react-native'
import { ApiError, api } from '../src/lib/api'
import { HomeScreen } from '../src/screens/HomeScreen'
import { useAuth } from '../src/stores/auth'
import { aUser, dashboardStats, mockApi, personalStats, renderScreen } from './helpers'

/**
 * Accueil mobile.
 *
 * Le point à protéger : **aucun chiffre inventé**. Les effectifs sont réels,
 * les modules à venir affichent leur phase. Un jour cet écran montrera un
 * solde de caisse — confondre « rien » et « pas encore mesuré » y serait grave.
 */
describe('HomeScreen', () => {
  beforeEach(() => {
    useAuth.setState({ user: aUser({ name: 'Awa Ndiaye' }), ready: true })
  })

  afterEach(() => {
    jest.restoreAllMocks()
    useAuth.setState({ user: null, ready: true })
  })

  it("salue le membre par son prénom seul", async () => {
    // Plus chaleureux, et surtout plus court sur un écran de téléphone.
    mockApi({
      '/stats/dashboard': { data: dashboardStats() },
      '/stats/me': { data: personalStats() },
    })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    expect(screen.getByText('Bonjour Awa')).toBeOnTheScreen()
    expect(screen.queryByText('Bonjour Awa Ndiaye')).toBeNull()
  })

  it('affiche les effectifs réels du club', async () => {
    mockApi({
      '/stats/dashboard': { data: dashboardStats() },
      // Cumuls personnels volontairement décalés de ceux du club : sinon un
      // « 7 » lu à l'écran pourrait venir de l'un comme de l'autre, et le
      // test ne prouverait plus rien.
      '/stats/me': {
        data: personalStats({
          totals: {
            activities: 3,
            distance_m: 214_500,
            moving_time_s: 28_900,
            duration_s: 31_200,
            elevation_gain_m: 340,
            avg_speed_mps: 7.422,
          },
        }),
      },
    })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    expect(await screen.findByText('membres actifs')).toBeOnTheScreen()
    expect(screen.getByText('ce mois-ci')).toBeOnTheScreen()
    expect(screen.getByText('sans compte')).toBeOnTheScreen()

    // « 9 » apparait deux fois : le compteur d'actifs et la ligne « Actif »
    // de la repartition. C'est voulu -- on verifie donc la presence, pas
    // l'unicite.
    expect(screen.getAllByText('9')).toHaveLength(2)
    expect(screen.getByText('6')).toBeOnTheScreen() // adhesions du mois
    expect(screen.getByText('7')).toBeOnTheScreen() // membres sans compte
  })

  it("n'affiche que les statuts réellement présents", async () => {
    // Une ligne « Suspendu : 0 » n'apprend rien et allonge la liste.
    mockApi({
      '/stats/dashboard': {
        data: dashboardStats({
          members: {
            ...dashboardStats().members,
            by_status: {
              ACTIVE: { label: 'Actif', count: 9 },
              PENDING: { label: 'En attente', count: 0 },
              SUSPENDED: { label: 'Suspendu', count: 0 },
              FORMER: { label: 'Ancien membre', count: 2 },
            },
          },
        }),
      },
    })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    expect(await screen.findByText('Actif')).toBeOnTheScreen()
    expect(screen.getByText('Ancien membre')).toBeOnTheScreen()
    expect(screen.queryByText('Suspendu')).toBeNull()
  })

  it('annonce la phase des modules à venir plutôt qu’un zéro', async () => {
    mockApi({
      '/stats/dashboard': { data: dashboardStats() },
      '/stats/me': { data: personalStats() },
    })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    expect(await screen.findByText('Événements du club')).toBeOnTheScreen()
    expect(screen.getByText('P9')).toBeOnTheScreen()

    // L'enregistrement GPS est livré depuis la phase 6 : le laisser dans
    // « Prochainement » ferait passer une fonction disponible pour absente.
    expect(screen.queryByText('Enregistrement GPS des sorties')).toBeNull()
  })

  it('affiche mes cumuls du mois, pas ceux du club', async () => {
    mockApi({
      '/stats/dashboard': { data: dashboardStats() },
      '/stats/me': { data: personalStats() },
    })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    // 214 500 m personnels, et non les 4 812 km du club.
    expect(await screen.findByText('215 km')).toBeOnTheScreen()
    expect(screen.getByText('parcourus')).toBeOnTheScreen()
    expect(screen.getByText('8 h 01')).toBeOnTheScreen()
  })

  it("le dit franchement quand le compte n'a pas de fiche membre", async () => {
    // Cas réel : un compte créé en console. Des cumuls à zéro laisseraient
    // croire à une absence de sorties alors que le problème est ailleurs.
    jest.spyOn(api, 'get').mockImplementation((...args: unknown[]) => {
      const url = String(args[0])

      if (url.includes('/stats/me')) {
        return Promise.reject(
          new ApiError('Aucune fiche membre.', 404, { code: 'NO_MEMBER_PROFILE' }),
        )
      }

      return Promise.resolve({ data: { data: dashboardStats() } })
    })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    expect(
      await screen.findByText(/pas encore rattaché à une fiche membre/),
    ).toBeOnTheScreen()
  })

  it('mène à mes sorties', async () => {
    mockApi({
      '/stats/dashboard': { data: dashboardStats() },
      '/stats/me': { data: personalStats() },
    })

    const onOpenHistory = jest.fn()
    await renderScreen(
      <HomeScreen onOpenMembers={jest.fn()} onOpenHistory={onOpenHistory} />,
    )

    await fireEvent.press(await screen.findByText('Mes sorties →'))

    expect(onOpenHistory).toHaveBeenCalled()
  })

  it("explique l'échec au lieu d'afficher des cases vides", async () => {
    jest.spyOn(api, 'get').mockRejectedValue(new Error('offline'))

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} onOpenHistory={jest.fn()} />)

    expect(
      await screen.findByText(/Statistiques indisponibles/),
    ).toBeOnTheScreen()
  })

  it("mène à l'annuaire", async () => {
    mockApi({
      '/stats/dashboard': { data: dashboardStats() },
      '/stats/me': { data: personalStats() },
    })

    const onOpenMembers = jest.fn()
    await renderScreen(<HomeScreen onOpenMembers={onOpenMembers} onOpenHistory={jest.fn()} />)

    await fireEvent.press(screen.getByText('Annuaire →'))

    expect(onOpenMembers).toHaveBeenCalled()
  })
})
