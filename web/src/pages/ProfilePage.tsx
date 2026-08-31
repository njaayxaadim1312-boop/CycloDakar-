import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertCircle,
  CheckCircle2,
  KeyRound,
  Monitor,
  Moon,
  Pencil,
  QrCode,
  RefreshCw,
  Sun,
} from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { Avatar } from '@/components/ui/Avatar'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import {
  MemberStatusBadge,
  NoAccountBadge,
  RoleBadge,
} from '@/components/ui/StatusBadge'
import { useTheme, type ThemeChoice } from '@/hooks/useTheme'
import { ApiError, postData } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { fetchMyMember, rotateQrCode } from '@/lib/members'
import { useAuth, useCurrentUser } from '@/stores/auth'
import type { MessageResult } from '@/types/api'

/**
 * Mon compte.
 *
 * Rassemble ce qui appartient à l'utilisateur lui-même : sa fiche club, son
 * mot de passe, son QR Code, ses préférences d'affichage et ses sessions.
 * Ces actions existaient dans l'API depuis la phase 2 mais n'avaient aucun
 * point d'entrée dans l'interface — un membre ne pouvait pas changer son mot
 * de passe sans passer par « mot de passe oublié ».
 */
export function ProfilePage() {
  const user = useCurrentUser()

  const member = useQuery({
    queryKey: ['member', 'me'],
    queryFn: fetchMyMember,
    // Un compte peut ne pas avoir de fiche (cas limite) : on ne réessaie pas
    // indéfiniment, on affiche l'explication.
    retry: false,
  })

  return (
    <div className="space-y-5">
      <PageHeader
        title="Mon compte"
        description="Votre fiche club, votre mot de passe et vos préférences."
      />

      {/* --- Identité ----------------------------------------------------- */}
      <section className="cd-card p-5 sm:p-6">
        {member.isLoading && (
          <div className="h-20 animate-pulse rounded bg-[var(--cd-surface-2)]" />
        )}

        {member.isError && (
          <p className="text-sm text-[var(--cd-text-muted)]">
            {member.error instanceof ApiError && member.error.status === 404
              ? "Aucune fiche membre n'est associée à votre compte. Contactez un responsable du club."
              : 'Votre fiche club n’a pas pu être chargée.'}
          </p>
        )}

        {member.data && (
          <>
            <div className="flex flex-wrap items-start gap-4">
              <Avatar
                photoUrl={member.data.photo_url}
                initials={member.data.initials}
                size={72}
              />

              <div className="min-w-0 flex-1">
                <h3 className="text-xl">{member.data.full_name}</h3>
                <p className="tabular mt-0.5 text-sm font-semibold text-brand-text">
                  {member.data.matricule}
                </p>
                <div className="mt-2.5 flex flex-wrap items-center gap-2">
                  <MemberStatusBadge
                    status={member.data.status}
                    label={member.data.status_label}
                  />
                  {user ? (
                    <RoleBadge role={user.role} label={user.role_label} />
                  ) : (
                    <NoAccountBadge />
                  )}
                </div>
              </div>

              {member.data.permissions?.update && (
                <Link
                  to={`/members/${member.data.uuid}/modifier`}
                  className="cd-btn cd-btn-ghost"
                >
                  <Pencil size={16} />
                  Modifier
                </Link>
              )}
            </div>

            <dl className="mt-6 grid gap-4 sm:grid-cols-3">
              <Info label="Téléphone" value={member.data.phone_formatted ?? '—'} />
              <Info label="Email" value={member.data.email ?? 'Non renseigné'} />
              <Info
                label="Membre depuis"
                value={
                  member.data.joined_at ? formatDate(member.data.joined_at) : '—'
                }
              />
            </dl>
          </>
        )}
      </section>

      {/* --- QR Code ------------------------------------------------------- */}
      {member.data?.permissions?.manage_qr && <QrSection uuid={member.data.uuid} />}

      {/* --- Mot de passe -------------------------------------------------- */}
      <PasswordSection />

      {/* --- Préférences --------------------------------------------------- */}
      <ThemeSection />
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function PasswordSection() {
  const [form, setForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })
  const [logoutOthers, setLogoutOthers] = useState(false)

  const mutation = useMutation({
    mutationFn: () =>
      postData<MessageResult>('/auth/change-password', {
        ...form,
        logout_other_devices: logoutOthers,
      }),
    onSuccess: () => {
      setForm({ current_password: '', password: '', password_confirmation: '' })
      setLogoutOthers(false)
    },
  })

  const error = mutation.error instanceof ApiError ? mutation.error : null

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    mutation.mutate()
  }

  return (
    <section className="cd-card p-5">
      <h3 className="flex items-center gap-2 text-lg">
        <KeyRound size={19} className="text-[var(--cd-orange)]" />
        Changer mon mot de passe
      </h3>
      <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
        Votre mot de passe actuel est demandé même si vous êtes connecté : un
        téléphone laissé déverrouillé ne doit pas suffire à verrouiller votre
        compte.
      </p>

      <form onSubmit={handleSubmit} className="mt-4 max-w-sm space-y-4" noValidate>
        {error && Object.keys(error.errors).length === 0 && (
          <div
            role="alert"
            className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]"
          >
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <span>{error.message}</span>
          </div>
        )}

        {mutation.isSuccess && (
          <div className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-green-soft)] p-3 text-sm">
            <CheckCircle2
              size={17}
              className="mt-0.5 shrink-0 text-[var(--cd-green-hover)]"
            />
            <span>Mot de passe modifié.</span>
          </div>
        )}

        <Field
          label="Mot de passe actuel"
          type="password"
          autoComplete="current-password"
          revealable
          value={form.current_password}
          onChange={(e) => setForm({ ...form, current_password: e.target.value })}
          error={error?.fieldError('current_password')}
          required
        />

        <Field
          label="Nouveau mot de passe"
          type="password"
          autoComplete="new-password"
          revealable
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={error?.fieldError('password')}
          hint="8 caractères minimum, avec au moins une lettre et un chiffre."
          required
        />

        <Field
          label="Confirmer le nouveau mot de passe"
          type="password"
          autoComplete="new-password"
          revealable
          value={form.password_confirmation}
          onChange={(e) =>
            setForm({ ...form, password_confirmation: e.target.value })
          }
          required
        />

        <label className="flex items-start gap-2.5 text-sm">
          <input
            type="checkbox"
            checked={logoutOthers}
            onChange={(e) => setLogoutOthers(e.target.checked)}
            className="mt-0.5 size-4 accent-[var(--cd-orange)]"
          />
          <span>
            Déconnecter mes autres appareils
            <span className="block text-xs text-[var(--cd-text-muted)]">
              À cocher si vous pensez que quelqu'un a eu accès à votre compte.
            </span>
          </span>
        </label>

        <button
          type="submit"
          disabled={mutation.isPending}
          className="cd-btn cd-btn-primary"
        >
          {mutation.isPending ? 'Enregistrement…' : 'Changer le mot de passe'}
        </button>
      </form>
    </section>
  )
}

function QrSection({ uuid }: { uuid: string }) {
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)

  const mutation = useMutation({
    mutationFn: () => rotateQrCode(uuid),
    onSuccess: () => {
      setConfirming(false)
      void queryClient.invalidateQueries({ queryKey: ['member', 'me'] })
    },
  })

  return (
    <section className="cd-card p-5">
      <h3 className="flex items-center gap-2 text-lg">
        <QrCode size={19} className="text-[var(--cd-blue)]" />
        Mon QR Code
      </h3>
      <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
        Il permet au collecteur de vous identifier en un scan. Il ne contient
        aucune donnée personnelle : photographié par un tiers, il ne révèle rien.
        Son affichage et son impression arrivent en phase 11.
      </p>

      <div className="mt-4">
        {confirming ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm">
              Générer un nouveau QR ? L'ancien cessera de fonctionner.
            </span>
            <button
              type="button"
              onClick={() => mutation.mutate()}
              disabled={mutation.isPending}
              className="cd-btn cd-btn-primary !min-h-9"
            >
              {mutation.isPending ? 'En cours…' : 'Confirmer'}
            </button>
            <button
              type="button"
              onClick={() => setConfirming(false)}
              className="cd-btn cd-btn-ghost !min-h-9"
            >
              Annuler
            </button>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => setConfirming(true)}
            className="cd-btn cd-btn-ghost"
          >
            <RefreshCw size={16} />
            Régénérer mon QR Code
          </button>
        )}

        {mutation.isSuccess && !confirming && (
          <p className="mt-2 text-sm font-medium text-[var(--cd-green-hover)]">
            Nouveau QR Code généré. L'ancien ne fonctionne plus.
          </p>
        )}
      </div>
    </section>
  )
}

function ThemeSection() {
  const { choice, setChoice } = useTheme()
  const logout = useAuth((state) => state.logout)

  const options: { value: ThemeChoice; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Clair', icon: Sun },
    { value: 'dark', label: 'Sombre', icon: Moon },
    { value: 'system', label: 'Système', icon: Monitor },
  ]

  return (
    <section className="cd-card p-5">
      <h3 className="text-lg">Préférences</h3>

      <p className="mt-4 text-sm font-semibold">Apparence</p>
      <p className="mt-0.5 text-sm text-[var(--cd-text-muted)]">
        Le club roule avant le lever du jour : le mode sombre évite d'être ébloui.
      </p>

      <div
        role="radiogroup"
        aria-label="Thème de l'interface"
        className="mt-3 flex flex-wrap gap-2"
      >
        {options.map((option) => (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={choice === option.value}
            onClick={() => setChoice(option.value)}
            className={
              choice === option.value
                ? 'cd-btn cd-btn-primary !min-h-9'
                : 'cd-btn cd-btn-ghost !min-h-9'
            }
          >
            <option.icon size={16} />
            {option.label}
          </button>
        ))}
      </div>

      <hr className="my-5 border-[var(--cd-border)]" />

      <p className="text-sm font-semibold">Sessions</p>
      <p className="mt-0.5 text-sm text-[var(--cd-text-muted)]">
        Se déconnecter de tous les appareils est le geste à faire si vous perdez
        votre téléphone.
      </p>
      <button
        type="button"
        onClick={() => void logout(true)}
        className="cd-btn cd-btn-ghost mt-3 !text-[var(--cd-danger)]"
      >
        Déconnecter tous mes appareils
      </button>
    </section>
  )
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs tracking-wide text-[var(--cd-text-muted)] uppercase">
        {label}
      </dt>
      <dd className="mt-0.5 font-semibold">{value}</dd>
    </div>
  )
}
