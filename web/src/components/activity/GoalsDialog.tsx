import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Target } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { ApiError } from '@/lib/api'
import { updateGoals } from '@/lib/stats'
import type { WeeklyGoals } from '@/types/api'

interface GoalsDialogProps {
  goals: WeeklyGoals
}

/**
 * Réglage des objectifs hebdomadaires.
 *
 * Deux partis pris :
 *
 * - **La saisie est dans l'unité du membre** : des kilomètres et des minutes,
 *   pas des mètres et des secondes. La conversion vers les unités SI de l'API
 *   se fait ici, à la frontière.
 * - **Le dialogue est natif** (`<dialog>`). Il apporte gratuitement le
 *   piégeage du focus, la fermeture à l'échappement et le fond inerte — trois
 *   choses qu'une `<div>` avec `position: fixed` ne fait jamais correctement.
 */
export function GoalsDialog({ goals }: GoalsDialogProps) {
  const dialog = useRef<HTMLDialogElement>(null)
  const queryClient = useQueryClient()

  const [km, setKm] = useState('')
  const [minutes, setMinutes] = useState('')
  const [count, setCount] = useState('')

  // Les champs se resynchronisent sur les objectifs réels : rouvrir le
  // dialogue après une modification doit montrer la valeur enregistrée, pas
  // celle qu'on avait tapée la fois d'avant.
  useEffect(() => {
    setKm(String(Math.round(goals.distance_m / 1000)))
    setMinutes(String(Math.round(goals.moving_time_s / 60)))
    setCount(String(goals.activities))
  }, [goals])

  const save = useMutation({
    mutationFn: () =>
      updateGoals({
        distance_m: Math.round(Number(km) * 1000),
        moving_time_s: Math.round(Number(minutes) * 60),
        activities: Number(count),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['stats'] })
      dialog.current?.close()
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

  return (
    <>
      <button
        type="button"
        onClick={() => dialog.current?.showModal()}
        className="inline-flex items-center gap-1.5 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-3 py-1.5 text-xs font-medium text-[var(--cd-text-muted)] transition-colors hover:border-[var(--cd-orange)] hover:text-[var(--cd-text)]"
      >
        <Target size={14} aria-hidden="true" />
        Mes objectifs
      </button>

      <dialog
        ref={dialog}
        className="cd-pop m-auto w-[min(92vw,26rem)] rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-0 text-[var(--cd-text)] backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        onClose={() => save.reset()}
      >
        <form
          method="dialog"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
          className="space-y-5 p-5"
        >
          <div>
            <h2 className="text-lg font-bold">Mes objectifs de la semaine</h2>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Vous seul les fixez. Un objectif atteint donne envie de le relever ;
              un objectif hors de portée décourage.
            </p>
          </div>

          <GoalField
            label="Distance"
            unit="km"
            value={km}
            onChange={setKm}
            min={0}
            max={700}
            error={error?.fieldError('distance_m')}
          />
          <GoalField
            label="Temps en mouvement"
            unit="min"
            value={minutes}
            onChange={setMinutes}
            min={0}
            max={2400}
            error={error?.fieldError('moving_time_s')}
          />
          <GoalField
            label="Sorties"
            unit="par semaine"
            value={count}
            onChange={setCount}
            min={0}
            max={30}
            error={error?.fieldError('activities')}
          />

          <p className="text-xs text-[var(--cd-text-muted)]">
            Mettez un objectif à zéro pour le désactiver : son anneau ne sera plus
            compté.
          </p>

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
              className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
            >
              {save.isPending ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </form>
      </dialog>
    </>
  )
}

function GoalField({
  label,
  unit,
  value,
  onChange,
  min,
  max,
  error,
}: {
  label: string
  unit: string
  value: string
  onChange: (value: string) => void
  min: number
  max: number
  error?: string
}) {
  return (
    <label className="block space-y-1.5">
      <span className="block text-sm font-semibold">{label}</span>
      <span className="flex items-center gap-2">
        <input
          type="number"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          min={min}
          max={max}
          required
          aria-invalid={error ? true : undefined}
          className={[
            'w-28 rounded-[var(--cd-radius-sm)] border bg-[var(--cd-surface)] px-3 py-2 text-[15px] tabular-nums outline-none transition-colors',
            error
              ? 'border-[var(--cd-danger)]'
              : 'border-[var(--cd-border-strong)] focus:border-[var(--cd-orange)]',
          ].join(' ')}
        />
        <span className="text-sm text-[var(--cd-text-muted)]">{unit}</span>
      </span>
      {error && (
        <span role="alert" className="block text-xs font-medium text-[var(--cd-danger)]">
          {error}
        </span>
      )}
    </label>
  )
}
