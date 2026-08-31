import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft, Save, Upload } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Avatar } from '@/components/ui/Avatar'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { createMember, fetchMember, updateMember } from '@/lib/members'
import { hasAtLeastRole, useCurrentUser } from '@/stores/auth'
import type { MemberStatusCode } from '@/types/api'

const STATUSES: { value: MemberStatusCode; label: string }[] = [
  { value: 'ACTIVE', label: 'Actif' },
  { value: 'PENDING', label: 'En attente' },
  { value: 'SUSPENDED', label: 'Suspendu' },
  { value: 'FORMER', label: 'Ancien membre' },
]

interface FormState {
  first_name: string
  last_name: string
  phone: string
  email: string
  birth_date: string
  gender: string
  joined_at: string
  status: MemberStatusCode
  emergency_contact_name: string
  emergency_contact_phone: string
  notes: string
}

const EMPTY: FormState = {
  first_name: '',
  last_name: '',
  phone: '',
  email: '',
  birth_date: '',
  gender: '',
  joined_at: new Date().toISOString().slice(0, 10),
  status: 'ACTIVE',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  notes: '',
}

/**
 * Création et modification d'une fiche membre.
 *
 * Un seul écran pour les deux : les champs sont identiques, et maintenir deux
 * formulaires jumeaux garantit qu'ils finiront par diverger.
 */
export function MemberFormPage() {
  const { uuid } = useParams()
  const isEdit = Boolean(uuid)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useCurrentUser()
  const isAdmin = hasAtLeastRole(user, 'ADMIN')

  const [form, setForm] = useState<FormState>(EMPTY)
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)

  const existing = useQuery({
    queryKey: ['member', uuid],
    queryFn: () => fetchMember(uuid!),
    enabled: isEdit,
  })

  // Pré-remplissage à l'ouverture d'une fiche existante.
  useEffect(() => {
    if (!existing.data) return

    const m = existing.data
    setForm({
      first_name: m.first_name,
      last_name: m.last_name,
      phone: m.phone_formatted ?? '',
      email: m.email ?? '',
      birth_date: m.birth_date ?? '',
      gender: m.gender ?? '',
      joined_at: m.joined_at ?? '',
      status: m.status,
      emergency_contact_name: m.emergency_contact_name ?? '',
      emergency_contact_phone: m.emergency_contact_phone ?? '',
      notes: m.notes ?? '',
    })
  }, [existing.data])

  // L'URL d'aperçu est révoquée quand elle change ou que l'écran se ferme :
  // sinon chaque photo choisie fuirait de la mémoire du navigateur.
  useEffect(() => {
    if (!photo) {
      setPhotoPreview(null)
      return
    }

    const url = URL.createObjectURL(photo)
    setPhotoPreview(url)

    return () => URL.revokeObjectURL(url)
  }, [photo])

  const mutation = useMutation({
    mutationFn: () => {
      // Le statut n'est envoyé que si l'utilisateur a le droit de le changer :
      // sinon le serveur refuserait la requête entière.
      const values: Record<string, unknown> = { ...form }
      if (!isAdmin) delete values.status

      return isEdit
        ? updateMember(uuid!, values, photo)
        : createMember(values, photo)
    },
    onSuccess: (member) => {
      void queryClient.invalidateQueries({ queryKey: ['members'] })
      void queryClient.invalidateQueries({ queryKey: ['member', member.uuid] })
      navigate(`/members/${member.uuid}`, { replace: true })
    },
  })

  const error = mutation.error instanceof ApiError ? mutation.error : null

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    mutation.mutate()
  }

  if (isEdit && existing.isLoading) {
    return <div className="cd-card h-96 animate-pulse" />
  }

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <Link
        to={isEdit ? `/members/${uuid}` : '/members'}
        className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-text hover:underline"
      >
        <ArrowLeft size={15} />
        {isEdit ? 'Retour à la fiche' : 'Annuaire'}
      </Link>

      <form onSubmit={handleSubmit} className="cd-card space-y-5 p-5 sm:p-6" noValidate>
        <div>
          <h2 className="text-xl">
            {isEdit ? 'Modifier la fiche' : 'Nouveau membre'}
          </h2>
          {!isEdit && (
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Le matricule et le QR Code sont générés automatiquement. Le membre
              n'a pas besoin de compte de connexion.
            </p>
          )}
        </div>

        {error && Object.keys(error.errors).length === 0 && (
          <div
            role="alert"
            className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]"
          >
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <span>{error.message}</span>
          </div>
        )}

        {/* --- Photo ------------------------------------------------------ */}
        <div className="flex items-center gap-4">
          <Avatar
            photoUrl={photoPreview ?? existing.data?.photo_url}
            initials={
              (form.first_name[0] ?? '?').toUpperCase() +
              (form.last_name[0] ?? '').toUpperCase()
            }
            size={64}
          />
          <div>
            <label className="cd-btn cd-btn-ghost !min-h-9 cursor-pointer">
              <Upload size={16} />
              {photo ? 'Changer la photo' : 'Ajouter une photo'}
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(e) => setPhoto(e.target.files?.[0] ?? null)}
              />
            </label>
            <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
              JPEG, PNG ou WebP · 10 Mo maximum
            </p>
            {error?.fieldError('photo') && (
              <p role="alert" className="mt-1 text-xs font-medium text-[var(--cd-danger)]">
                {error.fieldError('photo')}
              </p>
            )}
          </div>
        </div>

        {/* --- Identité --------------------------------------------------- */}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Prénom"
            value={form.first_name}
            onChange={(e) => setForm({ ...form, first_name: e.target.value })}
            error={error?.fieldError('first_name')}
            required
            autoFocus={!isEdit}
          />
          <Field
            label="Nom"
            value={form.last_name}
            onChange={(e) => setForm({ ...form, last_name: e.target.value })}
            error={error?.fieldError('last_name')}
            required
          />
          <Field
            label="Téléphone"
            type="tel"
            inputMode="tel"
            placeholder="77 123 45 67"
            value={form.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
            error={error?.fieldError('phone')}
            hint="Saisissez-le comme vous voulez, il sera normalisé."
          />
          <Field
            label="Adresse email"
            type="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            error={error?.fieldError('email')}
          />
          <Field
            label="Date de naissance"
            type="date"
            value={form.birth_date}
            onChange={(e) => setForm({ ...form, birth_date: e.target.value })}
            error={error?.fieldError('birth_date')}
          />
          <Field
            label="Date d'adhésion"
            type="date"
            value={form.joined_at}
            onChange={(e) => setForm({ ...form, joined_at: e.target.value })}
            error={error?.fieldError('joined_at')}
          />
        </div>

        {/* --- Statut, réservé à l'administration ------------------------- */}
        {isAdmin && (
          <div className="space-y-1.5">
            <label htmlFor="status" className="block text-sm font-semibold">
              Statut
            </label>
            <select
              id="status"
              value={form.status}
              onChange={(e) =>
                setForm({ ...form, status: e.target.value as MemberStatusCode })
              }
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] outline-none focus:border-[var(--cd-orange)]"
            >
              {STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
            <p className="text-xs text-[var(--cd-text-muted)]">
              Seul un « Actif » est ajouté d'office aux nouvelles participations.
            </p>
          </div>
        )}

        {/* --- Urgence ---------------------------------------------------- */}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Contact d'urgence"
            value={form.emergency_contact_name}
            onChange={(e) =>
              setForm({ ...form, emergency_contact_name: e.target.value })
            }
            error={error?.fieldError('emergency_contact_name')}
          />
          <Field
            label="Téléphone d'urgence"
            type="tel"
            value={form.emergency_contact_phone}
            onChange={(e) =>
              setForm({ ...form, emergency_contact_phone: e.target.value })
            }
            error={error?.fieldError('emergency_contact_phone')}
          />
        </div>

        {isAdmin && (
          <div className="space-y-1.5">
            <label htmlFor="notes" className="block text-sm font-semibold">
              Notes internes
            </label>
            <textarea
              id="notes"
              rows={3}
              value={form.notes}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] outline-none focus:border-[var(--cd-orange)]"
            />
            <p className="text-xs text-[var(--cd-text-muted)]">
              Visibles uniquement par les administrateurs.
            </p>
          </div>
        )}

        <div className="flex flex-wrap gap-2 pt-1">
          <button
            type="submit"
            disabled={mutation.isPending}
            className="cd-btn cd-btn-primary"
          >
            <Save size={16} />
            {mutation.isPending
              ? 'Enregistrement…'
              : isEdit
                ? 'Enregistrer'
                : 'Créer le membre'}
          </button>
          <Link
            to={isEdit ? `/members/${uuid}` : '/members'}
            className="cd-btn cd-btn-ghost"
          >
            Annuler
          </Link>
        </div>
      </form>
    </div>
  )
}
