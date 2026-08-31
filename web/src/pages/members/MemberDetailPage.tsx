import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertCircle,
  ArrowLeft,
  CalendarDays,
  Mail,
  Pencil,
  Phone,
  QrCode,
  RefreshCw,
  ShieldCheck,
} from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Avatar } from '@/components/ui/Avatar'
import {
  MemberStatusBadge,
  NoAccountBadge,
  RoleBadge,
} from '@/components/ui/StatusBadge'
import { ApiError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { fetchMember, rotateQrCode, updateMemberRole } from '@/lib/members'
import type { Member, RoleCode } from '@/types/api'

const ASSIGNABLE_ROLES: { value: RoleCode; label: string; help: string }[] = [
  { value: 'MEMBER', label: 'Membre', help: 'Ses activités, ses participations.' },
  { value: 'COLLECTOR', label: 'Collecteur', help: 'Peut encaisser les participations qui lui sont confiées.' },
  { value: 'TREASURER', label: 'Trésorier', help: 'Gère la caisse, les recettes et les dépenses.' },
  { value: 'ADMIN', label: 'Administrateur', help: 'Gestion complète du club.' },
  { value: 'SUPER_ADMIN', label: 'Super administrateur', help: 'Gère aussi les administrateurs.' },
]

export function MemberDetailPage() {
  const { uuid = '' } = useParams()
  const navigate = useNavigate()

  const query = useQuery({
    queryKey: ['member', uuid],
    queryFn: () => fetchMember(uuid),
    enabled: uuid !== '',
  })

  if (query.isLoading) {
    return <div className="cd-card h-64 animate-pulse" />
  }

  if (query.isError) {
    return (
      <div className="cd-card mx-auto max-w-md p-8 text-center">
        <AlertCircle size={28} className="mx-auto text-[var(--cd-danger)]" />
        <p className="mt-3 font-semibold">
          {query.error instanceof ApiError && query.error.status === 404
            ? 'Ce membre est introuvable.'
            : 'La fiche n’a pas pu être chargée.'}
        </p>
        <button onClick={() => navigate('/members')} className="cd-btn cd-btn-primary mt-5">
          <ArrowLeft size={16} />
          Retour à l'annuaire
        </button>
      </div>
    )
  }

  const member = query.data!

  return (
    <div className="space-y-5">
      <Link
        to="/members"
        className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-text hover:underline"
      >
        <ArrowLeft size={15} />
        Annuaire
      </Link>

      {/* --- Identité ----------------------------------------------------- */}
      <section className="cd-card p-5 sm:p-6">
        <div className="flex flex-wrap items-start gap-4">
          <Avatar photoUrl={member.photo_url} initials={member.initials} size={80} />

          <div className="min-w-0 flex-1">
            <h2 className="text-2xl">{member.full_name}</h2>
            <p className="tabular mt-0.5 text-sm font-semibold text-brand-text">
              {member.matricule}
            </p>

            <div className="mt-3 flex flex-wrap items-center gap-2">
              <MemberStatusBadge status={member.status} label={member.status_label} />
              {member.account ? (
                <RoleBadge role={member.account.role} label={member.account.role_label} />
              ) : (
                <NoAccountBadge />
              )}
            </div>
          </div>

          {member.permissions?.update && (
            <Link to={`/members/${member.uuid}/modifier`} className="cd-btn cd-btn-ghost">
              <Pencil size={16} />
              Modifier
            </Link>
          )}
        </div>

        <dl className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {member.phone_formatted && (
            <Info icon={Phone} label="Téléphone" value={member.phone_formatted} />
          )}
          {member.email && <Info icon={Mail} label="Email" value={member.email} />}
          {member.joined_at && (
            <Info
              icon={CalendarDays}
              label="Membre depuis"
              value={`${formatDate(member.joined_at)}${
                member.seniority_years > 0 ? ` · ${member.seniority_years} an${member.seniority_years > 1 ? 's' : ''}` : ''
              }`}
            />
          )}
        </dl>

        {/*
          Un membre sans compte n'est pas une anomalie : c'est le cas de tous
          les adhérents sans smartphone. On l'explique plutôt que de laisser
          un vide qui ressemblerait à un bug.
        */}
        {!member.has_account && (
          <p className="mt-5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-surface-2)] p-3 text-sm text-[var(--cd-text-muted)]">
            Ce membre n'a pas de compte de connexion. Il figure dans l'effectif et
            dans les collectes, et son QR Code peut lui être remis imprimé.
          </p>
        )}
      </section>

      {/* --- Informations privées ----------------------------------------- */}
      {(member.birth_date || member.emergency_contact_name || member.notes) && (
        <section className="cd-card p-5">
          <h3 className="text-lg">Informations complémentaires</h3>
          <dl className="mt-4 grid gap-4 sm:grid-cols-2">
            {member.birth_date && (
              <Info label="Date de naissance" value={formatDate(member.birth_date)} />
            )}
            {member.emergency_contact_name && (
              <Info
                label="Contact d'urgence"
                value={`${member.emergency_contact_name}${
                  member.emergency_contact_phone ? ` · ${member.emergency_contact_phone}` : ''
                }`}
              />
            )}
          </dl>
          {member.notes && (
            <p className="mt-4 rounded-[var(--cd-radius-sm)] bg-[var(--cd-surface-2)] p-3 text-sm whitespace-pre-line">
              {member.notes}
            </p>
          )}
        </section>
      )}

      {member.permissions?.update_role && member.account && <RoleSection member={member} />}
      {member.permissions?.manage_qr && <QrSection member={member} />}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function RoleSection({ member }: { member: Member }) {
  const queryClient = useQueryClient()
  const [role, setRole] = useState<RoleCode>(member.account!.role)
  const [reason, setReason] = useState('')

  const mutation = useMutation({
    mutationFn: () => updateMemberRole(member.uuid, role, reason || undefined),
    onSuccess: () => {
      setReason('')
      void queryClient.invalidateQueries({ queryKey: ['member', member.uuid] })
      void queryClient.invalidateQueries({ queryKey: ['members'] })
    },
  })

  const changed = role !== member.account!.role
  const selected = ASSIGNABLE_ROLES.find((r) => r.value === role)

  return (
    <section className="cd-card p-5">
      <h3 className="flex items-center gap-2 text-lg">
        <ShieldCheck size={19} className="text-[var(--cd-orange)]" />
        Rôle et permissions
      </h3>
      <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
        Attribuer un rôle donne accès à la caisse du club. Chaque changement est
        enregistré dans le journal d'audit, et déconnecte le membre de tous ses
        appareils.
      </p>

      <div className="mt-4 space-y-3">
        <select
          value={role}
          onChange={(e) => setRole(e.target.value as RoleCode)}
          aria-label="Rôle du membre"
          className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] outline-none focus:border-[var(--cd-orange)] sm:max-w-sm"
        >
          {ASSIGNABLE_ROLES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        {selected && (
          <p className="text-sm text-[var(--cd-text-muted)]">{selected.help}</p>
        )}

        {changed && (
          <>
            <input
              type="text"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Motif (ex. : élu trésorier en assemblée générale)"
              aria-label="Motif du changement de rôle"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] outline-none focus:border-[var(--cd-orange)]"
            />

            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => mutation.mutate()}
                disabled={mutation.isPending}
                className="cd-btn cd-btn-primary"
              >
                {mutation.isPending ? 'Enregistrement…' : `Nommer ${selected?.label.toLowerCase()}`}
              </button>
              <button
                type="button"
                onClick={() => setRole(member.account!.role)}
                className="cd-btn cd-btn-ghost"
              >
                Annuler
              </button>
            </div>
          </>
        )}

        {mutation.isError && (
          <p role="alert" className="text-sm text-[var(--cd-danger)]">
            {mutation.error instanceof ApiError
              ? (mutation.error.fieldError('role') ?? mutation.error.message)
              : 'Le rôle n’a pas pu être modifié.'}
          </p>
        )}

        {mutation.isSuccess && !changed && (
          <p className="text-sm font-medium text-[var(--cd-green-hover)]">
            Rôle mis à jour.
          </p>
        )}
      </div>
    </section>
  )
}

function QrSection({ member }: { member: Member }) {
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)

  const mutation = useMutation({
    mutationFn: () => rotateQrCode(member.uuid),
    onSuccess: () => {
      setConfirming(false)
      void queryClient.invalidateQueries({ queryKey: ['member', member.uuid] })
    },
  })

  return (
    <section className="cd-card p-5">
      <h3 className="flex items-center gap-2 text-lg">
        <QrCode size={19} className="text-[var(--cd-blue)]" />
        QR Code personnel
      </h3>
      <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
        Il permet au collecteur d'identifier le membre en un scan. Il ne contient
        aucune donnée personnelle : photographié par un tiers, il ne révèle rien.
      </p>

      {/*
        L'image du QR et son impression arrivent en phase 11, avec le scanner
        mobile. On ne montre pas un faux QR en attendant.
      */}
      <p className="mt-4 rounded-[var(--cd-radius-sm)] bg-[var(--cd-surface-2)] p-3 text-sm text-[var(--cd-text-muted)]">
        L'affichage et l'impression du QR Code arrivent en phase 11, avec le
        scanner mobile. Le jeton, lui, existe déjà.
      </p>

      <div className="mt-4">
        {confirming ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm">
              Générer un nouveau QR ? L'ancien cessera immédiatement de fonctionner.
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
            Régénérer le QR Code
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

function Info({
  icon: Icon,
  label,
  value,
}: {
  icon?: typeof Phone
  label: string
  value: string
}) {
  return (
    <div>
      <dt className="flex items-center gap-1.5 text-xs tracking-wide text-[var(--cd-text-muted)] uppercase">
        {Icon && <Icon size={13} />}
        {label}
      </dt>
      <dd className="mt-0.5 font-semibold">{value}</dd>
    </div>
  )
}
