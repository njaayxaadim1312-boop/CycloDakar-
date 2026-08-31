# Architecture — Cyclo Dakar

> Version 1.0 — document vivant. Toute décision structurante est consignée ici.

## 1. Vue d'ensemble

```text
                          CYCLO DAKAR
                               │
        ┌──────────────────────┴──────────────────────┐
        │                                             │
        ▼                                             ▼
  WEB (React 19 + TS + Vite)              MOBILE (React Native / Expo)
  Navigateur desktop/tablette/mobile      Android + iOS, GPS terrain
        │                                             │
        │            même contrat d'API               │
        └──────────────────────┬──────────────────────┘
                               │  HTTPS / JSON  (Bearer token Sanctum)
                               ▼
                    ┌──────────────────────┐
                    │   LARAVEL 12  (API)  │  ← source de vérité
                    │   /api/v1/*          │
                    └──────────┬───────────┘
                               │
              ┌────────────────┼─────────────────┐
              │                │                 │
              ▼                ▼                 ▼
          MySQL 8 /        Queue (jobs)      Storage
          MariaDB 10.4     database driver   local / S3
              ▲                │
              │                │ dispatch VideoRenderJob
              │                ▼
              │      ┌──────────────────────┐
              └──────┤  NODE.JS (services)  │
       callback HMAC │  WebSocket + FFmpeg  │
                     └──────────────────────┘
```

**Règle d'or :** Laravel est le **backend principal et l'unique source de vérité**.
Node.js n'est appelé que pour ce que PHP fait mal : rendu vidéo (FFmpeg), temps réel
(WebSocket), traitement de fichiers lourds. Node.js **n'écrit jamais** directement en base ;
il rend compte à Laravel par un webhook signé HMAC.

## 2. Répartition des responsabilités

| Domaine | Laravel | Node.js | Client (Web/Mobile) |
|---|---|---|---|
| Authentification, rôles | Sanctum + RBAC | — | consomme |
| Membres, matricule, QR | oui | — | consomme |
| Activités, points GPS | persistance + recalcul | — | capture + affichage temps réel |
| Statistiques GPS | **recalculées serveur** | — | affichage provisoire seulement |
| Événements, participations | oui | — | consomme |
| Paiements, caisse, audit | **exclusivement** | — | consomme |
| Rendu vidéo | orchestre (job + statut) | FFmpeg | déclenche + télécharge |
| Notifications temps réel | émet l'événement | WebSocket fan-out | s'abonne |

## 3. Décisions d'architecture (ADR condensés)

### ADR-001 — MySQL/MariaDB plutôt que PostgreSQL + PostGIS

Le cahier des charges v1.1 suggérait PostGIS ; le prompt maître impose MySQL/XAMPP.
**Décision : MySQL.** Conséquence : pas de type `geography` natif. Les points GPS sont stockés
en `DECIMAL(10,7)` / `DECIMAL(11,7)` (précision ~1 cm, exacte, sans dérive flottante) et les
distances sont calculées en PHP (Haversine) plutôt qu'en SQL. Les requêtes spatiales lourdes
(segments comparatifs, heatmap) sont **reportées** ; l'architecture reste migrable vers PostGIS
car toute la géométrie est encapsulée dans `App\Services\Gps\*`.

### ADR-002 — Points GPS : table brute + polyline encodée

Une sortie de 3 h à 1 Hz produit ~10 800 points. 250 membres × 100 sorties/an ≈ 270 M lignes.
**Décision : double stockage.**

- `activity_points` : vérité brute, indexée par activité, purgeable/archivable après N mois.
- `activities.polyline` : trace **simplifiée** (Douglas-Peucker) puis encodée (Google Encoded
  Polyline Algorithm) → ~1 Ko au lieu de ~500 Ko. C'est **elle** qui est servie aux listes,
  miniatures et cartes. Le brut n'est lu que pour l'export GPX et le rendu vidéo.

### ADR-003 — Le solde de caisse n'est jamais une valeur mutable

`cash_accounts.current_balance` n'existe que comme **cache**. La vérité est la somme signée de
`financial_transactions`. Une commande `finance:recompute-balance` recalcule et compare.
Aucune route n'accepte un solde en entrée. Voir [finance.md](finance.md).

### ADR-004 — Cartographie : OpenStreetMap par défaut, fournisseur pluggable

Coût zéro, aucune clé API pour démarrer. Mapbox (le fond satellite du prototype) est activable
par variable d'environnement. L'accès aux tuiles passe par une abstraction unique
(`MapProvider` côté web, `MapView` configuré côté mobile) pour ne jamais figer le fournisseur.

### ADR-005 — Sanctum (tokens API) plutôt que JWT

Sanctum en mode **token** (pas cookie) : le même flux fonctionne pour le web et le mobile,
avec révocation immédiate côté serveur (table `personal_access_tokens`) et sans liste noire à
maintenir. Un JWT non révocable serait dangereux pour un rôle `TREASURER`.

### ADR-006 — Mono-dépôt sans outil de monorepo

`backend/`, `web/`, `mobile/`, `services/` ont chacun leurs dépendances. Pas de Nx/Turborepo :
les stacks sont hétérogènes (PHP + Node) et le gain serait nul. Le partage se fait par le
**contrat OpenAPI**, pas par du code partagé.

### ADR-007 — PHP 8.3 CLI dédié

XAMPP fournit PHP 8.1 (incompatible Laravel 12). PHP 8.3.33 NTS est installé dans `C:\php83`
et placé en tête du `PATH` utilisateur. Apache/XAMPP reste inchangé et continue de servir les
autres projets avec son propre PHP 8.1. Voir [installation.md](installation.md).

## 3 bis. Écart assumé avec le cahier des charges v1.1

Le document `Cyclo-Dakar-App-Cahier-des-charges.md` proposait Flutter + PostgreSQL/PostGIS +
NestJS. Le prompt maître (plus récent) impose React Native + MySQL + Laravel.
**Le prompt maître fait foi.** Les fonctionnalités propres au document v1.1 (fil d'actualité,
badges, segments comparatifs, export GPX/FIT, capteurs Bluetooth, multi-langue) ne sont pas
perdues : elles figurent dans la roadmap comme évolutions post-MVP.

## 4. Arborescence

```text
CycloDakar/
├── assets/brand/            Logo, affiches, prototype (source de l'identité visuelle)
├── backend/                 Laravel 12 — API principale, MySQL, jobs, RBAC
├── web/                     React 19 + TypeScript + Vite + Tailwind
├── mobile/                  React Native (Expo Development Build) — GPS, offline, QR
├── services/                Node.js — WebSocket temps réel + rendu vidéo FFmpeg
├── database/                Schéma SQL de référence, dumps, jeux de démo
├── docs/                    Documentation technique
│   └── cahier-des-charges/  Documents sources du club
└── README.md
```

## 5. Flux critiques

### 5.1 Enregistrement d'une activité (tolérant aux coupures réseau)

```text
Mobile : start → tâche de localisation en arrière-plan → filtre qualité → SQLite local
       → lot de 100 points → si réseau : POST /activities/{uuid}/points  (idempotent)
       → sinon : file d'attente locale, retry exponentiel
Fin    : POST /activities/{uuid}/finalize
       → Laravel RECALCULE toutes les statistiques depuis les points reçus
         (le client n'est jamais cru sur parole)
       → simplification + polyline + reverse-geocoding groupé des zones traversées
```

### 5.2 Encaissement d'une participation

```text
Collecteur : scan QR (ou recherche) → GET /members/resolve/{token}
           → POST /participations/{id}/payments  {member_id, amount, method}

Laravel, dans une seule transaction SQL :
   1. vérifie la policy (COLLECTOR assigné, ou TREASURER / ADMIN)
   2. vérifie amount ≤ reste dû            (sinon 422)
   3. crée la ligne payments
   4. crée financial_transactions (+amount, direction=IN, source=payment)
   5. met à jour participation_members.status (NON_PAYE / PARTIEL / PAYE)
   6. invalide le cache de solde
   7. écrit audit_logs
```

### 5.3 Génération vidéo

```text
POST /activities/{id}/video  → video_jobs(status=QUEUED) → 202 + job_id
Job Laravel → HTTP POST vers Node /render (charge utile signée HMAC)
Node → rendu des frames (carte + trace + overlay) → FFmpeg → MP4
Node → POST /api/v1/internal/video-jobs/{id}/complete (HMAC) → status=DONE + url
     → notification « Votre vidéo est prête »
```

## 6. Sécurité transversale

- Toutes les routes `/api/v1/*` sont derrière `auth:sanctum` sauf login / register / reset.
- RBAC par Policy Laravel, jamais par `if ($user->role === ...)` dispersé dans les contrôleurs.
- Rate limiting : 5 req/min sur `login`, 60/min sur l'API générale, 240/min sur l'ingestion GPS.
- Uploads : type MIME vérifié côté serveur, taille max 10 Mo, stockage **hors** `public/` pour
  les justificatifs financiers (accès par route signée à durée limitée).
- `audit_logs` sur toute opération financière et administrative.

Détail complet : [security.md](security.md).
