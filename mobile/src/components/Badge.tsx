import { StyleSheet, Text, View } from 'react-native'
import { fontSize, radius } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { MemberStatusCode, RoleCode } from '../types/api'

/**
 * Étiquettes de statut et de rôle.
 *
 * Les couleurs sont porteuses de sens et constantes dans toute l'application,
 * mais jamais le SEUL indice : le libellé est toujours écrit, pour les
 * personnes qui distinguent mal les couleurs — et parce qu'un écran de
 * téléphone en plein soleil aplatit les nuances.
 */

export function MemberStatusBadge({
  status,
  label,
}: {
  status: MemberStatusCode
  label: string
}) {
  const { colors } = useTheme()

  const palette: Record<MemberStatusCode, { bg: string; fg: string }> = {
    ACTIVE: { bg: colors.greenSoft, fg: colors.greenHover },
    PENDING: { bg: colors.warningSoft, fg: colors.warning },
    SUSPENDED: { bg: colors.dangerSoft, fg: colors.danger },
    FORMER: { bg: colors.surface2, fg: colors.textMuted },
  }

  return <Badge {...palette[status]} label={label} />
}

export function RoleBadge({ role, label }: { role: RoleCode; label: string }) {
  const { colors } = useTheme()

  // Le dégradé suit la hiérarchie : un trésorier doit se repérer immédiatement
  // dans une liste de 200 membres.
  const palette: Record<RoleCode, { bg: string; fg: string }> = {
    MEMBER: { bg: colors.surface2, fg: colors.textMuted },
    COLLECTOR: { bg: colors.blueSoft, fg: colors.blue },
    TREASURER: { bg: colors.orangeSoft, fg: colors.orangeText },
    ADMIN: { bg: colors.black, fg: '#FFFFFF' },
    SUPER_ADMIN: { bg: colors.orange, fg: colors.black },
  }

  return <Badge {...palette[role]} label={label} />
}

/** Membre sans compte de connexion : une situation normale, pas une anomalie. */
export function NoAccountBadge() {
  const { colors } = useTheme()

  return <Badge bg={colors.surface2} fg={colors.textMuted} label="Sans compte" />
}

function Badge({ bg, fg, label }: { bg: string; fg: string; label: string }) {
  return (
    <View style={[styles.badge, { backgroundColor: bg }]}>
      <Text style={[styles.label, { color: fg }]} numberOfLines={1}>
        {label}
      </Text>
    </View>
  )
}

const styles = StyleSheet.create({
  badge: {
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: radius.pill,
    alignSelf: 'flex-start',
  },
  label: { fontSize: fontSize.caption, fontWeight: '700' },
})
