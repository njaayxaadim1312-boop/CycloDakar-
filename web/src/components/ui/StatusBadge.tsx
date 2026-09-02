import type { MemberStatusCode, RoleCode } from '@/types/api'

/**
 * Étiquettes de statut et de rôle.
 *
 * Les couleurs sont porteuses de sens et constantes dans toute l'application :
 * vert = tout va bien, orange = attention, rouge = bloqué, gris = inactif.
 * Elles ne sont jamais le SEUL indice — le libellé est toujours écrit, pour
 * les personnes qui distinguent mal les couleurs.
 */

const MEMBER_STATUS_STYLE: Record<MemberStatusCode, { bg: string; fg: string }> = {
  ACTIVE: { bg: 'var(--cd-green-soft)', fg: 'var(--cd-green-hover)' },
  PENDING: { bg: 'var(--cd-warning-soft)', fg: 'var(--cd-warning)' },
  SUSPENDED: { bg: 'var(--cd-danger-soft)', fg: 'var(--cd-danger)' },
  FORMER: { bg: 'var(--cd-surface-2)', fg: 'var(--cd-text-muted)' },
}

export function MemberStatusBadge({
  status,
  label,
}: {
  status: MemberStatusCode
  label: string
}) {
  const style = MEMBER_STATUS_STYLE[status]

  return (
    <span
      className="cd-badge"
      style={{ backgroundColor: style.bg, color: style.fg }}
    >
      {label}
    </span>
  )
}

/**
 * Rôle. Le dégradé suit la hiérarchie : plus le rôle est étendu, plus
 * l'étiquette est marquée — un trésorier doit se repérer immédiatement dans
 * une liste de 200 membres.
 */
const ROLE_STYLE: Record<RoleCode, { bg: string; fg: string }> = {
  MEMBER: { bg: 'var(--cd-surface-2)', fg: 'var(--cd-text-muted)' },
  RIDE_LEADER: { bg: 'var(--cd-green-soft)', fg: 'var(--cd-green-hover)' },
  COLLECTOR: { bg: 'var(--cd-blue-soft)', fg: 'var(--cd-blue)' },
  TREASURER: { bg: 'var(--cd-orange-soft)', fg: 'var(--cd-orange-text)' },
  ADMIN: { bg: 'var(--cd-black)', fg: '#ffffff' },
  SUPER_ADMIN: { bg: 'var(--cd-orange)', fg: 'var(--cd-black)' },
}

export function RoleBadge({ role, label }: { role: RoleCode; label: string }) {
  const style = ROLE_STYLE[role]

  return (
    <span
      className="cd-badge"
      style={{ backgroundColor: style.bg, color: style.fg }}
    >
      {label}
    </span>
  )
}

/** Membre sans compte de connexion : une situation normale, pas une anomalie. */
export function NoAccountBadge() {
  return (
    <span
      className="cd-badge"
      style={{
        backgroundColor: 'var(--cd-surface-2)',
        color: 'var(--cd-text-muted)',
      }}
      title="Ce membre n'a pas de compte de connexion (pas de smartphone)"
    >
      Sans compte
    </span>
  )
}
