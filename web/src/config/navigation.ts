import {
  Activity,
  Bike,
  CalendarDays,
  ClipboardList,
  CreditCard,
  FileBarChart,
  Footprints,
  Gauge,
  LayoutDashboard,
  ListChecks,
  Play,
  Receipt,
  ScrollText,
  Settings,
  ShieldCheck,
  Trophy,
  UserRound,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import type { RoleCode } from '@/types/api'

/**
 * Définition unique du menu de navigation.
 *
 * **Le sport passe devant.** Cyclo Dakar est d'abord une application
 * d'exercice : on l'ouvre pour enregistrer une sortie, voir ses kilomètres et
 * savoir quand part le groupe. Les effectifs, les participations et la caisse
 * sont indispensables au bureau, mais ce ne sont pas la raison d'ouvrir
 * l'application — ils vivent donc derrière **un seul bouton**, « Gestion du
 * club », en bas du menu.
 *
 * Ce n'est pas cosmétique : mettre un solde de caisse en tête d'écran change
 * ce qu'est le club aux yeux de ses membres.
 *
 * `phase` indique la phase du plan qui livre l'écran (voir docs/roadmap.md).
 * `DELIVERED_THROUGH_PHASE` distingue « livré » de « à venir » : sans cette
 * borne, un écran fonctionnel porterait le même repère qu'un écran absent.
 */
export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  phase: number
  /** Rôles autorisés. `undefined` = tous les rôles connectés. */
  roles?: RoleCode[]
  /** Description affichée sur l'écran en attente et sur la page Gestion. */
  summary: string
}

export interface NavSection {
  title: string
  items: NavItem[]
}

const ADMIN: RoleCode[] = ['ADMIN', 'SUPER_ADMIN']
const FINANCE: RoleCode[] = ['TREASURER', 'ADMIN', 'SUPER_ADMIN']
const COLLECT: RoleCode[] = ['COLLECTOR', 'TREASURER', 'ADMIN', 'SUPER_ADMIN']

/** Dernière phase effectivement livrée — à incrémenter à chaque phase finie. */
export const DELIVERED_THROUGH_PHASE = 14

/* -------------------------------------------------------------------------- */
/* Menu principal — l'exercice, et rien d'autre                               */
/* -------------------------------------------------------------------------- */

export const navigation: NavSection[] = [
  {
    title: 'Le tableau de bord et mon sport',
    items: [
      {
        to: '/',
        label: 'Tableau de bord',
        icon: LayoutDashboard,
        phase: 1,
        summary:
          "L'état du club en un écran : effectifs, activité, événements à venir, et — pour qui y a droit — la caisse et le reste à collecter.",
      },
      {
        to: '/activite',
        label: 'Mon activité',
        icon: Gauge,
        phase: 1,
        summary:
          'Vos objectifs de la semaine, vos dernières sorties et la prochaine sortie du club.',
      },
      {
        to: '/record',
        label: 'Démarrer une sortie',
        icon: Play,
        phase: 1,
        summary:
          "Enregistrement GPS depuis le navigateur, avec statistiques en direct : vélo, course, marche, randonnée.",
      },
      {
        to: '/activities',
        label: 'Mes sorties',
        icon: Bike,
        phase: 1,
        summary:
          'Historique des sorties enregistrées au GPS — vélo, course, randonnée, marche — avec carte, distance, durée, vitesse et dénivelé.',
      },
      {
        to: '/stats',
        label: 'Statistiques',
        icon: Activity,
        phase: 8,
        summary:
          'Vos cumuls par période, votre régularité sur douze semaines, la répartition par sport et vos records personnels.',
      },
    ],
  },
  {
    title: 'Le club',
    items: [
      {
        to: '/events',
        label: 'Sorties du club',
        icon: CalendarDays,
        phase: 9,
        summary:
          'Sorties officielles : parcours prévu, inscriptions, liste des participants et présence réelle.',
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
        icon: Footprints,
        phase: 16,
        summary:
          'Défis à objectif avec suivi de progression et badges — « 500 km en septembre », par exemple.',
      },
    ],
  },
  {
    title: 'Mon espace',
    items: [
      {
        to: '/mes-cotisations',
        label: 'Mes cotisations',
        icon: Wallet,
        phase: 12,
        summary:
          "Ce que le club attend de vous, ce que vous avez déjà versé, et vos reçus. La seule page financière ouverte à un membre — et elle ne montre que lui.",
      },
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
]

/* -------------------------------------------------------------------------- */
/* Gestion du club — replié derrière un seul bouton                           */
/* -------------------------------------------------------------------------- */

/**
 * Tout ce qui relève de l'administration et de l'argent.
 *
 * Volontairement hors du menu principal et regroupé sous une seule entrée. Le
 * bureau y accède en un clic ; le membre qui n'a rien à y faire ne le voit pas
 * du tout, puisque chaque écran porte ses rôles.
 */
export const managementSections: NavSection[] = [
  {
    title: 'Adhérents',
    items: [
      {
        to: '/members',
        label: 'Annuaire',
        icon: Users,
        phase: 1,
        summary:
          'Fiches du club : matricule automatique, photo, statut, QR Code personnel et recherche par nom, téléphone ou matricule.',
      },
      {
        to: '/participations',
        label: 'Participations',
        icon: ClipboardList,
        phase: 10,
        roles: COLLECT,
        summary:
          'Campagnes de collecte : montant attendu, date limite, membres concernés et suivi attendu / encaissé / reste.',
      },
    ],
  },
  {
    title: 'Trésorerie',
    items: [
      {
        to: '/payments',
        label: 'Encaissements',
        icon: CreditCard,
        phase: 12,
        roles: COLLECT,
        summary:
          "Les membres qui vous sont confiés, toutes collectes confondues : qui doit combien, son téléphone, et l'encaissement en deux appuis.",
      },
      {
        to: '/finance/collectes',
        label: 'Collectes par collecteur',
        icon: ShieldCheck,
        phase: 12,
        roles: FINANCE,
        summary:
          "Qui a encaissé combien, et combien d'opérations ont été annulées. C'est le contrôle contre le détournement, pas une statistique de confort.",
      },
      {
        to: '/finance',
        label: 'Caisse',
        icon: Wallet,
        phase: 13,
        roles: FINANCE,
        summary:
          "Trois nombres qui ne se mélangent pas : ce que le club a, ce qu'il a engagé, et ce qu'il attend encore. Les additionner ferait décider sur de l'argent qui n'est pas arrivé.",
      },
      {
        to: '/finance/expenses',
        label: 'Dépenses',
        icon: Receipt,
        phase: 13,
        roles: FINANCE,
        summary:
          "Saisie avec justificatif et poste. Une dépense en attente n'a encore rien sorti de la caisse ; au-delà du seuil, un second responsable doit approuver.",
      },
      {
        to: '/finance/transactions',
        label: 'Journal de caisse',
        icon: ListChecks,
        phase: 13,
        roles: FINANCE,
        summary:
          "Le grand livre, écriture par écriture — la pièce qu'on imprime en assemblée. Source de vérité du solde, qui est dérivé et jamais écrit.",
      },
      {
        to: '/finance/reports',
        label: 'Rapports',
        icon: FileBarChart,
        phase: 14,
        roles: FINANCE,
        summary:
          "Jour, semaine, mois, année ou période libre. Le PDF se signe et se distribue, l'Excel se retravaille, le CSV s'importe ailleurs — l'écran montre exactement ce que le fichier contiendra.",
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
          'Réglages du club : seuil de validation des dépenses, visibilité du solde, catégories, rôles.',
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
          "Diagnostic en direct de la liaison entre le navigateur, l'API Laravel, la base MySQL et le stockage.",
      },
    ],
  },
]

/**
 * L'entrée unique qui mène à tout ce qui précède.
 *
 * Réservée aux collecteurs et au-dessus. Un membre ordinaire n'a rien à gérer :
 * lui montrer une porte « Gestion du club » qui n'ouvre que sur l'annuaire
 * mettrait en avant exactement ce que cette réorganisation cherche à ranger.
 * L'annuaire lui reste accessible — il n'est simplement plus mis en avant.
 */
export const managementEntry: NavItem = {
  to: '/gestion',
  label: 'Gestion du club',
  icon: Settings,
  phase: 1,
  roles: COLLECT,
  summary:
    'Adhérents, participations, trésorerie et administration — tout ce qui fait tourner le club en coulisses.',
}

/* -------------------------------------------------------------------------- */

/** L'écran correspondant à cet élément est-il déjà utilisable ? */
export function isDelivered(item: NavItem): boolean {
  return item.phase <= DELIVERED_THROUGH_PHASE
}

/** Tous les éléments, à plat — pratique pour retrouver la page courante. */
export const allNavItems: NavItem[] = [
  ...navigation.flatMap((section) => section.items),
  managementEntry,
  ...managementSections.flatMap((section) => section.items),
]

/**
 * Retrouve l'élément de menu correspondant à un chemin.
 *
 * On prend la correspondance la PLUS LONGUE pour que `/finance/expenses` ne
 * soit pas capté par `/finance`. La racine est traitée à part : `/` préfixe
 * tout, et sans cette exception elle capterait chaque page.
 */
export function findNavItem(pathname: string): NavItem | undefined {
  if (pathname === '/') {
    return allNavItems.find((item) => item.to === '/')
  }

  return allNavItems
    .filter((item) => item.to !== '/')
    .filter((item) => pathname === item.to || pathname.startsWith(`${item.to}/`))
    .sort((a, b) => b.to.length - a.to.length)[0]
}
