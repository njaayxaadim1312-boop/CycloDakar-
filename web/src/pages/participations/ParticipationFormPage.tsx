import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Field } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { TextareaField } from '@/components/ui/SelectField'
import { ApiError } from '@/lib/api'
import { formatFcfa } from '@/lib/format'
import {
  createParticipation,
  fetchParticipation,
  updateParticipation,
  type ParticipationInput,
} from '@/lib/participations'

/**
 * Création et modification d'une campagne de collecte.
 *
 * **Le montant est saisi en francs, entier, sans conversion.** Le franc CFA
 * n'a pas de subdivision : il n'y a rien à multiplier ni à diviser entre le
 * champ et l'API, contrairement aux distances qui passent des kilomètres aux
 * mètres. Le champ refuse les décimales, et l'API les refuserait de toute
 * façon plutôt que de les arrondir en silence.
 *
 * Un aperçu du montant mis en forme est affiché sous le champ : « 5000 » tapé
 * à la volée se lit mal, « 5 000 FCFA » ne laisse aucun doute.
 */

interface FormState {
  name: string
  description: string
  amount: string
  starts_on: string
  due_on: string
}

const EMPTY: FormState = {
  name: '',
  description: '',
  amount: '',
  starts_on: new Date().toISOString().slice(0, 10),
  due_on: '',
}

export function ParticipationFormPage() {
  const { uuid } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const editing = uuid !== undefined

  const [form, setForm] = useState<FormState>(EMPTY)

  const existing = useQuery({
    queryKey: ['participation', uuid],
    queryFn: () => fetchParticipation(uuid as string),
    enabled: editing,
  })

  useEffect(() => {
    if (existing.data === undefined) return

    setForm({
      name: existing.data.name,
      description: existing.data.description ?? '',
      amount: String(existing.data.expected_amount),
      starts_on: existing.data.starts_on ?? '',
      due_on: existing.data.due_on ?? '',
    })
  }, [existing.data])

  const save = useMutation({
    mutationFn: (input: ParticipationInput) =>
      editing ? updateParticipation(uuid as string, input) : createParticipation(input),
    onSuccess: (participation) => {
      void queryClient.invalidateQueries({ queryKey: ['participations'] })
      void queryClient.invalidateQueries({ queryKey: ['participation', participation.uuid] })
      navigate(`/participations/${participation.uuid}`)
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  const amount = Number(form.amount)
  const amountIsValid = Number.isInteger(amount) && amount > 0

  return (
    <div className="space-y-5">
      <Link
        to={editing ? `/participations/${uuid}` : '/participations'}
        className="inline-flex items-center gap-1.5 text-sm text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]"
      >
        <ArrowLeft size={15} aria-hidden="true" />
        Retour
      </Link>

      <PageHeader
        title={editing ? 'Modifier la collecte' : 'Nouvelle collecte'}
        description={
          editing
            ? "Changer le montant ne modifie pas les dettes déjà créées : elles gardent le montant figé à leur rattachement."
            : "La collecte est créée en brouillon : vous l'ouvrirez quand elle sera prête."
        }
      />

      {error !== null && Object.keys(error.errors).length === 0 && (
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {error.message}
        </p>
      )}

      <form
        onSubmit={(event) => {
          event.preventDefault()

          save.mutate({
            name: form.name.trim(),
            description: form.description.trim() === '' ? null : form.description.trim(),
            // Entier de FCFA, envoyé tel quel. Rien à convertir.
            expected_amount: amount,
            starts_on: form.starts_on,
            due_on: form.due_on,
          })
        }}
        className="max-w-2xl space-y-5 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5"
      >
        <Field
          label="Nom de la collecte"
          value={form.name}
          onChange={(e) => set('name', e.target.value)}
          error={error?.fieldError('name')}
          required
          maxLength={160}
          placeholder="Sortie Lac Rose"
          autoFocus={!editing}
        />

        <TextareaField
          label="Description"
          value={form.description}
          onChange={(e) => set('description', e.target.value)}
          error={error?.fieldError('description')}
          rows={3}
          maxLength={5000}
          placeholder="Transport, repas et dossards."
        />

        <Field
          label="Montant par membre (FCFA)"
          type="number"
          min={1}
          step={1}
          value={form.amount}
          onChange={(e) => set('amount', e.target.value)}
          error={error?.fieldError('expected_amount')}
          required
          placeholder="5000"
          hint={
            amountIsValid
              ? `Chaque membre devra ${formatFcfa(amount)}.`
              : 'Nombre entier de francs, sans décimale ni espace.'
          }
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Début"
            type="date"
            value={form.starts_on}
            onChange={(e) => set('starts_on', e.target.value)}
            error={error?.fieldError('starts_on')}
            required
          />

          <Field
            label="Échéance"
            type="date"
            value={form.due_on}
            onChange={(e) => set('due_on', e.target.value)}
            error={error?.fieldError('due_on')}
            required
          />
        </div>

        <div className="flex flex-wrap gap-3 pt-1">
          <button
            type="submit"
            disabled={save.isPending}
            className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-5 py-2.5 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
          >
            {save.isPending
              ? 'Enregistrement…'
              : editing
                ? 'Enregistrer'
                : 'Créer la collecte'}
          </button>

          <Link
            to={editing ? `/participations/${uuid}` : '/participations'}
            className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-5 py-2.5 text-sm font-medium"
          >
            Annuler
          </Link>
        </div>
      </form>
    </div>
  )
}
