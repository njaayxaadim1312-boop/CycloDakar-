import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { StatusBar } from 'expo-status-bar'
import { useState } from 'react'
import {
  Alert,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Avatar } from '../components/Avatar'
import { MemberStatusBadge, RoleBadge } from '../components/Badge'
import { Button } from '../components/Button'
import { Field } from '../components/Field'
import { API_URL, ApiError, postData, tokenStore } from '../lib/api'
import { fetchMyMember, rotateQrCode } from '../lib/members'
import { useAuth, useCurrentUser } from '../stores/auth'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme, type ThemeChoice } from '../theme/useTheme'
import type { MessageResult } from '../types/api'

interface ProfileScreenProps {
  onOpenSystem: () => void
}

/**
 * Mon compte.
 *
 * Rassemble ce qui appartient à l'utilisateur : sa fiche club, son mot de
 * passe, son QR Code, son thème et ses sessions.
 */
export function ProfileScreen({ onOpenSystem }: ProfileScreenProps) {
  const { colors, isDark } = useTheme()
  const user = useCurrentUser()

  const member = useQuery({
    queryKey: ['member', 'me'],
    queryFn: fetchMyMember,
    retry: false,
  })

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
      <StatusBar style={isDark ? 'light' : 'dark'} />

      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
        >
          <Text style={[styles.title, { color: colors.text }]}>Mon compte</Text>

          {/* --- Fiche club ------------------------------------------------ */}
          <View
            style={[
              styles.card,
              { backgroundColor: colors.surface, borderColor: colors.border },
            ]}
          >
            {member.isError && (
              <Text style={[styles.muted, { color: colors.textMuted }]}>
                {member.error instanceof ApiError && member.error.status === 404
                  ? "Aucune fiche membre n'est associée à votre compte. Contactez un responsable du club."
                  : "Votre fiche club n'a pas pu être chargée."}
              </Text>
            )}

            {member.data && (
              <>
                <View style={styles.identity}>
                  <Avatar
                    photoUrl={member.data.photo_url}
                    initials={member.data.initials}
                    size={64}
                  />
                  <View style={styles.flex}>
                    <Text style={[styles.name, { color: colors.text }]}>
                      {member.data.full_name}
                    </Text>
                    <Text style={[styles.matricule, { color: colors.orangeText }]}>
                      {member.data.matricule}
                    </Text>
                  </View>
                </View>

                <View style={styles.badges}>
                  <MemberStatusBadge
                    status={member.data.status}
                    label={member.data.status_label}
                  />
                  {user && <RoleBadge role={user.role} label={user.role_label} />}
                </View>

                <Row label="Téléphone" value={member.data.phone_formatted ?? '—'} />
                <Row label="Email" value={member.data.email ?? 'Non renseigné'} />
              </>
            )}
          </View>

          {member.data?.permissions?.manage_qr && (
            <QrCard uuid={member.data.uuid} />
          )}

          <PasswordCard />
          <ThemeCard />
          <SessionCard onOpenSystem={onOpenSystem} />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function PasswordCard() {
  const { colors } = useTheme()

  const [form, setForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })

  const mutation = useMutation({
    mutationFn: () => postData<MessageResult>('/auth/change-password', form),
    onSuccess: () => {
      setForm({ current_password: '', password: '', password_confirmation: '' })
      Alert.alert('Mot de passe modifié', 'Votre nouveau mot de passe est actif.')
    },
  })

  const error = mutation.error instanceof ApiError ? mutation.error : null

  return (
    <View
      style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
    >
      <Text style={[styles.cardTitle, { color: colors.text }]}>
        Changer mon mot de passe
      </Text>
      <Text style={[styles.cardSub, { color: colors.textMuted }]}>
        Votre mot de passe actuel est demandé même connecté : un téléphone laissé
        déverrouillé ne doit pas suffire à verrouiller votre compte.
      </Text>

      {error && Object.keys(error.errors).length === 0 && (
        <View style={[styles.alert, { backgroundColor: colors.dangerSoft }]}>
          <Text style={[styles.alertText, { color: colors.danger }]}>
            {error.message}
          </Text>
        </View>
      )}

      <View style={styles.form}>
        <Field
          label="Mot de passe actuel"
          placeholder="••••••••"
          autoCapitalize="none"
          revealable
          value={form.current_password}
          onChangeText={(current_password) => setForm({ ...form, current_password })}
          error={error?.fieldError('current_password')}
        />
        <Field
          label="Nouveau mot de passe"
          placeholder="••••••••"
          autoCapitalize="none"
          revealable
          value={form.password}
          onChangeText={(password) => setForm({ ...form, password })}
          error={error?.fieldError('password')}
          hint="8 caractères minimum, avec au moins une lettre et un chiffre."
        />
        <Field
          label="Confirmer"
          placeholder="••••••••"
          autoCapitalize="none"
          revealable
          value={form.password_confirmation}
          onChangeText={(password_confirmation) =>
            setForm({ ...form, password_confirmation })
          }
        />
        <Button
          title="Changer le mot de passe"
          loading={mutation.isPending}
          onPress={() => mutation.mutate()}
        />
      </View>
    </View>
  )
}

function QrCard({ uuid }: { uuid: string }) {
  const { colors } = useTheme()
  const queryClient = useQueryClient()

  /** Version de l'image, incrementee a chaque rotation du jeton. */
  const [version, setVersion] = useState(0)

  const mutation = useMutation({
    mutationFn: () => rotateQrCode(uuid),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['member', 'me'] })
      // Sans ce changement d'URL, le telephone garderait l'ancienne image
      // en cache et le membre presenterait un code revoque sans le savoir.
      setVersion((n) => n + 1)
      Alert.alert('QR Code régénéré', "L'ancien QR Code ne fonctionne plus.")
    },
  })

  function confirm() {
    Alert.alert(
      'Régénérer mon QR Code',
      "L'ancien cessera immédiatement de fonctionner. À faire si vous pensez qu'il a été copié.",
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Régénérer', style: 'destructive', onPress: () => mutation.mutate() },
      ],
    )
  }

  return (
    <View
      style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
    >
      <Text style={[styles.cardTitle, { color: colors.text }]}>Mon QR Code</Text>
      <Text style={[styles.cardSub, { color: colors.textMuted }]}>
        Il permet au collecteur de vous identifier en un scan. Il ne contient
        aucune donnée personnelle : ni votre nom, ni votre téléphone, seulement
        un jeton que le club seul sait interpréter.
      </Text>

      {/*
        Fond blanc obligatoire, et pose sur la carte plutot que dans le fond
        de l'ecran : un QR sur fond sombre n'est pas lu par la plupart des
        appareils, et le mode sombre l'aurait rendu invisible.

        L'image vient du SERVEUR : la meme sur le web, sur le mobile et a
        l'impression. Une bibliotheque embarquee en produirait une seconde,
        qu'il faudrait garder identique a la premiere.
      */}
      <View style={styles.qrFrame}>
        <Image
          source={{
            uri: `${API_URL}/members/${uuid}/qr?v=${version}`,
            headers: { Authorization: `Bearer ${tokenStore.get() ?? ''}` },
          }}
          style={styles.qrImage}
          accessibilityLabel="Votre QR Code personnel"
        />
      </View>

      <Button
        title="Régénérer mon QR Code"
        variant="ghost"
        loading={mutation.isPending}
        onPress={confirm}
        style={styles.spaced}
      />
    </View>
  )
}

function ThemeCard() {
  const { colors, choice, setChoice } = useTheme()

  const options: { value: ThemeChoice; label: string }[] = [
    { value: 'light', label: 'Clair' },
    { value: 'dark', label: 'Sombre' },
    { value: 'system', label: 'Système' },
  ]

  return (
    <View
      style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
    >
      <Text style={[styles.cardTitle, { color: colors.text }]}>Apparence</Text>
      <Text style={[styles.cardSub, { color: colors.textMuted }]}>
        Le club roule avant le lever du jour : le mode sombre évite d'être ébloui.
      </Text>

      <View style={styles.segmented} accessibilityRole="radiogroup">
        {options.map((option) => {
          const active = choice === option.value

          return (
            <Pressable
              key={option.value}
              onPress={() => setChoice(option.value)}
              accessibilityRole="radio"
              accessibilityState={{ selected: active }}
              style={[
                styles.segment,
                {
                  backgroundColor: active ? colors.orange : colors.surface2,
                },
              ]}
            >
              <Text
                style={[
                  styles.segmentLabel,
                  { color: active ? colors.black : colors.textMuted },
                ]}
              >
                {option.label}
              </Text>
            </Pressable>
          )
        })}
      </View>
    </View>
  )
}

function SessionCard({ onOpenSystem }: { onOpenSystem: () => void }) {
  const { colors } = useTheme()
  const logout = useAuth((state) => state.logout)
  const [busy, setBusy] = useState(false)

  function confirmLogout(allDevices: boolean) {
    Alert.alert(
      allDevices ? 'Déconnecter tous les appareils' : 'Se déconnecter',
      allDevices
        ? 'Toutes vos sessions seront fermées. À faire si vous perdez votre téléphone.'
        : 'Voulez-vous vous déconnecter de cet appareil ?',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Confirmer',
          style: 'destructive',
          onPress: () => {
            setBusy(true)
            void logout(allDevices).finally(() => setBusy(false))
          },
        },
      ],
    )
  }

  return (
    <View
      style={[styles.card, { backgroundColor: colors.surface, borderColor: colors.border }]}
    >
      <Text style={[styles.cardTitle, { color: colors.text }]}>Sessions</Text>

      <View style={styles.form}>
        <Button
          title="Se déconnecter"
          variant="ghost"
          loading={busy}
          onPress={() => confirmLogout(false)}
        />
        <Button
          title="Déconnecter tous mes appareils"
          variant="danger"
          loading={busy}
          onPress={() => confirmLogout(true)}
        />
      </View>

      <Pressable onPress={onOpenSystem} hitSlop={8} style={styles.systemLink}>
        <Text style={[styles.link, { color: colors.orangeText }]}>
          État du système et diagnostic →
        </Text>
      </Pressable>
    </View>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  const { colors } = useTheme()

  return (
    <View style={[styles.row, { borderTopColor: colors.border }]}>
      <Text style={[styles.rowLabel, { color: colors.textMuted }]}>{label}</Text>
      <Text style={[styles.rowValue, { color: colors.text }]} numberOfLines={1}>
        {value}
      </Text>
    </View>
  )
}

const styles = StyleSheet.create({
  qrFrame: {
    alignSelf: 'center',
    // Blanc en dur, et non un jeton de theme : un QR doit rester sur fond
    // clair meme en mode sombre, sinon les lecteurs ne le trouvent pas.
    backgroundColor: '#FFFFFF',
    padding: spacing.md,
    borderRadius: radius.md,
    marginTop: spacing.md,
  },
  qrImage: { width: 200, height: 200 },
  safe: { flex: 1 },
  flex: { flex: 1 },
  scroll: { padding: spacing.lg, paddingBottom: spacing.xxl, gap: spacing.md },

  title: { fontSize: fontSize.h1, fontWeight: '800', letterSpacing: -0.6 },

  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: radius.md,
    padding: spacing.lg,
  },
  cardTitle: { fontSize: fontSize.h3, fontWeight: '700' },
  cardSub: { fontSize: fontSize.small, lineHeight: 19, marginTop: 2 },
  muted: { fontSize: fontSize.small, lineHeight: 19 },

  identity: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  name: { fontSize: fontSize.h3, fontWeight: '800' },
  matricule: { fontSize: fontSize.small, fontWeight: '700', marginTop: 2 },
  badges: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginTop: spacing.md,
    marginBottom: spacing.xs,
  },

  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
  },
  rowLabel: { fontSize: fontSize.small },
  rowValue: { fontSize: fontSize.body, fontWeight: '600', flexShrink: 1 },

  form: { gap: spacing.md, marginTop: spacing.lg },
  spaced: { marginTop: spacing.lg },

  alert: { borderRadius: radius.sm, padding: spacing.md, marginTop: spacing.md },
  alertText: { fontSize: fontSize.small, fontWeight: '600', lineHeight: 19 },

  segmented: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.lg },
  segment: {
    flex: 1,
    minHeight: 44,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  segmentLabel: { fontSize: fontSize.small, fontWeight: '700' },

  systemLink: { marginTop: spacing.lg, alignItems: 'center' },
  link: { fontSize: fontSize.small, fontWeight: '700' },
})
