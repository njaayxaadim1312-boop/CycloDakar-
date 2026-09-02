import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { useState } from 'react'
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button } from '../components/Button'
import { ApiError } from '../lib/api'
import { formatFcfa } from '../lib/format'
import { collectPayment, fetchMemberDues, newIdempotencyKey } from '../lib/payments'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'
import type { ParticipationLine, PaymentMethodCode } from '../types/api'

interface MemberDuesScreenProps {
  uuid: string
  onBack: () => void
}

/**
 * Ce qu'un membre doit, et l'encaissement.
 *
 * C'EST L'ÉCRAN QUI DONNE SON SENS AU SCAN.
 *
 * Scanner un QR Code ne sert à rien si l'on doit ensuite chercher le membre
 * dans une liste. Ici, on reconnaît quelqu'un et on voit immédiatement ce
 * qu'il reste à percevoir — puis on encaisse, sur place, en deux appuis.
 *
 * Trois décisions dictées par le terrain :
 *
 * - **La clé d'idempotence naît à l'ouverture du formulaire**, pas à l'envoi.
 *   Si le réseau lâche entre la requête et la réponse — le cas normal au bord
 *   d'une route, pas l'exception — le collecteur réessaie, la même clé part,
 *   et le serveur retrouve le paiement au lieu d'en créer un second.
 * - **Le montant est pré-rempli avec le reste dû**, le geste de loin le plus
 *   fréquent. Corriger est plus rapide que saisir quatre chiffres d'une main.
 * - **Seules les collectes ouvertes et non soldées apparaissent.** Un
 *   collecteur n'a que faire de l'historique : il a besoin de savoir quoi
 *   demander maintenant.
 */
export function MemberDuesScreen({ uuid, onBack }: MemberDuesScreenProps) {
  const { colors, isDark } = useTheme()

  const query = useQuery({
    queryKey: ['member-dues', uuid],
    queryFn: () => fetchMemberDues(uuid),
  })

  const lines = query.data?.lines ?? []
  const member = query.data?.member

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <Pressable onPress={onBack} hitSlop={12} style={styles.back}>
        <Text style={[styles.backText, { color: colors.orangeText }]}>← Retour</Text>
      </Pressable>

      <ScrollView contentContainerStyle={styles.content}>
        {member !== undefined && (
          <View>
            <Text style={[styles.name, { color: colors.text }]}>{member.full_name}</Text>
            <Text style={[styles.meta, { color: colors.textMuted }]}>
              {member.matricule}
            </Text>
          </View>
        )}

        {query.isLoading && <ActivityIndicator color={colors.orange} style={styles.spinner} />}

        {query.isError && (
          <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
            <Text style={[styles.alertText, { color: colors.danger }]}>
              Les cotisations n’ont pas pu être chargées.
            </Text>
          </View>
        )}

        {query.isSuccess && lines.length === 0 && (
          <View style={[styles.card, { backgroundColor: colors.surface }]}>
            <Text style={[styles.ok, { color: colors.greenHover }]}>
              Ce membre est à jour.
            </Text>
            <Text style={[styles.meta, { color: colors.textMuted }]}>
              Aucune collecte ouverte ne le concerne.
            </Text>
          </View>
        )}

        {query.data !== undefined && query.data.remainingAmount > 0 && (
          <View style={[styles.total, { backgroundColor: colors.surface }]}>
            <Text style={[styles.totalLabel, { color: colors.textMuted }]}>
              Reste à percevoir
            </Text>
            <Text style={[styles.totalValue, { color: colors.orangeText }]}>
              {formatFcfa(query.data.remainingAmount)}
            </Text>
          </View>
        )}

        {lines.map((line) => (
          <DueCard key={line.id} line={line} memberUuid={uuid} />
        ))}
      </ScrollView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function DueCard({ line, memberUuid }: { line: ParticipationLine; memberUuid: string }) {
  const { colors } = useTheme()
  const queryClient = useQueryClient()

  const [open, setOpen] = useState(false)
  const [amount, setAmount] = useState(String(line.remaining_amount))
  const [method, setMethod] = useState<PaymentMethodCode>('CASH')
  const [reference, setReference] = useState('')
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey)

  const participation = line.participation

  const collect = useMutation({
    mutationFn: () =>
      collectPayment(participation?.uuid ?? '', {
        member: memberUuid,
        amount: Number(amount),
        method,
        reference: reference.trim() === '' ? null : reference.trim(),
        idempotency_key: idempotencyKey,
      }),
    onSuccess: (result) => {
      setOpen(false)
      setReference('')
      // Le versement suivant sera un vrai nouveau versement.
      setIdempotencyKey(newIdempotencyKey())

      void queryClient.invalidateQueries({ queryKey: ['member-dues', memberUuid] })
      void queryClient.invalidateQueries({ queryKey: ['my-dues'] })

      Alert.alert(
        result.replayed ? 'Déjà enregistré' : 'Paiement enregistré',
        result.replayed
          ? // Le collecteur doit comprendre que sa reprise a retrouvé le
            // paiement, et non qu'il vient d'en créer un second.
            'Ce versement avait déjà été reçu. Rien n’a été débité une seconde fois.\n\n' +
              `Reçu ${result.payment.receipt_number}`
          : `${formatFcfa(result.payment.amount)} reçus.\n\n` +
              `Reçu ${result.payment.receipt_number} — communiquez ce numéro au membre.`,
      )
    },
    onError: (error) => {
      Alert.alert(
        'Encaissement refusé',
        error instanceof ApiError ? error.message : 'La requête n’a pas abouti.',
      )
    },
  })

  if (participation === undefined) return null

  return (
    <View style={[styles.card, { backgroundColor: colors.surface }]}>
      <Text style={[styles.cardTitle, { color: colors.text }]}>{participation.name}</Text>
      <Text style={[styles.meta, { color: colors.textMuted }]}>
        {formatFcfa(line.paid_amount)} versés sur {formatFcfa(line.expected_amount)}
      </Text>

      <Text style={[styles.remaining, { color: colors.orangeText }]}>
        {formatFcfa(line.remaining_amount)} à percevoir
      </Text>

      {!line.can_pay && (
        // Le droit vient du SERVEUR : un collecteur n'encaisse que les dettes
        // qui lui sont assignées. Le dire vaut mieux qu'un bouton qui échoue.
        <Text style={[styles.meta, { color: colors.textMuted }]}>
          Cette collecte est confiée à {line.collector?.name ?? 'un autre collecteur'}.
        </Text>
      )}

      {line.can_pay && !open && (
        <Button title="Encaisser" onPress={() => setOpen(true)} style={styles.spaced} />
      )}

      {line.can_pay && open && (
        <View style={styles.form}>
          <Text style={[styles.label, { color: colors.text }]}>Montant reçu (FCFA)</Text>
          <TextInput
            value={amount}
            onChangeText={setAmount}
            keyboardType="number-pad"
            style={[
              styles.input,
              { color: colors.text, borderColor: colors.border, backgroundColor: colors.bg },
            ]}
          />

          <Text style={[styles.label, { color: colors.text }]}>Moyen de paiement</Text>
          <View style={styles.methods}>
            {METHODS.map((option) => (
              <Pressable
                key={option.code}
                onPress={() => setMethod(option.code)}
                style={[
                  styles.method,
                  {
                    borderColor: method === option.code ? colors.orange : colors.border,
                    backgroundColor: method === option.code ? colors.orange : 'transparent',
                  },
                ]}
              >
                <Text
                  style={[
                    styles.methodText,
                    { color: method === option.code ? colors.black : colors.textMuted },
                  ]}
                >
                  {option.label}
                </Text>
              </Pressable>
            ))}
          </View>

          {method !== 'CASH' && (
            <>
              <Text style={[styles.label, { color: colors.text }]}>Référence</Text>
              <TextInput
                value={reference}
                onChangeText={setReference}
                placeholder="Identifiant Wave, Orange Money…"
                placeholderTextColor={colors.textMuted}
                style={[
                  styles.input,
                  { color: colors.text, borderColor: colors.border, backgroundColor: colors.bg },
                ]}
              />
              {/* Attendue, jamais exigée : bloquer l'encaissement ferait perdre
                  la trace du paiement, bien pire que de la consigner plus tard. */}
              <Text style={[styles.hint, { color: colors.textMuted }]}>
                Facultative — à compléter plus tard si vous ne l’avez pas.
              </Text>
            </>
          )}

          <Button
            title="Enregistrer le paiement"
            onPress={() => collect.mutate()}
            loading={collect.isPending}
            style={styles.spaced}
          />
          <Button title="Annuler" variant="ghost" onPress={() => setOpen(false)} />
        </View>
      )}
    </View>
  )
}

/**
 * Les moyens de paiement, dans l'ordre de leur fréquence réelle au Sénégal.
 *
 * Les espèces d'abord — c'est l'essentiel de la collecte de terrain — puis Wave
 * et Orange Money, qui pèsent plus lourd que le virement. L'ordre alphabétique
 * mettrait « Free Money » en tête et coûterait un appui à chaque encaissement.
 */
const METHODS: Array<{ code: PaymentMethodCode; label: string }> = [
  { code: 'CASH', label: 'Espèces' },
  { code: 'WAVE', label: 'Wave' },
  { code: 'ORANGE_MONEY', label: 'Orange Money' },
  { code: 'FREE_MONEY', label: 'Free Money' },
  { code: 'TRANSFER', label: 'Virement' },
  { code: 'OTHER', label: 'Autre' },
]

const styles = StyleSheet.create({
  safe: { flex: 1 },
  back: { paddingHorizontal: spacing.lg, paddingVertical: spacing.sm },
  backText: { fontSize: fontSize.small, fontWeight: '600' },
  content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xl },
  name: { fontSize: fontSize.h2, fontWeight: '700' },
  meta: { fontSize: fontSize.small, marginTop: 2 },
  spinner: { marginTop: spacing.xl },
  alert: { borderRadius: radius.md, padding: spacing.md },
  alertText: { fontSize: fontSize.small },
  card: { borderRadius: radius.lg, padding: spacing.lg, gap: 2 },
  cardTitle: { fontSize: fontSize.body, fontWeight: '700' },
  ok: { fontSize: fontSize.body, fontWeight: '700' },
  remaining: { fontSize: fontSize.h3, fontWeight: '700', marginTop: spacing.sm },
  total: {
    borderRadius: radius.lg,
    padding: spacing.lg,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  totalLabel: { fontSize: fontSize.small },
  totalValue: { fontSize: fontSize.h2, fontWeight: '700' },
  form: { marginTop: spacing.md, gap: spacing.xs },
  label: { fontSize: fontSize.small, fontWeight: '600', marginTop: spacing.sm },
  input: {
    borderWidth: 1,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.body,
  },
  hint: { fontSize: fontSize.caption },
  methods: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs },
  method: {
    borderWidth: 1,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
  },
  methodText: { fontSize: fontSize.caption, fontWeight: '600' },
  spaced: { marginTop: spacing.sm },
})
