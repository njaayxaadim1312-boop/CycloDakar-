import { api, getData } from './api'
import type {
  CashState,
  MyDues,
  MyDuesTotals,
  ParticipationLine,
  Payment,
  PaymentInput,
} from '../types/api'

/**
 * Accès à l'API des encaissements.
 *
 * Miroir de `web/src/lib/payments.ts` (ADR-006 : pas de paquet partagé, deux
 * fichiers tenus à la main).
 *
 * **Tous les montants sont des entiers de FCFA**, à l'entrée comme à la
 * sortie. Rien à convertir : le franc CFA n'a pas de subdivision en usage.
 *
 * Ce client n'envoie ni `collected_by`, ni `paid_amount`, ni `status`, ni le
 * moindre solde : le serveur les détermine seul (docs/finance.md, règle I3).
 * Et il ne SUPPRIME rien — une erreur s'annule, ce qui écrit une
 * contre-passation au grand livre et conserve le reçu.
 */

/**
 * Fabrique une clé d'idempotence.
 *
 * À appeler UNE FOIS par saisie, et à réutiliser telle quelle sur chaque
 * tentative d'envoi. C'est toute la protection contre le double débit quand le
 * réseau lâche entre la requête et la réponse — le cas normal sur le terrain,
 * pas l'exception. En fabriquer une nouvelle à chaque tentative reviendrait à
 * ne pas en avoir.
 *
 * `crypto.randomUUID` n'existe pas partout sur React Native selon le moteur :
 * on retombe sur un identifiant construit à la main, qui n'a pas besoin d'être
 * cryptographique — seulement unique à l'échelle d'un téléphone.
 */
export function newIdempotencyKey(): string {
  // Appelée SUR l'objet `crypto`, jamais détachée : `randomUUID` exige son
  // receveur, et `const f = crypto.randomUUID; f()` lève « Value of "this"
  // must be of type Crypto ». Le piège est silencieux à la relecture et
  // n'apparaît qu'à l'exécution.
  const crypto = (globalThis as { crypto?: { randomUUID?: () => string } }).crypto

  if (typeof crypto?.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  // Repli : selon le moteur JavaScript, React Native n'expose pas toujours
  // `crypto`. Cette clé n'a pas besoin d'être cryptographique — seulement
  // unique à l'échelle d'un téléphone, le temps d'une saisie.
  return `cd-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

/** Ce qu'un membre doit, vu par un collecteur. Complète le scan du QR Code. */
export async function fetchMemberDues(memberUuid: string): Promise<{
  lines: ParticipationLine[]
  member: { uuid: string; matricule: string; full_name: string }
  remainingAmount: number
}> {
  const response = await api.get<{
    data: ParticipationLine[]
    meta: {
      member: { uuid: string; matricule: string; full_name: string }
      remaining_amount: number
    }
  }>(`/members/${memberUuid}/dues`)

  return {
    lines: response.data.data,
    member: response.data.meta.member,
    remainingAmount: response.data.meta.remaining_amount,
  }
}

/**
 * Enregistre un encaissement.
 *
 * `replayed` distingue « c'est enregistré » de « c'était déjà enregistré » :
 * l'écran peut alors dire au collecteur que sa reprise a retrouvé le paiement,
 * au lieu de lui laisser croire qu'il vient d'en créer un second.
 */
export async function collectPayment(
  participationUuid: string,
  input: PaymentInput,
): Promise<{ payment: Payment; replayed: boolean; line: ParticipationLine }> {
  const response = await api.post<{
    data: Payment
    meta: { replayed: boolean; line: ParticipationLine }
  }>(`/participations/${participationUuid}/payments`, input)

  return {
    payment: response.data.data,
    replayed: response.data.meta.replayed,
    line: response.data.meta.line,
  }
}

/** Ce que JE dois et ce que J'AI payé. La seule route financière d'un membre. */
export async function fetchMyDues(): Promise<{ dues: MyDues; totals: MyDuesTotals }> {
  const response = await api.get<{ data: MyDues; meta: MyDuesTotals }>('/payments/mine')

  return { dues: response.data.data, totals: response.data.meta }
}

export function fetchCashState(): Promise<CashState> {
  return getData<CashState>('/finance/cash')
}
