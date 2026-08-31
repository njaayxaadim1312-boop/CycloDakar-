import { Navigate, Route, Routes } from 'react-router-dom'
import { RedirectIfAuthenticated, RequireAuth } from '@/components/RequireAuth'
import { AppLayout } from '@/components/layout/AppLayout'
import { allNavItems } from '@/config/navigation'
import { DashboardPage } from '@/pages/DashboardPage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'
import { SystemStatusPage } from '@/pages/SystemStatusPage'
import { ForgotPasswordPage } from '@/pages/auth/ForgotPasswordPage'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'
import { ResetPasswordPage } from '@/pages/auth/ResetPasswordPage'

/**
 * Routage de l'application web.
 *
 * Les routes de l'application sont dérivées du menu
 * (`config/navigation.ts`) : ajouter une entrée au menu crée automatiquement
 * sa route. Les écrans réellement implémentés sont déclarés avant, et
 * prennent le pas sur l'écran d'attente.
 *
 * Écrans livrés à ce jour :
 *   PHASE 1  /dashboard, /system
 *   PHASE 2  /login, /register, /forgot-password, /reset-password
 */

/** Routes déjà implémentées — elles ne doivent pas tomber sur PlaceholderPage. */
const IMPLEMENTED = new Set(['/dashboard', '/system'])

export default function App() {
  return (
    <Routes>
      {/* --- Écrans publics d'authentification -------------------------- */}
      <Route element={<RedirectIfAuthenticated />}>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      </Route>

      {/*
        La réinitialisation reste accessible même connecté : on peut arriver
        sur ce lien depuis un courriel alors qu'une session traîne dans le
        navigateur.
      */}
      <Route path="/reset-password" element={<ResetPasswordPage />} />

      {/* --- Application, réservée aux comptes connectés ----------------- */}
      <Route element={<RequireAuth />}>
        <Route element={<AppLayout />}>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/system" element={<SystemStatusPage />} />

          {allNavItems
            .filter((item) => !IMPLEMENTED.has(item.to))
            .map((item) => (
              <Route key={item.to} path={item.to} element={<PlaceholderPage />} />
            ))}

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Route>
      </Route>
    </Routes>
  )
}
