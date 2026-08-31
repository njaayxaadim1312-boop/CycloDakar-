import { AlertCircle, ArrowLeft, MailCheck, Send } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Field } from '@/components/ui/Field'
import { ApiError, postData } from '@/lib/api'
import type { MessageResult } from '@/types/api'

export function ForgotPasswordPage() {
  const [login, setLogin] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await postData<MessageResult>('/auth/forgot-password', { login })
      setSent(result.message)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : null)
    } finally {
      setSubmitting(false)
    }
  }

  if (sent) {
    return (
      <AuthLayout title="Vérifiez votre boîte mail">
        <div className="space-y-5">
          <div className="flex items-start gap-3 rounded-[var(--cd-radius-sm)] bg-[var(--cd-green-soft)] p-4">
            <MailCheck size={20} className="mt-0.5 shrink-0 text-[var(--cd-green-hover)]" />
            <p className="text-sm text-[var(--cd-text)]">{sent}</p>
          </div>

          <p className="text-sm text-[var(--cd-text-muted)]">
            Le lien est valable une heure. Pensez à regarder dans vos courriers
            indésirables.
          </p>

          <Link to="/login" className="cd-btn cd-btn-ghost w-full">
            <ArrowLeft size={16} />
            Retour à la connexion
          </Link>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Mot de passe oublié"
      subtitle="Nous vous enverrons un lien pour en choisir un nouveau."
      footer={
        <Link to="/login" className="font-semibold text-brand-text hover:underline">
          ← Retour à la connexion
        </Link>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {error && !error.fieldError('login') && (
          <div
            role="alert"
            className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]"
          >
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <span>{error.message}</span>
          </div>
        )}

        <Field
          label="Téléphone ou email"
          name="login"
          autoComplete="username"
          placeholder="77 123 45 67"
          value={login}
          onChange={(e) => setLogin(e.target.value)}
          error={error?.fieldError('login')}
          required
          autoFocus
        />

        <button
          type="submit"
          disabled={submitting}
          className="cd-btn cd-btn-primary w-full"
        >
          <Send size={17} />
          {submitting ? 'Envoi…' : 'Envoyer le lien'}
        </button>

        <p className="text-xs leading-relaxed text-[var(--cd-text-muted)]">
          Le lien part par courriel. Si votre compte n'a pas d'adresse email,
          contactez un responsable du club.
        </p>
      </form>
    </AuthLayout>
  )
}
