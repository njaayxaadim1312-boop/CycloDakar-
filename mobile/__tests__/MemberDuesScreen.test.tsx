import { fireEvent, screen, waitFor } from '@testing-library/react-native'
import { Alert } from 'react-native'
import { MemberDuesScreen } from '../src/screens/MemberDuesScreen'
import { api } from '../src/lib/api'
import { mockApi, renderScreen } from './helpers'

/**
 * Encaissement mobile.
 *
 * Ce qui est vérifié ici n'est pas de l'affichage : c'est l'argent du club.
 *
 * 1. **La clé d'idempotence part avec la requête, et ne change pas entre deux
 *    tentatives du même versement.** C'est toute la protection contre le
 *    double débit quand le réseau lâche entre l'envoi et la réponse — le cas
 *    normal au bord d'une route, pas l'exception.
 * 2. **Le montant est pré-rempli avec le reste dû**, pas avec le montant
 *    attendu : un membre qui a déjà versé la moitié ne doit pas se voir
 *    réclamer la totalité.
 * 3. **Une dette confiée à un autre collecteur ne montre pas de bouton.** Le
 *    droit vient du serveur ; l'afficher quand même donnerait un bouton qui
 *    répond 403, avec un membre qui attend.
 */

const ligne = (overrides: Record<string, unknown> = {}) => ({
  id: 7,
  expected_amount: 5000,
  paid_amount: 2000,
  remaining_amount: 3000,
  status: 'PARTIELLEMENT_PAYE',
  status_label: 'Partiellement payé',
  collector: { uuid: 'u-1', name: 'Awa Sow' },
  participation: {
    uuid: 'p-1',
    name: 'Sortie Lac Rose',
    status: 'OPEN',
    due_on: '2026-09-30',
  },
  last_payment_at: null,
  note: null,
  can_pay: true,
  ...overrides,
})

const dues = (lignes: Array<Record<string, unknown>> = [ligne()]) => ({
  '/members/m-1/dues': {
    data: lignes,
    meta: {
      member: { uuid: 'm-1', matricule: 'CD-000042', full_name: 'Khadim Ndiaye' },
      remaining_amount: lignes.reduce(
        (total, l) => total + Number(l.remaining_amount ?? 0),
        0,
      ),
    },
  },
})

describe('MemberDuesScreen', () => {
  afterEach(() => jest.restoreAllMocks())

  it('montre ce que le membre doit, reste à percevoir en tête', async () => {
    mockApi(dues())

    await renderScreen(<MemberDuesScreen uuid="m-1" onBack={jest.fn()} />)

    expect(await screen.findByText('Khadim Ndiaye')).toBeOnTheScreen()
    expect(screen.getByText('Sortie Lac Rose')).toBeOnTheScreen()
    // Le reste dû, pas le montant attendu.
    expect(screen.getByText('3 000 FCFA à percevoir')).toBeOnTheScreen()
  })

  it('envoie une clé d’idempotence et le reste dû comme montant', async () => {
    mockApi(dues())

    const post = jest.spyOn(api, 'post').mockResolvedValue({
      data: {
        data: {
          uuid: 'pay-1',
          receipt_number: 'RC-2026-000001',
          amount: 3000,
          method: 'CASH',
          method_label: 'Espèces',
          reference: null,
          note: null,
          paid_on: '2026-09-02',
          created_at: '2026-09-02T10:00:00+00:00',
          cancelled: false,
          cancelled_at: null,
          cancellation_reason: null,
        },
        meta: { replayed: false, line: ligne({ remaining_amount: 0 }) },
      },
    } as never)

    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)

    await renderScreen(<MemberDuesScreen uuid="m-1" onBack={jest.fn()} />)

    fireEvent.press(await screen.findByText('Encaisser'))

    // `findBy…` et non `getBy…` : React 19 rend en mode concurrent, le
    // formulaire n'est pas monté à l'instant du clic.
    fireEvent.press(await screen.findByText('Enregistrer le paiement'))

    await waitFor(() => expect(post).toHaveBeenCalled())

    const [url, corps] = post.mock.calls[0] as [string, Record<string, unknown>]

    expect(url).toBe('/participations/p-1/payments')
    // Le montant pré-rempli est le RESTE dû, jamais le montant attendu.
    expect(corps.amount).toBe(3000)
    expect(corps.member).toBe('m-1')
    // Sans cette clé, une reprise réseau débiterait le membre deux fois.
    expect(typeof corps.idempotency_key).toBe('string')
    expect((corps.idempotency_key as string).length).toBeGreaterThan(8)

    // Le serveur détermine seul qui a encaissé : le client ne l'envoie pas.
    expect(corps).not.toHaveProperty('collected_by')
    expect(corps).not.toHaveProperty('paid_amount')
    expect(corps).not.toHaveProperty('status')
  })

  it("n'offre pas d'encaisser une dette confiée à quelqu'un d'autre", async () => {
    mockApi(dues([ligne({ can_pay: false })]))

    await renderScreen(<MemberDuesScreen uuid="m-1" onBack={jest.fn()} />)

    expect(await screen.findByText('Sortie Lac Rose')).toBeOnTheScreen()
    expect(screen.queryByText('Encaisser')).toBeNull()
    // On dit POURQUOI : un bouton absent sans explication passe pour une panne.
    expect(screen.getByText(/confiée à Awa Sow/)).toBeOnTheScreen()
  })

  it('dit clairement quand le membre est à jour', async () => {
    mockApi(dues([]))

    await renderScreen(<MemberDuesScreen uuid="m-1" onBack={jest.fn()} />)

    expect(await screen.findByText('Ce membre est à jour.')).toBeOnTheScreen()
  })
})
