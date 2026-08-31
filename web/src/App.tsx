import { Navigate, Route, Routes } from 'react-router-dom'
import { SystemStatusPage } from '@/pages/SystemStatusPage'

/**
 * Routage de l'application web.
 *
 * Les routes des phases suivantes (voir docs/api.md §47 du cahier des charges)
 * sont ajoutées au fur et à mesure :
 *
 *   PHASE 2  /login
 *   PHASE 4  /dashboard
 *   PHASE 3  /members, /members/:id
 *   PHASE 8  /activities, /activities/:id
 *   PHASE 9  /events, /events/:id
 *   PHASE 10 /participations, /participations/:id
 *   PHASE 12 /payments
 *   PHASE 13 /finance, /finance/income, /finance/expenses, /finance/transactions
 *   PHASE 14 /finance/reports
 *   PHASE 16 /challenges, /leaderboard
 *   PHASE 17 /notifications
 *   PHASE 19 /settings, /audit-logs
 */
export default function App() {
  return (
    <Routes>
      {/* Tant que le tableau de bord n'existe pas (phase 4), la racine mène à
          l'écran de diagnostic, qui restera ensuite accessible sous /system. */}
      <Route path="/" element={<Navigate to="/system" replace />} />
      <Route path="/system" element={<SystemStatusPage />} />
      <Route path="*" element={<Navigate to="/system" replace />} />
    </Routes>
  )
}
