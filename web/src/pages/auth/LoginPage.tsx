import { AlertCircle, LogIn } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Field } from '@/components/ui/Field'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const login = useAuth((state) => state.login)

  const [form, setForm] = useState({ login: '', password: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Après une connexion réussie, on revient à la page que l'utilisateur
  // voulait atteindre avant d'être renvoyé ici.
  const from = (location.state as { from?: string } | null)?.from ?? '/dashboard'

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await login(form)
      navigate(from, { replace: true })
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : null)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Connexion"
      subtitle="Accédez à votre espace Cyclo Dakar."
      footer={
        <>
          Pas encore de compte ?{' '}
          <Link to="/register" className="font-semibold text-brand-text hover:underline">
            Créer un compte
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {/*
          Erreur générale : identifiants incorrects, compte désactivé, trop de
          tentatives, serveur injoignable. Les erreurs de champ, elles, sont
          affichées sous le champ concerné.
        */}
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
          inputMode="text"
          placeholder="77 123 45 67"
          value={form.login}
          onChange={(e) => setForm({ ...form, login: e.target.value })}
          error={error?.fieldError('login')}
          hint="Vous pouvez saisir votre numéro dans n'importe quel format."
          required
          autoFocus
        />

        <Field
          label="Mot de passe"
          name="password"
          type="password"
          autoComplete="current-password"
          revealable
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={error?.fieldError('password')}
          required
        />

        <div className="flex justify-end">
          <Link
            to="/forgot-password"
            className="text-sm font-medium text-brand-text hover:underline"
          >
            Mot de passe oublié ?
          </Link>
        </div>

        <button
          type="submit"
          disabled={submitting}
          className="cd-btn cd-btn-primary w-full"
        >
          <LogIn size={17} />
          {submitting ? 'Connexion…' : 'Se connecter'}
        </button>
      </form>
    </AuthLayout>
  )
}
