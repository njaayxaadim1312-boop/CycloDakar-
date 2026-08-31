import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { useEffect, useState } from 'react'
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Avatar } from '../components/Avatar'
import { MemberStatusBadge, NoAccountBadge, RoleBadge } from '../components/Badge'
import { fetchMembers, searchMembers } from '../lib/members'
import { fontSize, radius, spacing, touch } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { Member, MemberSearchResult } from '../types/api'

interface MembersScreenProps {
  onOpenMember: (uuid: string) => void
}

/**
 * Annuaire du club, consultable sur le terrain.
 *
 * Deux modes, choisis automatiquement :
 *
 *  - **sans recherche**, on liste l'annuaire complet, paginé au défilement ;
 *  - **dès qu'on tape**, on bascule sur `/members/search` : charge utile
 *    réduite, pas de pagination, anciens membres écartés. C'est le mode pensé
 *    pour le collecteur qui cherche quelqu'un devant lui, sur un réseau
 *    parfois médiocre.
 *
 * Le champ est en haut, large et toujours visible : sur un téléphone tenu à
 * une main, c'est le geste le plus fréquent.
 */
export function MembersScreen({ onOpenMember }: MembersScreenProps) {
  const { colors, isDark } = useTheme()

  const [input, setInput] = useState('')
  const [term, setTerm] = useState('')

  // Envoi différé : sans cela, chaque frappe déclencherait une requête, ce qui
  // est intenable sur un réseau mobile.
  useEffect(() => {
    const timer = setTimeout(() => setTerm(input.trim()), 350)
    return () => clearTimeout(timer)
  }, [input])

  const searching = term.length > 0

  const search = useQuery({
    queryKey: ['members', 'search', term],
    queryFn: () => searchMembers(term),
    enabled: searching,
    placeholderData: keepPreviousData,
  })

  const directory = useQuery({
    queryKey: ['members', 'directory'],
    queryFn: () => fetchMembers({ per_page: 100, sort: 'name' }),
    enabled: !searching,
  })

  const rows: (Member | MemberSearchResult)[] = searching
    ? (search.data ?? [])
    : (directory.data?.data ?? [])

  const loading = searching ? search.isLoading : directory.isLoading
  const failed = searching ? search.isError : directory.isError

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <View style={styles.header}>
        <Text style={[styles.title, { color: colors.text }]}>Membres</Text>

        <TextInput
          value={input}
          onChangeText={setInput}
          placeholder="Nom, matricule ou téléphone…"
          placeholderTextColor={colors.textMuted}
          autoCapitalize="none"
          autoCorrect={false}
          clearButtonMode="while-editing"
          accessibilityLabel="Rechercher un membre"
          style={[
            styles.search,
            {
              backgroundColor: colors.surface,
              borderColor: colors.borderStrong,
              color: colors.text,
            },
          ]}
        />

        <Text style={[styles.hint, { color: colors.textMuted }]}>
          {searching
            ? `${rows.length} résultat${rows.length > 1 ? 's' : ''}`
            : `${rows.length} membre${rows.length > 1 ? 's' : ''} · le numéro peut être saisi dans n'importe quel format`}
        </Text>
      </View>

      {loading && <ActivityIndicator style={styles.spinner} color={colors.orange} />}

      {failed && (
        <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
          <Text style={[styles.alertText, { color: colors.danger }]}>
            L'annuaire n'a pas pu être chargé. Vérifiez votre connexion.
          </Text>
        </View>
      )}

      <FlatList
        data={rows}
        keyExtractor={(item) => item.uuid}
        contentContainerStyle={styles.list}
        keyboardShouldPersistTaps="handled"
        // Sans ces réglages, une liste de 200 membres saccade sur un téléphone
        // d'entrée de gamme.
        initialNumToRender={12}
        maxToRenderPerBatch={12}
        windowSize={7}
        removeClippedSubviews
        ListEmptyComponent={
          loading || failed ? null : (
            <View style={styles.empty}>
              <Text style={[styles.emptyTitle, { color: colors.text }]}>
                Aucun membre trouvé
              </Text>
              <Text style={[styles.emptyBody, { color: colors.textMuted }]}>
                {searching
                  ? `Rien ne correspond à « ${term} ».`
                  : "L'annuaire est vide."}
              </Text>
            </View>
          )
        }
        renderItem={({ item }) => (
          <Pressable
            onPress={() => onOpenMember(item.uuid)}
            accessibilityRole="button"
            style={({ pressed }) => [
              styles.row,
              {
                backgroundColor: pressed ? colors.orangeSoft : colors.surface,
                borderColor: colors.border,
              },
            ]}
          >
            <Avatar photoUrl={item.photo_url} initials={item.initials} size={44} />

            <View style={styles.rowText}>
              <Text style={[styles.rowName, { color: colors.text }]} numberOfLines={1}>
                {item.full_name}
              </Text>
              <Text style={[styles.rowMeta, { color: colors.textMuted }]} numberOfLines={1}>
                {item.matricule}
                {item.phone_formatted ? ` · ${item.phone_formatted}` : ''}
              </Text>
            </View>

            <View style={styles.rowBadges}>
              {'account' in item && item.account ? (
                <RoleBadge role={item.account.role} label={item.account.role_label} />
              ) : 'has_account' in item && !item.has_account ? (
                <NoAccountBadge />
              ) : (
                <MemberStatusBadge
                  status={item.status}
                  label={'status_label' in item ? item.status_label : ''}
                />
              )}
            </View>
          </Pressable>
        )}
      />
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },

  header: { padding: spacing.lg, paddingBottom: spacing.sm, gap: spacing.sm },
  title: { fontSize: fontSize.h1, fontWeight: '800', letterSpacing: -0.6 },
  search: {
    minHeight: touch.min,
    borderWidth: 1,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.md,
    fontSize: fontSize.body,
  },
  hint: { fontSize: fontSize.caption },

  spinner: { marginTop: spacing.xl },

  alert: {
    marginHorizontal: spacing.lg,
    borderRadius: radius.sm,
    padding: spacing.md,
  },
  alertText: { fontSize: fontSize.small, fontWeight: '600', lineHeight: 19 },

  list: { padding: spacing.lg, paddingTop: spacing.sm, gap: spacing.sm },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    minHeight: touch.min + 16,
  },
  rowText: { flex: 1, gap: 2 },
  rowName: { fontSize: fontSize.body, fontWeight: '700' },
  rowMeta: { fontSize: fontSize.caption },
  rowBadges: { alignItems: 'flex-end' },

  empty: { alignItems: 'center', paddingVertical: spacing.xxl, gap: 4 },
  emptyTitle: { fontSize: fontSize.body, fontWeight: '700' },
  emptyBody: { fontSize: fontSize.small, textAlign: 'center' },
})
