import { fireEvent, screen } from '@testing-library/react-native'
import { api } from '../src/lib/api'
import { EventDetailScreen } from '../src/screens/events/EventDetailScreen'
import { EventsScreen } from '../src/screens/events/EventsScreen'
import { useAuth } from '../src/stores/auth'
import { anEvent, aUser, mockApi, renderScreen } from './helpers'

/**
 * Calendrier et fiche d'une sortie.
 *
 * Le point à protéger : **le bouton doit dire ce qui va réellement se passer**.
 * Sur une sortie complète, proposer « Je participe » ferait croire à une place
 * qui n'existe pas, et le membre ne découvrirait qu'en arrivant au départ
 * qu'il était sur la liste d'attente.
 */
describe('Événements', () => {
  beforeEach(() => {
    useAuth.setState({ user: aUser(), ready: true })
  })

  afterEach(() => {
    jest.restoreAllMocks()
    useAuth.setState({ user: null, ready: true })
  })

  const list = (items = [anEvent()]) => ({
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 30, total: items.length, has_more: false },
  })

  /* ------------------------------------------------------------ calendrier */

  it('affiche les sorties à venir', async () => {
    mockApi({ '/events': list() })

    await renderScreen(<EventsScreen onOpenEvent={jest.fn()} />)

    expect(await screen.findByText('Grand Tour Cyclo Dakar')).toBeOnTheScreen()
    expect(screen.getByText('Place de la Nation')).toBeOnTheScreen()
    expect(screen.getByText('35,0 km')).toBeOnTheScreen()
    // Places occupées sur places totales, pas un pourcentage.
    expect(screen.getByText('24 / 25')).toBeOnTheScreen()
  })

  it("ne repete pas le statut normal sur chaque ligne", async () => {
    // Repeter « Annonce » douze fois n'apprend rien au lecteur.
    mockApi({ '/events': list([anEvent()]) })

    await renderScreen(<EventsScreen onOpenEvent={jest.fn()} />)
    await screen.findByText('Grand Tour Cyclo Dakar')

    expect(screen.queryByText('Annonce')).toBeNull()
  })

  it('montre en revanche une annulation', async () => {
    // Elle, doit sauter aux yeux : un membre qui ne la voit pas se deplace
    // pour rien.
    mockApi({
      '/events': list([anEvent({ status: 'CANCELLED', status_label: 'Annule' })]),
    })

    await renderScreen(<EventsScreen onOpenEvent={jest.fn()} />)

    expect(await screen.findByText('Annule')).toBeOnTheScreen()
  })

  it('signale ma place sur la liste d’attente', async () => {
    mockApi({
      '/events': list([
        anEvent({
          my_registration: {
            status: 'WAITLIST',
            status_label: "Liste d'attente",
            queue_position: 3,
            attendance_status: 'UNKNOWN',
            registered_at: '2026-09-01T09:00:00+00:00',
          },
        }),
      ]),
    })

    await renderScreen(<EventsScreen onOpenEvent={jest.fn()} />)

    expect(await screen.findByText('3ᵉ en attente')).toBeOnTheScreen()
  })

  it("dit qu'il n'y a rien plutôt que de laisser un écran vide", async () => {
    mockApi({ '/events': list([]) })

    await renderScreen(<EventsScreen onOpenEvent={jest.fn()} />)

    expect(
      await screen.findByText("Aucune sortie n'est prévue pour le moment."),
    ).toBeOnTheScreen()
  })

  it('ouvre la sortie touchée', async () => {
    mockApi({ '/events': list() })

    const onOpenEvent = jest.fn()
    await renderScreen(<EventsScreen onOpenEvent={onOpenEvent} />)

    await fireEvent.press(await screen.findByText('Grand Tour Cyclo Dakar'))

    expect(onOpenEvent).toHaveBeenCalledWith('e-1')
  })

  /* ----------------------------------------------------------------- fiche */

  it('propose de participer quand il reste des places', async () => {
    mockApi({ '/events/e-1': { data: anEvent() } })

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    expect(await screen.findByText('Je participe')).toBeOnTheScreen()
  })

  it('propose la liste d’attente quand la sortie est complète', async () => {
    // Le libellé doit dire la vérité : sinon le membre découvre au départ
    // qu'il n'avait pas de place.
    mockApi({
      '/events/e-1': {
        data: anEvent({ seats_taken: 25, seats_left: 0, is_full: true }),
      },
    })

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    expect(await screen.findByText("Rejoindre la liste d'attente")).toBeOnTheScreen()
    expect(screen.queryByText('Je participe')).toBeNull()
  })

  it('annonce ma position dans la file plutôt qu’un simple « inscrit »', async () => {
    mockApi({
      '/events/e-1': {
        data: anEvent({
          my_registration: {
            status: 'WAITLIST',
            status_label: "Liste d'attente",
            queue_position: 2,
            attendance_status: 'UNKNOWN',
            registered_at: '2026-09-01T09:00:00+00:00',
          },
        }),
      },
    })

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    expect(
      await screen.findByText("Vous êtes 2ᵉ sur la liste d'attente."),
    ).toBeOnTheScreen()
  })

  it('sépare les inscrits de la liste d’attente', async () => {
    // Confondre « a une place » et « attend une place » est précisément ce
    // qui fait qu'un membre se déplace pour rien.
    mockApi({ '/events/e-1': { data: anEvent() } })

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    expect(await screen.findByText('Khadim Ndiaye')).toBeOnTheScreen()
    expect(screen.getByText("LISTE D'ATTENTE")).toBeOnTheScreen()
    expect(screen.getByText('Aminata Cisse')).toBeOnTheScreen()
  })

  it("n'affiche pas « non pointé » comme une absence", async () => {
    // UNKNOWN et ABSENT ne veulent pas dire la même chose : on ne montre
    // rien tant que personne n'a pointé.
    mockApi({ '/events/e-1': { data: anEvent() } })

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    await screen.findByText('Khadim Ndiaye')

    expect(screen.queryByText(/Non pointé/)).toBeNull()
    expect(screen.queryByText(/Absent/)).toBeNull()
  })

  it('ferme le bouton quand les inscriptions sont closes', async () => {
    mockApi({
      '/events/e-1': {
        data: anEvent({
          status: 'DONE',
          status_label: 'Terminé',
          registrations_open: false,
        }),
      },
    })

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    expect(
      await screen.findByText('Les inscriptions sont fermées pour cette sortie.'),
    ).toBeOnTheScreen()
    expect(screen.queryByText('Je participe')).toBeNull()
  })

  it("explique l'échec au lieu d'afficher une fiche vide", async () => {
    jest.spyOn(api, 'get').mockRejectedValue(new Error('offline'))

    await renderScreen(<EventDetailScreen uuid="e-1" onBack={jest.fn()} />)

    expect(await screen.findByText(/introuvable, ou votre connexion/)).toBeOnTheScreen()
  })

  it('revient en arrière', async () => {
    mockApi({ '/events/e-1': { data: anEvent() } })

    const onBack = jest.fn()
    await renderScreen(<EventDetailScreen uuid="e-1" onBack={onBack} />)

    await fireEvent.press(screen.getByLabelText('Retour'))

    expect(onBack).toHaveBeenCalled()
  })
})
