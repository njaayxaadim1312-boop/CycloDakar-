import { api, getData, postData } from './api'
import type {
  Member,
  MemberFilters,
  MemberSearchResult,
  Paginated,
} from '../types/api'

/**
 * Accès à l'API des membres (mobile).
 *
 * Miroir de `web/src/lib/members.ts`, réduit à ce dont le terrain a besoin :
 * consulter l'annuaire, retrouver quelqu'un, voir sa propre fiche. La création
 * et la modification de fiche restent sur le web, où la saisie est plus
 * confortable — sauf la recherche, qui est justement faite pour le téléphone.
 */

function cleanParams(filters: MemberFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value !== '' && value !== undefined && value !== null) {
      params[key] = value as string | number
    }
  }

  return params
}

export async function fetchMembers(filters: MemberFilters): Promise<Paginated<Member>> {
  const response = await api.get<Paginated<Member>>('/members', {
    params: cleanParams(filters),
  })

  return response.data
}

export function fetchMember(uuid: string): Promise<Member> {
  return getData<Member>(`/members/${uuid}`)
}

/** Fiche club de l'utilisateur connecté. */
export function fetchMyMember(): Promise<Member> {
  return getData<Member>('/members/me')
}

/**
 * Recherche rapide : c'est la route pensée pour le terrain. Charge utile
 * réduite, pas de pagination, anciens membres écartés.
 */
export function searchMembers(q: string, limit = 15): Promise<MemberSearchResult[]> {
  return getData<MemberSearchResult[]>('/members/search', { q, limit })
}

export function rotateQrCode(
  uuid: string,
): Promise<{ qr_token: string; qr_rotated_at: string | null }> {
  return postData(`/members/${uuid}/rotate-qr`)
}
