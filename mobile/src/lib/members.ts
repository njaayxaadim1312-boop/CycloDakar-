import { api, getData, postData } from './api'
import type {
  MemberStatusCode,
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

/**
 * Un membre reconnu par son QR Code.
 *
 * Volontairement MINIMAL : reconnaitre quelqu'un, pas aspirer l'annuaire un
 * QR a la fois. Ni telephone, ni adresse, ni date de naissance.
 */
export interface ScannedMember {
  uuid: string
  matricule: string
  full_name: string
  initials: string
  photo_url: string | null
  status: MemberStatusCode
  status_label: string
  is_active: boolean
}

/**
 * Retrouve un membre a partir du contenu scanne.
 *
 * Le contenu part tel quel : c'est le SERVEUR qui decide si le code vient du
 * club. Filtrer ici en plus donnerait deux verdicts a tenir a jour, et le
 * client n'est de toute facon jamais cru.
 */
export function resolveQrCode(scanned: string): Promise<ScannedMember> {
  return getData<ScannedMember>(`/members/resolve/${encodeURIComponent(scanned)}`)
}
