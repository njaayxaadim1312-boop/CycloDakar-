import { fireEvent, screen, waitFor } from '@testing-library/react-native'
import AsyncStorage from '@react-native-async-storage/async-storage'
import { ProfileScreen } from '../src/screens/ProfileScreen'
import { useAuth } from '../src/stores/auth'
import { aMember, aUser, mockApi, renderScreen } from './helpers'

/**
 * Mon compte (mobile).
 *
 * Deux choses comptent ici : que le membre puisse enfin changer son mot de
 * passe depuis l'application, et que son choix de thème soit RETENU — un
 * membre qui roule avant le lever du jour ne doit pas le refaire à chaque
 * ouverture.
 */
describe('ProfileScreen', () => {
  beforeEach(() => {
    useAuth.setState({ user: aUser(), ready: true })
  })

  afterEach(() => {
    jest.restoreAllMocks()
    useAuth.setState({ user: null, ready: true })
  })

  function mockProfile() {
    return mockApi({
      '/members/me': {
        data: aMember({
          uuid: 'm-me',
          full_name: 'Awa Ndiaye',
          matricule: 'CD-000004',
          phone_formatted: '77 000 00 03',
          permissions: {
            update: true,
            update_status: false,
            update_role: false,
            manage_qr: true,
            delete: false,
          },
        }),
      },
    })
  }

  it('affiche la fiche club du membre connecté', async () => {
    mockProfile()

    await renderScreen(<ProfileScreen onOpenSystem={jest.fn()} />)

    expect(await screen.findByText('Awa Ndiaye')).toBeOnTheScreen()
    expect(screen.getByText('CD-000004')).toBeOnTheScreen()
    expect(screen.getByText('77 000 00 03')).toBeOnTheScreen()
  })

  it('offre enfin le changement de mot de passe', async () => {
    // L'API le permettait depuis la phase 2, mais aucun écran n'y menait.
    mockProfile()

    await renderScreen(<ProfileScreen onOpenSystem={jest.fn()} />)

    expect(screen.getByText('Changer mon mot de passe')).toBeOnTheScreen()
    expect(screen.getByLabelText('Mot de passe actuel')).toBeOnTheScreen()
    expect(screen.getByLabelText('Nouveau mot de passe')).toBeOnTheScreen()
  })

  it('retient le thème choisi', async () => {
    mockProfile()

    await renderScreen(<ProfileScreen onOpenSystem={jest.fn()} />)

    await fireEvent.press(screen.getByText('Sombre'))

    await waitFor(() => {
      expect(AsyncStorage.setItem).toHaveBeenCalledWith('cd.theme', 'dark')
    })
  })

  it('propose la rotation du QR Code à son propriétaire', async () => {
    mockProfile()

    await renderScreen(<ProfileScreen onOpenSystem={jest.fn()} />)

    expect(await screen.findByText('Régénérer mon QR Code')).toBeOnTheScreen()
  })

  it('mène au diagnostic système', async () => {
    mockProfile()

    const onOpenSystem = jest.fn()
    await renderScreen(<ProfileScreen onOpenSystem={onOpenSystem} />)

    await fireEvent.press(screen.getByText('État du système et diagnostic →'))

    expect(onOpenSystem).toHaveBeenCalled()
  })

  it("explique l'absence de fiche plutôt que d'afficher un écran vide", async () => {
    // Cas limite réel : un compte créé sans fiche club.
    const { ApiError } = require('../src/lib/api')
    jest
      .spyOn(require('../src/lib/api').api, 'get')
      .mockRejectedValue(new ApiError('Introuvable', 404))

    await renderScreen(<ProfileScreen onOpenSystem={jest.fn()} />)

    expect(
      await screen.findByText(/Aucune fiche membre n'est associée à votre compte/),
    ).toBeOnTheScreen()
  })
})
