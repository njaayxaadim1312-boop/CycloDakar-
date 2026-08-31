import { fireEvent, screen, waitFor } from '@testing-library/react-native'
import { MembersScreen } from '../src/screens/MembersScreen'
import { aMember, aSearchResult, mockApi, renderScreen } from './helpers'

/**
 * Annuaire mobile.
 *
 * L'écran a deux modes, et c'est le point à vérifier : la liste complète tant
 * qu'on ne cherche pas, et la route de recherche allégée dès qu'on tape. Se
 * tromper de route ferait passer une charge utile complète sur un réseau
 * mobile, à chaque frappe.
 */
describe('MembersScreen', () => {
  afterEach(() => jest.restoreAllMocks())

  it("affiche l'annuaire complet quand aucune recherche n'est saisie", async () => {
    mockApi({
      '/members': {
        data: [
          aMember(),
          aMember({
            uuid: 'm-2',
            matricule: 'CD-000043',
            full_name: 'Aminata Cisse',
            initials: 'AC',
            has_account: false,
            account: null,
          }),
        ],
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 100,
          total: 2,
          has_more: false,
        },
      },
    })

    await renderScreen(<MembersScreen onOpenMember={jest.fn()} />)

    expect(await screen.findByText('Khadim Ndiaye')).toBeOnTheScreen()
    expect(screen.getByText('Aminata Cisse')).toBeOnTheScreen()
  })

  it('bascule sur la recherche allégée dès que l’on tape', async () => {
    const get = mockApi({
      '/members/search': { data: [aSearchResult()], meta: { count: 1 } },
      '/members': { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, has_more: false } },
    })

    await renderScreen(<MembersScreen onOpenMember={jest.fn()} />)

    await fireEvent.changeText(
      screen.getByLabelText('Rechercher un membre'),
      'Kha',
    )

    await waitFor(() => {
      expect(get).toHaveBeenCalledWith(
        '/members/search',
        expect.objectContaining({ params: { q: 'Kha', limit: 15 } }),
      )
    })

    expect(await screen.findByText('Khadim Ndiaye')).toBeOnTheScreen()
  })

  it('ne lance pas une requête à chaque frappe', async () => {
    // La saisie est différée : sans cela, « Khadim » déclencherait six
    // requêtes, ce qui est intenable sur un réseau mobile.
    const get = mockApi({
      '/members/search': { data: [], meta: { count: 0 } },
      '/members': { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, has_more: false } },
    })

    await renderScreen(<MembersScreen onOpenMember={jest.fn()} />)
    const input = screen.getByLabelText('Rechercher un membre')

    for (const value of ['K', 'Kh', 'Kha', 'Khad', 'Khadi', 'Khadim']) {
      await fireEvent.changeText(input, value)
    }

    await waitFor(() => {
      const searchCalls = get.mock.calls.filter(
        ([url]) => url === '/members/search',
      )
      expect(searchCalls).toHaveLength(1)
      expect(searchCalls[0]![1]).toMatchObject({ params: { q: 'Khadim' } })
    })
  })

  it('annonce clairement une recherche sans résultat', async () => {
    mockApi({
      '/members/search': { data: [], meta: { count: 0 } },
      '/members': { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, has_more: false } },
    })

    await renderScreen(<MembersScreen onOpenMember={jest.fn()} />)
    await fireEvent.changeText(screen.getByLabelText('Rechercher un membre'), 'Zzz')

    // `findBy` et non `getBy` : la saisie est differee de 350 ms, le message
    // specifique a la recherche n'apparait donc pas immediatement.
    expect(
      await screen.findByText(/Rien ne correspond à « Zzz »/),
    ).toBeOnTheScreen()
  })

  it('ouvre la fiche du membre touché', async () => {
    mockApi({
      '/members': {
        data: [aMember()],
        meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, has_more: false },
      },
    })

    const onOpenMember = jest.fn()
    await renderScreen(<MembersScreen onOpenMember={onOpenMember} />)

    await fireEvent.press(await screen.findByText('Khadim Ndiaye'))

    expect(onOpenMember).toHaveBeenCalledWith('m-1')
  })

  it("explique l'échec plutôt que d'afficher une liste vide", async () => {
    // Hors ligne, une liste vide laisserait croire que le club n'a aucun
    // membre. C'est exactement le genre de silence qui fait douter de l'outil.
    jest.spyOn(require('../src/lib/api').api, 'get').mockRejectedValue(
      new Error('offline'),
    )

    await renderScreen(<MembersScreen onOpenMember={jest.fn()} />)

    expect(
      await screen.findByText(/L'annuaire n'a pas pu être chargé/),
    ).toBeOnTheScreen()
  })
})
