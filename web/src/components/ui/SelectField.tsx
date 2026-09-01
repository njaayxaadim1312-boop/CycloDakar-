import clsx from 'clsx'
import type { ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { useId } from 'react'

/**
 * Listes déroulantes et zones de texte.
 *
 * Même habillage que `Field` — étiquette, aide, erreur liée par
 * `aria-describedby` — pour les deux contrôles que `Field` ne couvre pas.
 *
 * Extrait après avoir recopié la même chaîne de classes une quatrième fois
 * (fiche membre, notes internes, formulaire d'événement). Un habillage
 * dupliqué finit toujours par diverger, et c'est la bordure d'erreur qui est
 * oubliée en premier.
 */

interface CommonProps {
  label: string
  error?: string
  hint?: ReactNode
}

const CONTROL =
  'w-full rounded-[var(--cd-radius-sm)] border bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] transition-colors outline-none placeholder:text-[var(--cd-text-muted)]'

function borderClass(error?: string): string {
  return error
    ? 'border-[var(--cd-danger)] focus:border-[var(--cd-danger)]'
    : 'border-[var(--cd-border-strong)] focus:border-[var(--cd-orange)]'
}

function Frame({
  id,
  label,
  hint,
  error,
  children,
}: CommonProps & { id: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-semibold">
        {label}
      </label>

      {children}

      {hint && !error && (
        <p id={`${id}-hint`} className="text-xs text-[var(--cd-text-muted)]">
          {hint}
        </p>
      )}

      {error && (
        <p id={`${id}-error`} role="alert" className="text-xs font-medium text-[var(--cd-danger)]">
          {error}
        </p>
      )}
    </div>
  )
}

function describedBy(id: string, error?: string, hint?: ReactNode): string | undefined {
  return [error && `${id}-error`, hint && `${id}-hint`].filter(Boolean).join(' ') || undefined
}

type SelectFieldProps = CommonProps &
  Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id'> & { children: ReactNode }

export function SelectField({ label, error, hint, children, ...props }: SelectFieldProps) {
  const id = useId()

  return (
    <Frame id={id} label={label} error={error} hint={hint}>
      <select
        {...props}
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        className={clsx(CONTROL, borderClass(error))}
      >
        {children}
      </select>
    </Frame>
  )
}

type TextareaFieldProps = CommonProps &
  Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'>

export function TextareaField({ label, error, hint, ...props }: TextareaFieldProps) {
  const id = useId()

  return (
    <Frame id={id} label={label} error={error} hint={hint}>
      <textarea
        {...props}
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        className={clsx(CONTROL, borderClass(error))}
      />
    </Frame>
  )
}
