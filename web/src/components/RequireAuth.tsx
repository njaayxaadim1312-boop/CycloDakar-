import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { Logo } from '@/components/Logo'
import { useAuth } from '@/stores/auth'

/**
 * Garde des routes de l'application.
 *
 * Trois états, et il faut les distinguer :
 *
 *  - session pas encore vérifiée → on attend. Rediriger tout de suite vers
 *    /login renverrait un utilisateur pourtant connecté sur l'écran de
 *    connexion à chaque rafraîchissement de page ;
 *  - session absente → redirection, en mémorisant la page demandée ;
 *  - session présente → on affiche.
 *
 * Cette garde ne protège RIEN côté données : elle évite d'afficher une
 * coquille vide. La vraie protection est côté Laravel, à chaque requête.
 */
export function RequireAuth() {
  const location = useLocation()
  const { user, ready, bootstrap } = useAuth()

  useEffect(() => {
    if (!ready) void bootstrap()
  }, [ready, bootstrap])

  if (!ready) {
    return <SplashScreen />
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <Outlet />
}

/**
 * Écran d'attente pendant la vérification de session.
 * Volontairement sobre : il ne dure qu'une requête.
 */
function SplashScreen() {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center gap-4 bg-[var(--cd-bg)]">
      <Logo size={64} />
      <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>
    </div>
  )
}

/**
 * Inverse de `RequireAuth` : empêche un utilisateur déjà connecté de revenir
 * sur l'écran de connexion, ce qui n'aurait aucun sens.
 */
export function RedirectIfAuthenticated() {
  const { user, ready, bootstrap } = useAuth()

  useEffect(() => {
    if (!ready) void bootstrap()
  }, [ready, bootstrap])

  if (!ready) {
    return <SplashScreen />
  }

  return user ? <Navigate to="/" replace /> : <Outlet />
}
