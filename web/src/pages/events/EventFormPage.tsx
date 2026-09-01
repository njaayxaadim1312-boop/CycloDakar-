import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Field } from '@/components/ui/Field'
import { SelectField, TextareaField } from '@/components/ui/SelectField'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { createEvent, fetchEvent, updateEvent, type EventInput } from '@/lib/events'
import type { ClubEvent } from '@/types/api'

/**
 * Création et modification d'une sortie.
 *
 * Deux conversions se font ici, et nulle part ailleurs :
 *
 *  - **kilomètres → mètres.** Le bureau saisit « 35 », l'API reçoit 35 000.
 *    Personne ne saisit une distance de sortie en mètres, et l'API n'accepte
 *    que des mètres : la conversion doit vivre à la frontière, pas se
 *    disperser dans les écrans.
 *  - **heure locale → ISO.** Le champ `datetime-local` ne porte pas de fuseau ;
 *    on l'interprète dans celui du navigateur, qui est celui du club.
 */

interface FormState {
  title: string
  description: string
  sport: string
  starts_at: string
  ends_at: string
  location_name: string
  distance_km: string
  difficulty: string
  max_participants: string
}

const EMPTY: FormState = {
  title: '',
  description: '',
  sport: 'CYCLING',
  starts_at: '',
  ends_at: '',
  location_name: '',
  distance_km: '',
  difficulty: '',
  max_participants: '',
}

/** `2026-09-08T07:30` — le format attendu par `datetime-local`. */
function toLocalInput(iso: string | null): string {
  if (iso === null) return ''

  const date = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(
    date.getHours(),
  )}:${pad(date.getMinutes())}`
}

function fromForm(state: FormState): EventInput {
  const km = state.distance_km.trim()
  const seats = state.max_participants.trim()

  return {
    title: state.title.trim(),
    description: state.description.trim() === '' ? null : state.description.trim(),
    sport: state.sport as EventInput['sport'],
    starts_at: new Date(state.starts_at).toISOString(),
    ends_at: state.ends_at === '' ? null : new Date(state.ends_at).toISOString(),
    location_name: state.location_name.trim(),
    // Kilomètres saisis, mètres envoyés. Voir le commentaire de module.
    planned_distance_m: km === '' ? null : Math.round(Number(km) * 1000),
    difficulty: state.difficulty === '' ? null : state.difficulty,
    // Champ vide = pas de limite, et non zéro place.
    max_participants: seats === '' ? null : Number(seats),
  }
}

export function EventFormPage() {
  const { uuid } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const editing = uuid !== undefined

  const [form, setForm] = useState<FormState>(EMPTY)

  const existing = useQuery({
    queryKey: ['event', uuid],
    queryFn: () => fetchEvent(uuid as string),
    enabled: editing,
  })

  useEffect(() => {
    if (existing.data === undefined) return

    const event: ClubEvent = existing.data

    setForm({
      title: event.title,
      description: event.description ?? '',
      sport: event.sport,
      starts_at: toLocalInput(event.starts_at),
      ends_at: toLocalInput(event.ends_at),
      location_name: event.location_name,
      distance_km:
        event.planned_distance_m !== null ? String(event.planned_distance_m / 1000) : '',
      difficulty: event.difficulty ?? '',
      max_participants:
        event.max_participants !== null ? String(event.max_participants) : '',
    })
  }, [existing.data])

  const save = useMutation({
    mutationFn: (input: EventInput) =>
      editing ? updateEvent(uuid as string, input) : createEvent(input),
    onSuccess: (event) => {
      void queryClient.invalidateQueries({ queryKey: ['events'] })
      void queryClient.invalidateQueries({ queryKey: ['event', event.uuid] })
      navigate(`/events/${event.uuid}`)
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  return (
    <div className="space-y-5">
      <Link
        to={editing ? `/events/${uuid}` : '/events'}
        className="inline-flex items-center gap-1.5 text-sm text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]"
      >
        <ArrowLeft size={15} aria-hidden="true" />
        Retour
      </Link>

      <PageHeader
        title={editing ? 'Modifier la sortie' : 'Nouvelle sortie'}
        description={
          editing
            ? undefined
            : "La sortie est créée en brouillon : vous l'annoncerez au club quand elle sera prête."
        }
      />

      {error !== null && Object.keys(error.errors).length === 0 && (
        <p className="flex items-center gap-2 rounded-2xl border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {error.message}
        </p>
      )}

      <form
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate(fromForm(form))
        }}
        className="max-w-2xl space-y-5 rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5"
      >
        <Field
          label="Titre"
          value={form.title}
          onChange={(e) => set('title', e.target.value)}
          error={error?.fieldError('title')}
          required
          maxLength={160}
          placeholder="Grand Tour Cyclo Dakar"
          autoFocus={!editing}
        />

        <TextareaField
          label="Description"
          value={form.description}
          onChange={(e) => set('description', e.target.value)}
          error={error?.fieldError('description')}
          rows={4}
          maxLength={5000}
          placeholder="Parcours, points de ravitaillement, consignes de securite..."
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            label="Sport"
            value={form.sport}
            onChange={(e) => set('sport', e.target.value)}
            error={error?.fieldError('sport')}
          >
            <option value="CYCLING">Cyclisme</option>
            <option value="RUNNING">Course</option>
            <option value="HIKING">Randonnée</option>
          </SelectField>

          <SelectField
            label="Difficulté"
            value={form.difficulty}
            onChange={(e) => set('difficulty', e.target.value)}
            error={error?.fieldError('difficulty')}
            hint="Un membre qui débute doit pouvoir écarter une sortie trop longue."
          >
            <option value="">Non précisée</option>
            <option value="EASY">Facile</option>
            <option value="MEDIUM">Modéré</option>
            <option value="HARD">Difficile</option>
          </SelectField>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Départ"
            type="datetime-local"
            value={form.starts_at}
            onChange={(e) => set('starts_at', e.target.value)}
            error={error?.fieldError('starts_at')}
            required
          />

          <Field
            label="Fin prévue"
            type="datetime-local"
            value={form.ends_at}
            onChange={(e) => set('ends_at', e.target.value)}
            error={error?.fieldError('ends_at')}
          />
        </div>

        <Field
          label="Lieu de rendez-vous"
          value={form.location_name}
          onChange={(e) => set('location_name', e.target.value)}
          error={error?.fieldError('location_name')}
          required
          maxLength={160}
          placeholder="Place de la Nation"
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Distance prévue (km)"
            type="number"
            min={0}
            max={500}
            step={0.5}
            value={form.distance_km}
            onChange={(e) => set('distance_km', e.target.value)}
            error={error?.fieldError('planned_distance_m')}
            placeholder="35"
            hint="Saisie en kilomètres, transmise en mètres."
          />

          <Field
            label="Places"
            type="number"
            min={1}
            max={2000}
            value={form.max_participants}
            onChange={(e) => set('max_participants', e.target.value)}
            error={error?.fieldError('max_participants')}
            placeholder="Illimité"
            hint="Vide = pas de limite. Une fois complet, les membres passent en liste d’attente."
          />
        </div>

        <div className="flex flex-wrap gap-3 pt-1">
          <button
            type="submit"
            disabled={save.isPending}
            className="rounded-full bg-[var(--cd-orange)] px-5 py-2.5 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
          >
            {save.isPending ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer la sortie'}
          </button>

          <Link
            to={editing ? `/events/${uuid}` : '/events'}
            className="rounded-full border border-[var(--cd-border)] px-5 py-2.5 text-sm font-medium"
          >
            Annuler
          </Link>
        </div>
      </form>
    </div>
  )
}
