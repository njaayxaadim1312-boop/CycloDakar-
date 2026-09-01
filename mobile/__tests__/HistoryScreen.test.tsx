import { fireEvent, screen } from '@testing-library/react-native'
import { api } from '../src/lib/api'
import { HistoryScreen } from '../src/screens/HistoryScreen'
import { useAuth } from '../src/stores/auth'
import { anActivity, aUser, mockApi, personalStats, renderScreen } from './helpers'

/**
 * Historique des sorties.
 *
 * Deux points sont réellement protégés ici :
 *
 * 1. **Les cumuls viennent de `/stats/me`, jamais de la page affichée.**
 *    Additionner les vingt sorties visibles pour fabriquer un total du mois
 *    serait un mensonge discret et très difficile à repérer une fois en
 *    production.
 * 2. **La liste est bornée à la période.** Le paramètre `from` doit suivre le
 *    filtre, sinon un membre lirait « Cette semaine » au-dessus de toutes ses
 *    sorties de l'année.
 */
describe('HistoryScreen', () => {
  beforeEach(() => {
    useAuth.setState({ user: aUser(), ready: true })
  })

  afterEach(() => {
    jest.restoreAllMocks()
    useAuth.setState({ user: null, ready: true })
  })

  const activities = (items = [anActivity()]) => ({
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 30, total: items.length, has_more: false },
  })

  it('affiche les cumuls de la période et les sorties', async () => {
    mockApi({
      '/stats/me': { data: personalStats() },
      '/activities': activities(),
    })

    await renderScreen(<HistoryScreen onBack={jest.fn()} onOpenActivity={jest.fn()} />)

    expect(await screen.findByText('Ce mois-ci')).toBeOnTheScreen()
    expect(screen.getByText('215 km')).toBeOnTheScreen()
    expect(screen.getByText('7')).toBeOnTheScreen()
    expect(screen.getByText('+340 m')).toBeOnTheScreen()

    expect(await screen.findByText('Dakar — Popenguine')).toBeOnTheScreen()
  })

  it("n'additionne pas la page affichée pour fabriquer le total", async () => {
    // Une seule sortie de 118 km est listée, mais le cumul du mois vaut
    // 215 km : c'est le serveur qui fait foi, pas ce qui tient à l'écran.
    mockApi({
      '/stats/me': { data: personalStats() },
      '/activities': activities(),
    })

    await renderScreen(<HistoryScreen onBack={jest.fn()} onOpenActivity={jest.fn()} />)

    expect(await screen.findByText('215 km')).toBeOnTheScreen()
    expect(screen.getByText('118 km')).toBeOnTheScreen()
  })

  it('borne la liste à la période choisie', async () => {
    const spy = mockApi({
      '/stats/me': { data: personalStats() },
      '/activities': activities(),
    })

    await renderScreen(<HistoryScreen onBack={jest.fn()} onOpenActivity={jest.fn()} />)
    await screen.findByText('Dakar — Popenguine')

    const call = spy.mock.calls.find(([url]) => String(url).includes('/activities'))

    expect(call).toBeDefined()
    expect(call?.[1]).toMatchObject({
      params: expect.objectContaining({ mine: 1, from: '2026-08-01' }),
    })
  })

  it('demande une autre période quand on change de filtre', async () => {
    const spy = mockApi({
      '/stats/me': { data: personalStats({ period: 'year', period_label: 'Cette année' }) },
      '/activities': activities(),
    })

    await renderScreen(<HistoryScreen onBack={jest.fn()} onOpenActivity={jest.fn()} />)
    await screen.findByText('Dakar — Popenguine')

    await fireEvent.press(screen.getByText('Année'))

    const asked = spy.mock.calls.some(
      ([url, config]) =>
        String(url).includes('/stats/me') &&
        (config as { params?: { period?: string } } | undefined)?.params?.period === 'year',
    )

    expect(asked).toBe(true)
  })

  it("dit qu'il n'y a rien plutôt que de laisser un écran vide", async () => {
    mockApi({
      '/stats/me': {
        data: personalStats({
          totals: {
            activities: 0,
            distance_m: 0,
            moving_time_s: 0,
            duration_s: 0,
            elevation_gain_m: 0,
            avg_speed_mps: 0,
          },
        }),
      },
      '/activities': activities([]),
    })

    await renderScreen(<HistoryScreen onBack={jest.fn()} onOpenActivity={jest.fn()} />)

    expect(await screen.findByText('Aucune sortie sur cette période.')).toBeOnTheScreen()
  })

  it('ouvre le détail de la sortie touchée', async () => {
    mockApi({
      '/stats/me': { data: personalStats() },
      '/activities': activities(),
    })

    const onOpenActivity = jest.fn()
    await renderScreen(
      <HistoryScreen onBack={jest.fn()} onOpenActivity={onOpenActivity} />,
    )

    await fireEvent.press(await screen.findByText('Dakar — Popenguine'))

    expect(onOpenActivity).toHaveBeenCalledWith('a-1')
  })

  it('revient en arrière', async () => {
    mockApi({
      '/stats/me': { data: personalStats() },
      '/activities': activities(),
    })

    const onBack = jest.fn()
    await renderScreen(<HistoryScreen onBack={onBack} onOpenActivity={jest.fn()} />)

    await fireEvent.press(screen.getByLabelText('Retour'))

    expect(onBack).toHaveBeenCalled()
  })

  it("n'affiche pas de cumul inventé quand l'API tombe", async () => {
    jest.spyOn(api, 'get').mockRejectedValue(new Error('offline'))

    await renderScreen(<HistoryScreen onBack={jest.fn()} onOpenActivity={jest.fn()} />)

    // Ni « 0 km », ni un total fabriqué : la carte reste sans chiffre.
    expect(screen.queryByText('parcourus')).toBeNull()
    expect(screen.queryByText('0 m')).toBeNull()
  })
})
