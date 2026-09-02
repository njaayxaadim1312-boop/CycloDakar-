import { useMutation, useQueryClient } from '@tanstack/react-query'
import { PlusCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { ApiError } from '@/lib/api'
import { createChallenge } from '@/lib/community'
import type { ChallengeMetricCode, SportCode } from '@/types/api'

/**
 * Proposer un défi.
 *
 * LA SAISIE EST DANS L'UNITÉ DU CHEF DE GROUPE, PAS DANS CELLE DE L'API.
 *
 * On tape « 100 » kilomètres, « 8 » sorties, « 5 » heures. La conversion vers
 * les mètres et les secondes se fait ICI, à la frontière — un champ unique qui
 * signifierait tantôt des kilomètres, tantôt des mètres finirait par produire
 * un défi mille fois trop court, et personne ne comprendrait pourquoi tout le
 * monde l'a réussi le premier jour.
 *
 * Le défi naît **annoncé**, pas en brouillon : proposer un défi et devoir
 * ensuite le publier serait une étape de plus pour rien. Un chef de groupe qui
 * veut préparer sans annoncer peut encore le faire par l'API.
 */
const MESURES: Array<{
  code: ChallengeMetricCode
  label: string
  unite: string
  facteur: number
  exemple: string
}> = [
  { code: 'distance', label: 'Distance', unite: 'km', facteur: 1000, exemple: '100' },
  { code: 'activities', label: 'Nombre de sorties', unite: 'sorties', facteur: 1, exemple: '8' },
  { code: 'duration', label: 'Temps en mouvement', unite: 'heures', facteur: 3600, exemple: '10' },
  { code: 'elevation', label: 'Dénivelé positif', unite: 'm D+', facteur: 1, exemple: '1500' },
]

const SPORTS: Array<{ code: SportCode | ''; label: string }> = [
  { code: '', label: 'Tous sports' },
  { code: 'CYCLING', label: 'Vélo' },
  { code: 'RUNNING', label: 'Course' },
  { code: 'WALKING', label: 'Marche' },
  { code: 'HIKING', label: 'Randonnée' },
]

export function ChallengeDialog() {
  const dialog = useRef<HTMLDialogElement>(null)
  const queryClient = useQueryClient()

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [metric, setMetric] = useState<ChallengeMetricCode>('distance')
  const [target, setTarget] = useState('')
  const [sport, setSport] = useState<SportCode | ''>('')
  const [startsOn, setStartsOn] = useState(() => new Date().toISOString().slice(0, 10))
  const [endsOn, setEndsOn] = useState('')

  const mesure = MESURES.find((m) => m.code === metric) ?? MESURES[0]!

  const save = useMutation({
    mutationFn: () =>
      createChallenge({
        title: title.trim(),
        description: description.trim() === '' ? null : description.trim(),
        metric,
        // La conversion vers l'unité SI, à la frontière.
        target: Math.round(Number(target) * mesure.facteur),
        sport: sport === '' ? null : sport,
        starts_on: startsOn,
        ends_on: endsOn,
        status: 'PUBLISHED',
      }),
    onSuccess: () => {
      setTitle('')
      setDescription('')
      setTarget('')
      void queryClient.invalidateQueries({ queryKey: ['challenges'] })
      dialog.current?.close()
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

  function open() {
    // Par défaut, la fin du mois : c'est la fenêtre d'un défi de club, et
    // laisser le champ vide obligerait à réfléchir avant même de commencer.
    const fin = new Date()
    fin.setMonth(fin.getMonth() + 1, 0)
    setEndsOn(fin.toISOString().slice(0, 10))

    save.reset()
    dialog.current?.showModal()
  }

  return (
    <>
      <button
        type="button"
        onClick={open}
        className="inline-flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
      >
        <PlusCircle size={16} aria-hidden="true" />
        Proposer un défi
      </button>

      <dialog
        ref={dialog}
        className="cd-pop m-auto w-[min(92vw,28rem)] rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-0 text-[var(--cd-text)] backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        onClose={() => save.reset()}
      >
        <form
          method="dialog"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
          className="max-h-[85dvh] space-y-4 overflow-y-auto p-5"
        >
          <div>
            <h2 className="text-lg font-bold">Nouveau défi</h2>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Un objectif atteignable donne envie de recommencer ; un objectif hors de
              portée décourage.
            </p>
          </div>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Titre</span>
            <input
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              required
              minLength={3}
              maxLength={160}
              placeholder="100 km en septembre"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            />
            {error?.fieldError('title') !== undefined && (
              <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                {error.fieldError('title')}
              </span>
            )}
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Mesure</span>
            <select
              value={metric}
              onChange={(event) => setMetric(event.target.value as ChallengeMetricCode)}
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            >
              {MESURES.map((option) => (
                <option key={option.code} value={option.code}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">
              Objectif ({mesure.unite})
            </span>
            <input
              type="number"
              inputMode="numeric"
              min={1}
              step={metric === 'duration' ? 0.5 : 1}
              value={target}
              onChange={(event) => setTarget(event.target.value)}
              required
              placeholder={mesure.exemple}
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[17px] font-semibold tabular-nums"
            />
            {/* L'unité est rappelée à côté du champ : c'est là qu'une erreur
                de facteur mille se glisse, et elle ne se voit qu'après. */}
            <span className="block text-xs text-[var(--cd-text-muted)]">
              En {mesure.unite}. Le club enregistre en unités internationales ;
              la conversion se fait ici.
            </span>
            {error?.fieldError('target') !== undefined && (
              <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                {error.fieldError('target')}
              </span>
            )}
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Sport</span>
            <select
              value={sport}
              onChange={(event) => setSport(event.target.value as SportCode | '')}
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            >
              {SPORTS.map((option) => (
                <option key={option.code} value={option.code}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <div className="grid grid-cols-2 gap-3">
            <label className="block space-y-1">
              <span className="block text-[13px] font-semibold">Du</span>
              <input
                type="date"
                value={startsOn}
                onChange={(event) => setStartsOn(event.target.value)}
                required
                className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
              />
            </label>
            <label className="block space-y-1">
              <span className="block text-[13px] font-semibold">Au</span>
              <input
                type="date"
                value={endsOn}
                onChange={(event) => setEndsOn(event.target.value)}
                required
                min={startsOn}
                className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
              />
            </label>
          </div>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Détail</span>
            <textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              rows={3}
              maxLength={2000}
              placeholder="Facultatif — ce qui donnera envie de s'inscrire"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            />
          </label>

          {error !== null && Object.keys(error.errors).length === 0 && (
            <p role="alert" className="text-sm text-[var(--cd-danger)]">
              {error.message}
            </p>
          )}

          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => dialog.current?.close()}
              className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-4 py-2 text-sm font-medium"
            >
              Annuler
            </button>
            <button
              type="submit"
              disabled={save.isPending}
              className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] disabled:opacity-60"
            >
              {save.isPending ? 'Création…' : 'Annoncer le défi'}
            </button>
          </div>
        </form>
      </dialog>
    </>
  )
}
