import { Navigate, Route, Routes } from 'react-router-dom'
import { RedirectIfAuthenticated, RequireAuth } from '@/components/RequireAuth'
import { AppLayout } from '@/components/layout/AppLayout'
import { allNavItems } from '@/config/navigation'
import { ActivityHomePage } from '@/pages/ActivityHomePage'
import { DashboardPage } from '@/pages/DashboardPage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'
import { ManagementPage } from '@/pages/ManagementPage'
import { ParticipationDetailPage } from '@/pages/participations/ParticipationDetailPage'
import { ParticipationFormPage } from '@/pages/participations/ParticipationFormPage'
import { ParticipationsPage } from '@/pages/participations/ParticipationsPage'
import { CashPage } from '@/pages/finance/CashPage'
import { ExpensesPage } from '@/pages/finance/ExpensesPage'
import { LedgerPage } from '@/pages/finance/LedgerPage'
import { ReportsPage } from '@/pages/finance/ReportsPage'
import { CollectionsPage } from '@/pages/payments/CollectionsPage'
import { CollectPage } from '@/pages/payments/CollectPage'
import { MyDuesPage } from '@/pages/payments/MyDuesPage'
import { ProfilePage } from '@/pages/ProfilePage'
import { RecordPage } from '@/pages/record/RecordPage'
import { SystemStatusPage } from '@/pages/SystemStatusPage'
import { ActivitiesPage } from '@/pages/activities/ActivitiesPage'
import { ActivityDetailPage } from '@/pages/activities/ActivityDetailPage'
import { ActivityMoviePage } from '@/pages/activities/ActivityMoviePage'
import { EventDetailPage } from '@/pages/events/EventDetailPage'
import { EventFormPage } from '@/pages/events/EventFormPage'
import { EventsPage } from '@/pages/events/EventsPage'
import { MemberDetailPage } from '@/pages/members/MemberDetailPage'
import { MemberFormPage } from '@/pages/members/MemberFormPage'
import { MembersPage } from '@/pages/members/MembersPage'
import { PersonalStatsPage } from '@/pages/stats/PersonalStatsPage'
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
 * LA RACINE EST LE TABLEAU DE BORD DU CLUB, à la demande du bureau.
 *
 * C'est un revirement par rapport à l'organisation précédente, où la racine
 * portait l'écran d'activité — anneaux de la semaine, dernières sorties — et
 * où le tableau de bord vivait derrière « Gestion du club ». Ce choix-là
 * partait d'une idée juste : ce qu'on voit en ouvrant une application dit ce
 * qu'elle est. Le club a tranché autrement, et c'est sa décision.
 *
 * L'écran d'activité n'est pas perdu pour autant : il vit désormais sous
 * `/activite`, en tête du menu, et reste ce qu'un membre vient chercher pour
 * enregistrer une sortie.
 *
 * Écrans livrés à ce jour :
 *   PHASE 1  / (activite), /system
 *   PHASE 2  /login, /register, /forgot-password, /reset-password
 *   PHASE 3  /members, /members/nouveau, /members/:uuid, /members/:uuid/modifier
 *   PHASE 4  /profile
 *   PHASE 7  /activities, /activities/:uuid
 *   PHASE 8  /stats
 *   PHASE 9  /events, /events/nouveau, /events/:uuid, /events/:uuid/modifier
 *   PHASE 9bis  /activite, /gestion
 *   PHASE 13bis / (tableau de bord)
 *   PHASE 10 /participations, /participations/nouvelle, /participations/:uuid
 */

/** Routes déjà implémentées — elles ne doivent pas tomber sur PlaceholderPage. */
const IMPLEMENTED = new Set([
  '/',
  '/activite',
  '/gestion',
  '/record',
  '/participations',
  '/system',
  '/members',
  '/profile',
  '/activities',
  '/stats',
  '/events',

  // PHASE 12
  '/payments',
  '/mes-cotisations',
  '/finance/collectes',

  // PHASE 13
  '/finance',
  '/finance/expenses',
  '/finance/transactions',

  // PHASE 14
  '/finance/reports',
])

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
          {/* La racine : le tableau de bord du club, à la demande du bureau.
              C'est aussi là qu'atterrit une connexion réussie. */}
          <Route path="/" element={<DashboardPage />} />

          {/* L'écran d'exercice : anneaux de la semaine, dernières sorties,
              prochaine sortie du club. */}
          <Route path="/activite" element={<ActivityHomePage />} />

          {/* Tout ce qui touche à l'argent et à l'administration passe par
              une seule porte. */}
          <Route path="/gestion" element={<ManagementPage />} />

          {/* Anciens chemins du tableau de bord. Les liens déjà partagés et
              les favoris du bureau doivent continuer de fonctionner : une
              réorganisation de menu ne doit jamais casser un signet. */}
          <Route path="/gestion/tableau-de-bord" element={<Navigate to="/" replace />} />
          <Route path="/dashboard" element={<Navigate to="/" replace />} />

          <Route path="/system" element={<SystemStatusPage />} />
          <Route path="/profile" element={<ProfilePage />} />

          {/* Membres — phase 3. « nouveau » est déclaré AVANT « :uuid »
              pour ne pas être pris pour un identifiant. */}
          <Route path="/members" element={<MembersPage />} />
          <Route path="/members/nouveau" element={<MemberFormPage />} />
          <Route path="/members/:uuid" element={<MemberDetailPage />} />
          <Route path="/members/:uuid/modifier" element={<MemberFormPage />} />

          {/* Activités — phase 7 */}
          <Route path="/activities" element={<ActivitiesPage />} />
          <Route path="/activities/:uuid" element={<ActivityDetailPage />} />
          {/* Le parcours rejoue en video — phase 15. */}
          <Route path="/activities/:uuid/video" element={<ActivityMoviePage />} />

          {/* Enregistrement d'une sortie depuis le navigateur. */}
          <Route path="/record" element={<RecordPage />} />

          {/* Participations — phase 10. « nouvelle » avant « :uuid ». */}
          {/* Encaissements — PHASE 12. `/payments` est l'ecran de TERRAIN du
              collecteur ; `/mes-cotisations` est la vue du membre sur son
              propre compte, et la seule page financiere qui lui soit
              ouverte. */}
          <Route path="/payments" element={<CollectPage />} />
          <Route path="/mes-cotisations" element={<MyDuesPage />} />
          <Route path="/finance/collectes" element={<CollectionsPage />} />

          {/* Caisse — PHASE 13. `/finance` est le tableau de bord ; le journal
              en est le detail ecriture par ecriture. */}
          <Route path="/finance" element={<CashPage />} />
          <Route path="/finance/expenses" element={<ExpensesPage />} />
          <Route path="/finance/transactions" element={<LedgerPage />} />

          {/* Rapports — PHASE 14. L'ecran montre exactement ce que l'export
              contiendra : un rapport telecharge sans avoir pu etre regarde se
              redecouvre a l'ouverture, la veille d'une assemblee. */}
          <Route path="/finance/reports" element={<ReportsPage />} />

          <Route path="/participations" element={<ParticipationsPage />} />
          <Route path="/participations/nouvelle" element={<ParticipationFormPage />} />
          <Route path="/participations/:uuid" element={<ParticipationDetailPage />} />
          <Route path="/participations/:uuid/modifier" element={<ParticipationFormPage />} />

          {/* Statistiques personnelles — phase 8 */}
          <Route path="/stats" element={<PersonalStatsPage />} />

          {/* Événements — phase 9. « nouveau » est déclaré AVANT « :uuid »
              pour ne pas être pris pour un identifiant. */}
          <Route path="/events" element={<EventsPage />} />
          <Route path="/events/nouveau" element={<EventFormPage />} />
          <Route path="/events/:uuid" element={<EventDetailPage />} />
          <Route path="/events/:uuid/modifier" element={<EventFormPage />} />

          {allNavItems
            .filter((item) => !IMPLEMENTED.has(item.to))
            .map((item) => (
              <Route key={item.to} path={item.to} element={<PlaceholderPage />} />
            ))}

          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Route>
    </Routes>
  )
}
