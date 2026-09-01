import { CameraView, useCameraPermissions } from 'expo-camera'
import { StatusBar } from 'expo-status-bar'
import { ChevronLeft, ScanLine } from 'lucide-react-native'
import { useRef, useState } from 'react'
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Avatar } from '../components/Avatar'
import { Button } from '../components/Button'
import { ApiError } from '../lib/api'
import { resolveQrCode, type ScannedMember } from '../lib/members'
import { fontSize, radius, spacing } from '../theme/tokens'
import { useTheme } from '../theme/useTheme'

interface ScanScreenProps {
  onBack: () => void
  onOpenMember: (uuid: string) => void
}

/**
 * Scanner le QR Code d'un membre.
 *
 * C'est le geste du terrain : au départ d'une sortie, un collecteur présente
 * le téléphone, le membre montre son code, et l'application dit qui c'est.
 * Tout est fait pour que cela tienne en une seconde, debout, à bout de bras.
 *
 * Trois décisions dictées par cet usage :
 *
 * 1. **Un verrou pendant la résolution.** Une caméra émet plusieurs lectures
 *    par seconde du même code : sans verrou, dix requêtes partiraient pour un
 *    seul scan, et la limite de débit se déclencherait au premier membre.
 *
 * 2. **Le résultat reste affiché** jusqu'à ce qu'on demande le suivant. Un
 *    écran qui repartirait aussitôt en caméra ne laisserait pas le temps de
 *    lire le nom — or c'est tout ce qu'on est venu chercher.
 *
 * 3. **Un membre inactif est signalé en rouge.** On ne réclame pas de
 *    cotisation à quelqu'un qui a quitté le club.
 */
export function ScanScreen({ onBack, onOpenMember }: ScanScreenProps) {
  const { colors, isDark } = useTheme()
  const [permission, requestPermission] = useCameraPermissions()

  const [result, setResult] = useState<ScannedMember | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  // Verrou hors état React : `setState` n'est pas immédiat, et deux lectures
  // séparées de 30 ms passeraient toutes les deux avant le premier rendu.
  const locked = useRef(false)

  async function onScan(data: string) {
    if (locked.current) return

    locked.current = true
    setBusy(true)
    setError(null)

    try {
      setResult(await resolveQrCode(data))
    } catch (caught) {
      setResult(null)
      setError(
        caught instanceof ApiError
          ? caught.message
          : 'Lecture impossible. Vérifiez votre connexion.',
      )
    } finally {
      setBusy(false)
    }
  }

  function scanNext() {
    setResult(null)
    setError(null)
    locked.current = false
  }

  /* --------------------------------------------------------- permission --- */

  if (permission === null) {
    return (
      <Centered>
        <ActivityIndicator color={colors.orange} />
      </Centered>
    )
  }

  if (!permission.granted) {
    return (
      <SafeAreaView style={[styles.safe, { backgroundColor: colors.bg }]} edges={['top']}>
        <Header title="Scanner un membre" onBack={onBack} />

        <View style={styles.explain}>
          <ScanLine color={colors.orangeText} size={48} />
          <Text style={[styles.explainTitle, { color: colors.text }]}>
            L'appareil photo est nécessaire
          </Text>
          <Text style={[styles.explainText, { color: colors.textMuted }]}>
            Il sert uniquement à lire le QR Code des membres. Aucune photo n'est
            prise ni enregistrée.
          </Text>
          <Button title="Autoriser l'appareil photo" onPress={() => void requestPermission()} />
        </View>
      </SafeAreaView>
    )
  }

  /* -------------------------------------------------------------- rendu --- */

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: '#000000' }]} edges={['top']}>
      <StatusBar style="light" />

      <Header title="Scanner un membre" onBack={onBack} onDark />

      <View style={styles.cameraWrap}>
        {result === null && (
          <CameraView
            style={StyleSheet.absoluteFill}
            // Seuls les QR : un code-barres de supermarché n'a rien à faire
            // ici, et le filtrer évite une requête inutile.
            barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
            onBarcodeScanned={({ data }) => void onScan(data)}
          />
        )}

        {/* Viseur : sans repère, on ne sait pas où présenter le code. */}
        {result === null && (
          <View style={styles.viewfinder} pointerEvents="none">
            <View style={[styles.frame, { borderColor: colors.orange }]} />
            <Text style={styles.hint}>Présentez le QR Code du membre</Text>
          </View>
        )}

        {busy && (
          <View style={styles.busy}>
            <ActivityIndicator color="#FFFFFF" size="large" />
          </View>
        )}
      </View>

      {/* --- Résultat ---------------------------------------------------- */}
      <View style={[styles.sheet, { backgroundColor: colors.surface }]}>
        {error !== null && (
          <>
            <Text style={[styles.errorText, { color: colors.danger }]}>{error}</Text>
            <Button title="Scanner à nouveau" onPress={scanNext} />
          </>
        )}

        {result !== null && (
          <>
            <View style={styles.member}>
              <Avatar initials={result.initials} photoUrl={result.photo_url} size={56} />

              <View style={styles.flex}>
                <Text style={[styles.memberName, { color: colors.text }]} numberOfLines={1}>
                  {result.full_name}
                </Text>
                <Text style={[styles.memberMeta, { color: colors.textMuted }]}>
                  {result.matricule}
                </Text>

                {/* Un ancien membre ou un compte suspendu doit sauter aux
                    yeux : on ne réclame pas une cotisation à quelqu'un qui a
                    quitté le club. */}
                <Text
                  style={[
                    styles.badge,
                    result.is_active
                      ? { color: colors.greenHover, backgroundColor: colors.successSoft }
                      : { color: colors.danger, backgroundColor: colors.dangerSoft },
                  ]}
                >
                  {result.status_label}
                </Text>
              </View>
            </View>

            <View style={styles.actions}>
              <Button title="Voir la fiche" onPress={() => onOpenMember(result.uuid)} />
              <Button title="Scanner le suivant" onPress={scanNext} variant="ghost" />
            </View>

            {/* PHASE 12 — c'est ici que viendra « Encaisser », le geste qui
                justifie tout ce module : scanner puis saisir un paiement sans
                chercher le membre dans une liste. */}
          </>
        )}

        {result === null && error === null && !busy && (
          <Text style={[styles.idle, { color: colors.textMuted }]}>
            Le membre trouve son QR Code dans « Mon compte ».
          </Text>
        )}
      </View>
    </SafeAreaView>
  )
}

/* -------------------------------------------------------------------------- */

function Header({
  title,
  onBack,
  onDark,
}: {
  title: string
  onBack: () => void
  onDark?: boolean
}) {
  const { colors } = useTheme()
  const tint = onDark === true ? '#FFFFFF' : colors.text

  return (
    <View style={styles.header}>
      <Pressable onPress={onBack} hitSlop={12} accessibilityLabel="Retour">
        <ChevronLeft color={tint} size={24} />
      </Pressable>
      <Text style={[styles.headerTitle, { color: tint }]}>{title}</Text>
    </View>
  )
}

function Centered({ children }: { children: React.ReactNode }) {
  const { colors } = useTheme()

  return (
    <SafeAreaView style={[styles.safe, styles.center, { backgroundColor: colors.bg }]}>
      {children}
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  center: { alignItems: 'center', justifyContent: 'center' },

  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
  },
  headerTitle: { fontSize: fontSize.h3, fontWeight: '700' },

  cameraWrap: { flex: 1, overflow: 'hidden' },
  viewfinder: {
    position: 'absolute',
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    alignItems: 'center',
    justifyContent: 'center',
  },
  frame: {
    width: 240,
    height: 240,
    borderWidth: 3,
    borderRadius: radius.lg,
  },
  hint: {
    marginTop: spacing.lg,
    color: 'rgba(255,255,255,0.85)',
    fontSize: fontSize.small,
    fontWeight: '600',
  },
  busy: {
    position: 'absolute',
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.5)',
  },

  sheet: {
    padding: spacing.lg,
    gap: spacing.md,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    minHeight: 140,
  },
  member: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  memberName: { fontSize: fontSize.h3, fontWeight: '800' },
  memberMeta: { fontSize: fontSize.small, marginTop: 1 },
  badge: {
    alignSelf: 'flex-start',
    marginTop: spacing.xs,
    fontSize: fontSize.caption,
    fontWeight: '700',
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    borderRadius: radius.pill,
    overflow: 'hidden',
  },
  actions: { gap: spacing.sm },
  errorText: { fontSize: fontSize.body, fontWeight: '600', textAlign: 'center' },
  idle: { fontSize: fontSize.small, textAlign: 'center' },

  explain: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.lg, padding: spacing.xl },
  explainTitle: { fontSize: fontSize.h3, fontWeight: '700', textAlign: 'center' },
  explainText: { fontSize: fontSize.small, textAlign: 'center', lineHeight: 20 },
})
