import clsx from 'clsx'
import { Eye, EyeOff } from 'lucide-react'
import { useId, useState, type InputHTMLAttributes, type ReactNode } from 'react'

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  /** Message d'erreur renvoyé par l'API pour ce champ. */
  error?: string
  hint?: ReactNode
  /** Ajoute un bouton « afficher / masquer » sur un champ mot de passe. */
  revealable?: boolean
}

/**
 * Champ de formulaire.
 *
 * L'erreur est liée au champ par `aria-describedby` et annoncée en direct :
 * un lecteur d'écran doit entendre pourquoi la connexion a échoué, pas
 * seulement voir du rouge.
 */
export function Field({
  label,
  error,
  hint,
  revealable,
  type = 'text',
  className,
  ...props
}: FieldProps) {
  const id = useId()
  const [revealed, setRevealed] = useState(false)

  const inputType = revealable && revealed ? 'text' : type
  const describedBy = [error && `${id}-error`, hint && `${id}-hint`]
    .filter(Boolean)
    .join(' ')

  return (
    <div className={clsx('space-y-1.5', className)}>
      <label htmlFor={id} className="block text-sm font-semibold">
        {label}
      </label>

      <div className="relative">
        <input
          {...props}
          id={id}
          type={inputType}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy || undefined}
          className={clsx(
            'w-full rounded-[var(--cd-radius-sm)] border bg-[var(--cd-surface)] px-3 py-2.5 text-[15px]',
            'placeholder:text-[var(--cd-text-muted)]',
            'transition-colors outline-none',
            error
              ? 'border-[var(--cd-danger)] focus:border-[var(--cd-danger)]'
              : 'border-[var(--cd-border-strong)] focus:border-[var(--cd-orange)]',
            revealable && 'pr-11',
          )}
        />

        {revealable && (
          <button
            type="button"
            onClick={() => setRevealed((v) => !v)}
            className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]"
            aria-label={revealed ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
            tabIndex={-1}
          >
            {revealed ? <EyeOff size={17} /> : <Eye size={17} />}
          </button>
        )}
      </div>

      {hint && !error && (
        <p id={`${id}-hint`} className="text-xs text-[var(--cd-text-muted)]">
          {hint}
        </p>
      )}

      {error && (
        <p
          id={`${id}-error`}
          role="alert"
          className="text-xs font-medium text-[var(--cd-danger)]"
        >
          {error}
        </p>
      )}
    </div>
  )
}
