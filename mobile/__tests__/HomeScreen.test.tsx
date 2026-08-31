import { fireEvent, screen } from '@testing-library/react-native'
import { HomeScreen } from '../src/screens/HomeScreen'
import { useAuth } from '../src/stores/auth'
import { aUser, dashboardStats, mockApi, renderScreen } from './helpers'

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
    mockApi({ '/stats/dashboard': { data: dashboardStats() } })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} />)

    expect(screen.getByText('Bonjour Awa')).toBeOnTheScreen()
    expect(screen.queryByText('Bonjour Awa Ndiaye')).toBeNull()
  })

  it('affiche les effectifs réels du club', async () => {
    mockApi({ '/stats/dashboard': { data: dashboardStats() } })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} />)

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

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} />)

    expect(await screen.findByText('Actif')).toBeOnTheScreen()
    expect(screen.getByText('Ancien membre')).toBeOnTheScreen()
    expect(screen.queryByText('Suspendu')).toBeNull()
  })

  it('annonce la phase des modules à venir plutôt qu’un zéro', async () => {
    mockApi({ '/stats/dashboard': { data: dashboardStats() } })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} />)

    expect(screen.getByText('Enregistrement GPS · phase 6')).toBeOnTheScreen()
    expect(screen.getByText('Enregistrement GPS des sorties')).toBeOnTheScreen()
  })

  it('laisse le bouton « Démarrer » visible mais désactivé', async () => {
    // Sa place est réservée : l'utilisateur sait dès maintenant où le chercher.
    mockApi({ '/stats/dashboard': { data: dashboardStats() } })

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} />)

    const button = screen.getByText('Démarrer une sortie')
    expect(button).toBeOnTheScreen()
    expect(button).toBeDisabled()
  })

  it("explique l'échec au lieu d'afficher des cases vides", async () => {
    jest
      .spyOn(require('../src/lib/api').api, 'get')
      .mockRejectedValue(new Error('offline'))

    await renderScreen(<HomeScreen onOpenMembers={jest.fn()} />)

    expect(
      await screen.findByText(/Statistiques indisponibles/),
    ).toBeOnTheScreen()
  })

  it("mène à l'annuaire", async () => {
    mockApi({ '/stats/dashboard': { data: dashboardStats() } })

    const onOpenMembers = jest.fn()
    await renderScreen(<HomeScreen onOpenMembers={onOpenMembers} />)

    await fireEvent.press(screen.getByText('Annuaire →'))

    expect(onOpenMembers).toHaveBeenCalled()
  })
})
