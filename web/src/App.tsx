import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { allNavItems } from '@/config/navigation'
import { DashboardPage } from '@/pages/DashboardPage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'
import { SystemStatusPage } from '@/pages/SystemStatusPage'

/**
 * Routage de l'application web.
 *
 * Les routes sont dérivées du menu (`config/navigation.ts`) : ajouter une
 * entrée au menu crée automatiquement sa route. Les écrans réellement
 * implémentés sont déclarés avant, et prennent le pas sur l'écran d'attente.
 *
 * Écrans livrés à ce jour :
 *   /dashboard  — tableau de bord (phase 1, enrichi en phase 4)
 *   /system     — diagnostic de la plateforme (phase 1)
 *
 * Les autres afficheront leur module dès la phase indiquée dans le menu.
 */

/** Routes déjà implémentées — elles ne doivent pas tomber sur PlaceholderPage. */
const IMPLEMENTED = new Set(['/dashboard', '/system'])

export default function App() {
  return (
    <Routes>
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
    </Routes>
  )
}
