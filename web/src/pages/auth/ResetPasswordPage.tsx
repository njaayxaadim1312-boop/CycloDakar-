import { AlertCircle, CheckCircle2, KeyRound } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Field } from '@/components/ui/Field'
import { ApiError, postData } from '@/lib/api'
import type { MessageResult } from '@/types/api'

/**
 * Choix d'un nouveau mot de passe depuis le lien reçu par courriel.
 *
 * Le jeton et l'identifiant arrivent en paramètres d'URL — c'est le courriel
 * envoyé par `ResetPasswordNotification` qui les fournit. Les deux sont
 * nécessaires : le jeton seul ne suffit pas, il est lié à un compte.
 */
export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const login = params.get('login') ?? ''

  const [form, setForm] = useState({ password: '', password_confirmation: '' })
  const [done, setDone] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await postData<MessageResult>('/auth/reset-password', { token, login, ...form })
      setDone(true)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : null)
    } finally {
      setSubmitting(false)
    }
  }

  if (!token || !login) {
    return (
      <AuthLayout title="Lien invalide">
        <div className="space-y-5">
          <div className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]">
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <span>
              Ce lien de réinitialisation est incomplet. Demandez-en un nouveau.
            </span>
          </div>
          <Link to="/forgot-password" className="cd-btn cd-btn-primary w-full">
            Demander un nouveau lien
          </Link>
        </div>
      </AuthLayout>
    )
  }

  if (done) {
    return (
      <AuthLayout title="Mot de passe modifié">
        <div className="space-y-5">
          <div className="flex items-start gap-3 rounded-[var(--cd-radius-sm)] bg-[var(--cd-green-soft)] p-4">
            <CheckCircle2 size={20} className="mt-0.5 shrink-0 text-[var(--cd-green-hover)]" />
            <p className="text-sm">
              Votre mot de passe a été changé. Par sécurité, toutes vos sessions ont
              été fermées.
            </p>
          </div>
          <Link to="/login" className="cd-btn cd-btn-primary w-full">
            Se connecter
          </Link>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Nouveau mot de passe"
      subtitle={`Compte : ${login}`}
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {error && !error.fieldError('password') && (
          <div
            role="alert"
            className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]"
          >
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <span>{error.message}</span>
          </div>
        )}

        <Field
          label="Nouveau mot de passe"
          name="password"
          type="password"
          autoComplete="new-password"
          revealable
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={error?.fieldError('password')}
          hint="8 caractères minimum, avec au moins une lettre et un chiffre."
          required
          autoFocus
        />

        <Field
          label="Confirmer le mot de passe"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          revealable
          value={form.password_confirmation}
          onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
          required
        />

        <button
          type="submit"
          disabled={submitting}
          className="cd-btn cd-btn-primary w-full"
        >
          <KeyRound size={17} />
          {submitting ? 'Enregistrement…' : 'Changer mon mot de passe'}
        </button>
      </form>
    </AuthLayout>
  )
}
