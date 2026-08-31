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
