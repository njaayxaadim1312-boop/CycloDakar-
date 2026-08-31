import { AlertCircle, UserPlus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'

export function RegisterPage() {
  const navigate = useNavigate()
  const register = useAuth((state) => state.register)

  const [form, setForm] = useState({
    name: '',
    phone: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await register({
        name: form.name,
        // Les champs vides sont envoyés à null : une chaîne vide serait
        // stockée telle quelle et casserait la contrainte d'unicité au
        // deuxième membre sans email.
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        password: form.password,
        password_confirmation: form.password_confirmation,
      })
      navigate('/dashboard', { replace: true })
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : null)
    } finally {
      setSubmitting(false)
    }
  }

  const hasFieldErrors = Boolean(
    error?.fieldError('name') ??
      error?.fieldError('phone') ??
      error?.fieldError('email') ??
      error?.fieldError('password'),
  )

  return (
    <AuthLayout
      title="Créer un compte"
      subtitle="Rejoignez la plateforme du club Cyclo Dakar."
      footer={
        <>
          Vous avez déjà un compte ?{' '}
          <Link to="/login" className="font-semibold text-brand-text hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {error && !hasFieldErrors && (
          <div
            role="alert"
            className="flex items-start gap-2.5 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]"
          >
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <span>{error.message}</span>
          </div>
        )}

        <Field
          label="Nom complet"
          name="name"
          autoComplete="name"
          placeholder="Awa Ndiaye"
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
          error={error?.fieldError('name')}
          required
          autoFocus
        />

        <Field
          label="Téléphone"
          name="phone"
          type="tel"
          autoComplete="tel"
          inputMode="tel"
          placeholder="77 123 45 67"
          value={form.phone}
          onChange={(e) => setForm({ ...form, phone: e.target.value })}
          error={error?.fieldError('phone')}
        />

        <Field
          label="Adresse email (facultative)"
          name="email"
          type="email"
          autoComplete="email"
          placeholder="awa@example.sn"
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
          error={error?.fieldError('email')}
          hint="Nécessaire pour réinitialiser vous-même votre mot de passe."
        />

        <Field
          label="Mot de passe"
          name="password"
          type="password"
          autoComplete="new-password"
          revealable
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={error?.fieldError('password')}
          hint="8 caractères minimum, avec au moins une lettre et un chiffre."
          required
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
          <UserPlus size={17} />
          {submitting ? 'Création…' : 'Créer mon compte'}
        </button>

        <p className="text-xs leading-relaxed text-[var(--cd-text-muted)]">
          Indiquez au moins un téléphone ou une adresse email : c'est votre
          identifiant de connexion.
        </p>
      </form>
    </AuthLayout>
  )
}
