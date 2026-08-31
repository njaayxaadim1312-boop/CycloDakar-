import {
  Activity,
  BarChart3,
  Bike,
  CalendarDays,
  ClipboardList,
  CreditCard,
  FileBarChart,
  LayoutDashboard,
  ListChecks,
  Receipt,
  ScrollText,
  Settings,
  ShieldCheck,
  UserRound,
  Trophy,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import type { RoleCode } from '@/types/api'

/**
 * Définition unique du menu de navigation.
 *
 * Centraliser ici plutôt que dans le JSX de la barre latérale permet de dériver
 * aussi le fil d'Ariane, le titre de page et le filtrage par rôle sans les
 * réécrire trois fois.
 *
 * `phase` indique la phase du plan de développement qui livre l'écran. Un écran
 * dont la phase n'est pas encore atteinte reste visible mais annoncé comme tel :
 * le club voit où va le produit plutôt que de découvrir des liens morts.
 */
export interface NavItem {
  /** Chemin de la route. */
  to: string
  label: string
  icon: LucideIcon
  /** Phase du plan qui livre cet écran (voir docs/roadmap.md). */
  phase: number
  /** Rôles autorisés. `undefined` = tous les rôles connectés. */
  roles?: RoleCode[]
  /** Description affichée sur l'écran en attente. */
  summary: string
}

export interface NavSection {
  title: string
  items: NavItem[]
}

const ADMIN: RoleCode[] = ['ADMIN', 'SUPER_ADMIN']
const FINANCE: RoleCode[] = ['TREASURER', 'ADMIN', 'SUPER_ADMIN']
const COLLECT: RoleCode[] = ['COLLECTOR', 'TREASURER', 'ADMIN', 'SUPER_ADMIN']

export const navigation: NavSection[] = [
  {
    title: 'Pilotage',
    items: [
      {
        to: '/dashboard',
        label: 'Tableau de bord',
        // Première version livrée en phase 1 ; enrichie de graphiques et de
        // données réelles au fur et à mesure que les modules arrivent.
        icon: LayoutDashboard,
        phase: 1,
        summary:
          'Vue d\'ensemble du club : membres, activités, distance parcourue, événements et situation de la caisse.',
      },
    ],
  },
  {
    title: 'Sport',
    items: [
      {
        to: '/activities',
        label: 'Activités',
        icon: Bike,
        phase: 1,
        summary:
          'Historique des sorties enregistrées au GPS, avec carte, distance, durée, vitesse et dénivelé. Filtrable par sport et par période.',
      },
      {
        to: '/stats',
        label: 'Mes statistiques',
        icon: BarChart3,
        phase: 8,
        summary:
          'Vos cumuls par période, votre régularité sur douze semaines, la répartition par sport et vos records personnels.',
      },
      {
        to: '/events',
        label: 'Événements',
        icon: CalendarDays,
        phase: 9,
        summary:
          'Sorties officielles du club : parcours prévu, inscriptions, liste des participants et suivi de la présence réelle.',
      },
      {
        to: '/leaderboard',
        label: 'Classements',
        icon: Trophy,
        phase: 16,
        summary:
          'Classements hebdomadaires, mensuels et annuels par distance, nombre de sorties, temps et par sport.',
      },
      {
        to: '/challenges',
        label: 'Challenges',
        icon: Activity,
        phase: 16,
        summary:
          'Défis à objectif avec suivi de progression et badges — « 500 km en septembre », par exemple.',
      },
    ],
  },
  {
    title: 'Club',
    items: [
      {
        to: '/members',
        label: 'Membres',
        icon: Users,
        phase: 1,
        summary:
          'Annuaire du club : matricule automatique, photo, statut, QR Code personnel et recherche par nom, téléphone ou matricule.',
      },
      {
        to: '/participations',
        label: 'Participations',
        icon: ClipboardList,
        phase: 10,
        roles: COLLECT,
        summary:
          'Campagnes de collecte : montant attendu, date limite, membres concernés et suivi attendu / encaissé / reste à collecter.',
      },
      {
        to: '/payments',
        label: 'Encaissements',
        icon: CreditCard,
        phase: 12,
        roles: COLLECT,
        summary:
          'Enregistrement des paiements par recherche du membre ou par scan de son QR Code. Espèces, Wave, Orange Money, Free Money, virement.',
      },
    ],
  },
  {
    title: 'Finances',
    items: [
      {
        to: '/finance',
        label: 'Caisse',
        icon: Wallet,
        phase: 13,
        roles: FINANCE,
        summary:
          'Solde en temps réel, total des recettes et des dépenses, montants restant à collecter et évolution de la caisse.',
      },
      {
        to: '/finance/expenses',
        label: 'Dépenses',
        icon: Receipt,
        phase: 13,
        roles: FINANCE,
        summary:
          'Saisie des dépenses avec justificatif, catégorie et circuit de validation à seuil configurable.',
      },
      {
        to: '/finance/transactions',
        label: 'Journal de caisse',
        icon: ListChecks,
        phase: 13,
        roles: FINANCE,
        summary:
          'Grand livre chronologique : date, opération, entrée, sortie, solde après opération et auteur. Source de vérité du solde.',
      },
      {
        to: '/finance/reports',
        label: 'Rapports',
        icon: FileBarChart,
        phase: 14,
        roles: FINANCE,
        summary:
          'Rapports par jour, semaine, mois, année ou période libre, avec ventilation par catégorie et export PDF, Excel ou CSV.',
      },
    ],
  },
  {
    title: 'Mon espace',
    items: [
      {
        to: '/profile',
        label: 'Mon compte',
        icon: UserRound,
        phase: 1,
        summary:
          "Votre fiche club, votre mot de passe, votre QR Code et vos préférences d'affichage.",
      },
    ],
  },
  {
    title: 'Administration',
    items: [
      {
        to: '/settings',
        label: 'Paramètres',
        icon: Settings,
        phase: 19,
        roles: ADMIN,
        summary:
          'Réglages du club : seuil de validation des dépenses, visibilité du solde, catégories, rôles et permissions.',
      },
      {
        to: '/audit-logs',
        label: "Journal d'audit",
        icon: ScrollText,
        phase: 19,
        roles: ADMIN,
        summary:
          'Traçabilité complète : qui a fait quoi, quand, sur quelle entité, avec les valeurs avant et après.',
      },
      {
        to: '/system',
        label: 'État du système',
        icon: ShieldCheck,
        phase: 1,
        summary:
          'Diagnostic en direct de la liaison entre le navigateur, l\'API Laravel, la base MySQL et le stockage.',
      },
    ],
  },
]

/**
 * Dernière phase effectivement livrée.
 *
 * C'est elle qui distingue « écran livré en phase 8 » de « écran à venir en
 * phase 16 ». Sans cette borne, la pastille du menu afficherait le même repère
 * dans les deux cas et un écran fonctionnel semblerait indisponible.
 *
 * À incrémenter à chaque phase terminée — voir `docs/roadmap.md`.
 */
export const DELIVERED_THROUGH_PHASE = 8

/** L'écran correspondant à cet élément est-il déjà utilisable ? */
export function isDelivered(item: NavItem): boolean {
  return item.phase <= DELIVERED_THROUGH_PHASE
}

/** Tous les éléments, à plat — pratique pour retrouver la page courante. */
export const allNavItems: NavItem[] = navigation.flatMap((section) => section.items)

/**
 * Retrouve l'élément de menu correspondant à un chemin.
 * On prend la correspondance la PLUS LONGUE pour que `/finance/expenses`
 * ne soit pas capté par `/finance`.
 */
export function findNavItem(pathname: string): NavItem | undefined {
  return allNavItems
    .filter((item) => pathname === item.to || pathname.startsWith(`${item.to}/`))
    .sort((a, b) => b.to.length - a.to.length)[0]
}
