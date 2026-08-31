import { api, getData, postData } from '@/lib/api'
import type {
  Member,
  MemberFilters,
  MemberSearchResult,
  Paginated,
  RoleCode,
} from '@/types/api'

/**
 * Accès à l'API des membres.
 *
 * Regroupé ici plutôt que dispersé dans les composants : les mêmes appels
 * servent à la liste, à la fiche, à la recherche du collecteur et — plus tard
 * — au module de collecte. Une seule définition, un seul endroit à corriger.
 */

/** Retire les filtres vides pour ne pas polluer l'URL ni le cache. */
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

/** Recherche rapide pour la collecte : charge utile réduite, pas de pagination. */
export function searchMembers(q: string, limit = 10): Promise<MemberSearchResult[]> {
  return getData<MemberSearchResult[]>('/members/search', { q, limit })
}

/**
 * Création et mise à jour passent par `multipart/form-data` : c'est le seul
 * format qui permet d'envoyer la photo en même temps que les champs.
 * Les valeurs nulles sont envoyées comme chaînes vides, que Laravel
 * reconvertit en `null` (middleware ConvertEmptyStringsToNull).
 */
function toFormData(values: Record<string, unknown>, photo?: File | null): FormData {
  const form = new FormData()

  for (const [key, value] of Object.entries(values)) {
    if (value === undefined) continue
    form.append(key, value === null ? '' : String(value))
  }

  if (photo) {
    form.append('photo', photo)
  }

  return form
}

export async function createMember(
  values: Record<string, unknown>,
  photo?: File | null,
): Promise<Member> {
  const response = await api.post<{ data: Member }>('/members', toFormData(values, photo))

  return response.data.data
}

export async function updateMember(
  uuid: string,
  values: Record<string, unknown>,
  photo?: File | null,
): Promise<Member> {
  // POST et non PATCH : ni les navigateurs ni React Native n'envoient de
  // fichier en multipart sur une requête PATCH.
  const response = await api.post<{ data: Member }>(
    `/members/${uuid}`,
    toFormData(values, photo),
  )

  return response.data.data
}

export function updateMemberRole(
  uuid: string,
  role: RoleCode,
  reason?: string,
): Promise<Member> {
  return postData<Member>(`/members/${uuid}/role`, { role, reason })
}

export function rotateQrCode(uuid: string): Promise<{ qr_token: string; qr_rotated_at: string | null }> {
  return postData(`/members/${uuid}/rotate-qr`)
}

export async function archiveMember(uuid: string): Promise<void> {
  await api.delete(`/members/${uuid}`)
}
