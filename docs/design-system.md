# Identité visuelle Cyclo Dakar

Source : `assets/brand/prototype-design-system.jpg` (planche officielle), le logo
`assets/brand/logo-cyclo-dakar.jpg` et les affiches du club.

## 1. Palette

Les quatre couleurs proviennent directement de la planche du prototype.

| Rôle | Hex | Usage |
|---|---|---|
| **Orange Cyclo** | `#FF8C00` | Couleur primaire. Boutons d'action, liens, accents, marqueur GPS, sélection active. |
| **Noir Asphalte** | `#1A1A1A` | Texte principal, bouton d'arrêt, fond du mode sombre, barres de statistiques. |
| **Bleu Océan** | `#004080` | Secondaire. Actions calmes (« Prendre photo »), en-têtes de tableaux, informations. |
| **Vert Trace** | `#32CD32` | Succès, trace GPS active, « Point d'intérêt », statut *Payé*, progression de challenge. |

Couleurs dérivées (générées, non issues de la planche mais nécessaires à une UI complète) :

| Rôle | Clair | Sombre |
|---|---|---|
| Fond page | `#F7F7F8` | `#111113` |
| Fond carte/surface | `#FFFFFF` | `#1C1C1F` |
| Bordure | `#E4E4E7` | `#2E2E33` |
| Texte secondaire | `#6B7280` | `#9CA3AF` |
| Danger | `#DC2626` | `#F87171` |
| Avertissement | `#D97706` | `#FBBF24` |

> **Accessibilité.** L'orange `#FF8C00` sur blanc a un contraste de 2,3:1 — **insuffisant pour
> du texte**. Règle du projet : l'orange sert de **fond** (avec texte noir `#1A1A1A`, contraste
> 7,9:1) ou de **grand accent** (icône, barre, bordure). Pour un lien orange sur fond clair on
> utilise la variante foncée `#C46A00` (4,6:1). Cette règle est encodée dans les tokens
> `--cd-orange` / `--cd-orange-text`.

## 2. Typographie

La planche définit trois niveaux : `H1 TITRE` (très gras, condensé), `P Corps de texte`,
`Lien Orange`.

- **Titres** : *Archivo* (ou *Inter Tight*) en `700/800`, `letter-spacing: -0.02em`.
  Rendu proche du lettrage des affiches du club.
- **Corps** : *Inter*, `400/500`.
- **Chiffres de statistiques** : *Inter* avec `font-variant-numeric: tabular-nums`
  — indispensable pour que « 46.8 KM » ne saute pas pendant l'enregistrement GPS.
- Repli système : `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.

Échelle : `display 40/44` · `h1 30/36` · `h2 24/30` · `h3 20/26` · `body 15/22` ·
`small 13/18` · `caption 12/16`.

## 3. Formes et élévation

- Rayon : `12px` (cartes), `999px` (boutons pilule, comme sur la planche), `8px` (champs).
- Le bouton principal d'enregistrement est un **grand bouton pilule noir plein largeur** —
  utilisable avec des gants, lisible en plein soleil (contrainte du cahier des charges).
- Ombres discrètes uniquement : `0 1px 2px rgba(0,0,0,.06), 0 4px 12px rgba(0,0,0,.04)`.
- Boutons flottants d'action ronds orange, positionnés en bas à droite (planche « Communauté »).

## 4. États de bouton (repris de la planche)

| État | Rendu |
|---|---|
| Default | fond `#1A1A1A`, texte blanc |
| Primary | fond `#FF8C00`, texte `#1A1A1A` |
| Secondary | fond `#004080`, texte blanc |
| Pressed | assombri de 10 %, `scale(0.98)` |
| Disabled | fond `#D4D4D8`, texte `#8A8A8F`, `cursor: not-allowed` |

## 5. Iconographie

La planche liste : GPS (épingle), Bike, Runner, Vidéo (caméra), Social (groupe).
Bibliothèque retenue : **lucide-react** (web) / **lucide-react-native** (mobile) — trait de
2 px, cohérent avec le style linéaire de la planche. Correspondances :

`MapPin` (GPS) · `Bike` (cyclisme) · `Footprints` (course) · `Mountain` (randonnée) ·
`Video` (vidéo) · `Users` (communauté) · `Wallet` (caisse) · `QrCode` (scan).

## 6. Ton et langue

Interface **en français**, tutoiement évité, vocabulaire du club : « sortie », « membre »,
« collecte », « caisse ». Devise affichée `12 500 FCFA` (espace insécable fine comme
séparateur de milliers, jamais de décimales).

## 7. Implémentation

Les tokens sont définis une seule fois par plateforme et **jamais** redéfinis en dur :

- Web : `web/src/styles/tokens.css` (variables CSS) + `@theme` Tailwind v4.
- Mobile : `mobile/src/theme/tokens.ts`.

Toute nouvelle couleur doit d'abord être ajoutée aux tokens.

## Affiches du club

Deux affiches vivent dans `assets/brand/`, et une seule sert dans l'interface.

`affiche-ensemble-pedalons.jpg` (copiée en `web/public/brand/hero.jpg`) occupe le
panneau droit des écrans d'authentification. Format portrait, un sujet unique,
lisible réduite.

Elle y est montrée **entière**, posée sur le fond orange comme une affiche au
mur, et jamais recadrée : plein cadre, `object-cover` coupait l'en-tête
« CYCLO DAKAR », le médaillon et le bandeau des valeurs — une bonne part de ce
que l'affiche a à dire. C'est la règle pour toute affiche du club : on la montre
en entier ou on ne la montre pas.

**Aucune reprise de la devise ne se superpose à elle** — l'affiche porte déjà
« Ensemble, pédalons plus loin ! », et le répéter par-dessus ferait doublon.

`affiche-grand-tour-2025.jpg` n'est pas utilisée comme décor : elle est dense en
informations (horaires, étapes, consignes de sécurité) et deviendrait illisible
réduite à un fond. Sa place est dans le module Événements, à taille réelle et
téléchargeable — PHASE 9.

La source fait 853 × 1280 px. Affichée entière, elle n'est jamais agrandie
au-delà de sa taille propre sur un écran de hauteur courante — la question du
piqué, qui se posait avec le recadrage plein cadre, ne se pose plus.

## Hiérarchie : le sport devant

Cyclo Dakar est d'abord une application d'**exercice**. Ce principe gouverne la
navigation, pas seulement les couleurs.

- L'écran d'accueil est **mon activité de la semaine** : trois anneaux, ma
  régularité, mes dernières sorties. Pas un effectif, pas un solde.
- Les adhérents, les participations, la trésorerie et l'administration sont
  regroupés derrière **une seule entrée**, « Gestion du club », réservée aux
  collecteurs et au-dessus. Un membre ordinaire ne la voit pas.
- Sur mobile, l'accueil ne comporte plus **aucun** élément financier ni
  d'effectif. Ces outils vivent sur le web.

Ce n'est pas un rangement esthétique : mettre un solde de caisse en tête
d'écran change ce que le club a l'air d'être aux yeux de ses membres.

## Anneaux d'activité

Trois anneaux concentriques, à la manière de l'application Forme : distance,
temps en mouvement, sorties. Règles de dessin, identiques sur le web et le
mobile :

1. **L'anneau ne dépasse jamais un tour.** Le serveur renvoie bien 150 %, mais
   un arc qui repasserait sur lui-même deviendrait illisible.
2. **Un objectif atteint change d'aspect**, pas seulement de longueur — sinon
   98 % et 102 % se ressemblent, alors que l'un est un échec et l'autre une
   réussite.
3. **L'animation part de zéro** : elle montre le remplissage, elle ne décore
   pas.
4. **Un `aria-label` détaillé** porte les trois chiffres et leurs objectifs :
   un lecteur d'écran ne « voit » pas trois arcs.

## Mouvement

Trois durées, deux courbes, dans `tokens.css`. Au-delà, chaque écran invente la
sienne et l'application perd son rythme.

| Jeton | Valeur | Usage |
|---|---|---|
| `--cd-duration-fast` | 150 ms | survol, changement d'état |
| `--cd-duration` | 260 ms | entrée d'un bloc, transition de page |
| `--cd-duration-slow` | 900 ms | remplissage des anneaux |
| `--cd-ease-out` | `cubic-bezier(.16,1,.3,1)` | ce qui apparaît |
| `--cd-ease-spring` | `cubic-bezier(.34,1.56,.64,1)` | ce qui répond à un geste |

Classes utilitaires : `.cd-rise`, `.cd-fade`, `.cd-pop`, `.cd-stagger`
(cascade **plafonnée au huitième élément**, sans quoi le dernier apparaîtrait
une seconde après le premier), `.cd-lift` (survol, jamais sur écran tactile).

**`prefers-reduced-motion` neutralise tout** — sans masquer : une animation
`both` laissée à son état initial cacherait la moitié de la page. Ce n'est pas
une politesse ; pour une partie des utilisateurs, une animation non sollicitée
provoque un vrai malaise physique.

## Surfaces de verre

Fond translucide **et** flou (`.cd-glass`, `.cd-glass-strong`,
`.cd-glass-dark`), posés sur l'affiche du club. Les deux vont ensemble : sans
le flou, le texte tombe sur les détails de la photo et devient illisible dès
que l'image change. En mode sombre, le verre **assombrit** au lieu
d'éclaircir — un panneau blanc translucide sur une photo éblouirait exactement
celui qui a choisi le mode sombre.

## Sports

Quatre sports : cyclisme, course, **marche**, randonnée. La marche reprend le
bleu de la course en plus clair — ce sont deux façons du même geste, et deux
familles de couleur sans rapport rendraient la répartition par sport illisible.

Icône, couleur et libellé vivent dans **un seul fichier** par plateforme
(`web/src/lib/sports.ts`, `mobile/src/lib/sports.ts`), typés
`Record<SportCode, …>` : TypeScript refuse de compiler tant qu'un sport manque,
au lieu d'afficher un trou à l'exécution.

## Écrans d'authentification : fixes

Connexion, inscription, mot de passe oublié et réinitialisation **tiennent d'un
coup**, sans défilement. C'est le premier écran que voit un membre : un bouton
qu'il faut aller chercher plus bas y donne l'impression que quelque chose
manque, ou que la page n'a pas fini de charger.

Trois moyens, dans cet ordre :

1. **La carte bordée** (`--cd-border-strong`, `--cd-shadow-lg`) délimite la
   saisie au lieu de la laisser flotter, et impose de penser sa hauteur.
2. **Les champs compacts** (`<Field compact>`) resserrent les espacements —
   jamais la taille du texte. Le champ reste à 15 px : en dessous, Safari zoome
   automatiquement au premier appui et l'écran « fixe » se met à sauter.
3. **Les blancs cèdent sur fenêtre courte** (`[@media(max-height:700px)]`) :
   petit téléphone, portable en basse résolution. La respiration se sacrifie
   avant le contenu, et le contenu avant les cibles tactiles.

Deux pièges rencontrés, à ne pas réintroduire :

- **`items-center` sur un conteneur qui défile coupe le haut**, et cette partie
  devient inatteignable — le défilement ne remonte jamais au-dessus de zéro. Sur
  petit téléphone, le logo et le titre disparaissaient sans recours. Utiliser
  `items-start` + `my-auto`, qui centre tant qu'il y a de la place.
- **Sans `min-h-0` sur la colonne de l'affiche**, un élément de grille refuse de
  descendre sous la taille de son contenu : l'image impose alors sa hauteur à
  toute la page et le défilement revient.

Mesuré au navigateur réel (Chrome DevTools Protocol), pas en jsdom, qui ne
calcule aucune mise en page : 1366×768, 390×844 et 360×640, les quatre écrans
entièrement visibles.

Un garde-fou subsiste — `overflow-y-auto` sur la colonne du formulaire. En
paysage, ou avec un texte agrandi par accessibilité, la hauteur peut manquer
malgré tout : mieux vaut un léger défilement qu'un bouton hors d'atteinte.
