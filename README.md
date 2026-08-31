<div align="center">

# 🚴 CYCLO DAKAR

**Plateforme sportive et de gestion du club Cyclo Dakar**

Sorties GPS · Événements · Participations · Caisse · Communauté

*Ensemble, plus loin, plus forts !*

</div>

---

## Ce qu'est ce projet

Une plateforme unique pour remplacer l'assemblage actuel (Strava, Relive, formulaires,
cahier de caisse) par un outil propre au club :

| Sport | Club | Finances |
|---|---|---|
| Enregistrement GPS des sorties | Membres et matricules | Participations aux sorties |
| Vélo, course, randonnée | QR Code membre | Encaissement par recherche ou scan |
| Carte, statistiques, records | Événements et présence | Recettes et dépenses |
| Historique et classements | Challenges | Caisse, journal, rapports |
| Vidéo animée du parcours | Notifications | Audit et traçabilité |

Le **web** (navigateur, responsive) et le **mobile** (Android/iOS) consomment la même API.

---

## Architecture

```text
  WEB  React + TypeScript          MOBILE  React Native / Expo
        (Vite, Tailwind)                 (GPS, hors ligne, QR)
                └──────────────┬──────────────┘
                               │  API REST JSON
                        LARAVEL 13  ← source de vérité
                               │
                 ┌─────────────┴─────────────┐
              MySQL / MariaDB          NODE.JS
              (XAMPP en local)     WebSocket + vidéo
```

Détail et décisions d'architecture : **[docs/architecture.md](docs/architecture.md)**

---

## Prérequis (Windows)

| Outil | Version | Note |
|---|---|---|
| **XAMPP** | Apache + MySQL/MariaDB | Seul **MySQL** est nécessaire ; Apache est facultatif |
| **PHP** | **8.3 ou plus** | ⚠️ Le PHP de XAMPP (8.1) ne suffit pas — voir ci-dessous |
| **Composer** | 2.x | |
| **Node.js** | 20 ou plus | |
| **Git** | | |
| Android Studio *ou* l'app **Expo Go** | | pour tester le mobile |

> ### ⚠️ À propos de PHP
> Laravel 13 exige **PHP ≥ 8.3**, alors que XAMPP est livré avec PHP 8.1.
> Sur ce poste, **PHP 8.3.33 est déjà installé dans `C:\php83`** et placé en tête du
> `PATH` utilisateur. XAMPP et Apache ne sont pas modifiés et continuent de servir
> vos autres projets avec leur propre PHP.
>
> Vérification : `php -v` doit afficher `8.3.x`.
> Réinstallation ou retour en arrière : [docs/installation.md](docs/installation.md).

---

## Installation

```powershell
git clone <url-du-depot> CycloDakar
cd CycloDakar
```

### 1. Démarrer MySQL

Ouvrez le **XAMPP Control Panel** et cliquez sur **Start** en face de **MySQL**.
(Apache n'est pas nécessaire : le backend tourne avec `php artisan serve`.)

Créez la base — via phpMyAdmin, ou en ligne de commande :

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS cyclo_dakar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Backend (Laravel)

```powershell
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed    # cree le compte administrateur
php artisan cyclo:doctor      # diagnostic complet de l'environnement
php artisan serve             # http://localhost:8000
```

### 3. Web (React)

```powershell
cd web
npm install
copy .env.example .env
npm run dev                   # http://localhost:5173
```

### 4. Service Node (temps réel + vidéo)

```powershell
cd services
npm install
copy .env.example .env
```

Puis recopiez dans `services/.env` la valeur de `NODE_SERVICE_SECRET`
qui se trouve dans `backend/.env` :

```env
SERVICE_SECRET=<la même valeur que NODE_SERVICE_SECRET>
```

```powershell
npm run dev                   # http://localhost:4000
```

### 5. Mobile (Expo)

```powershell
cd mobile
npm install
copy .env.example .env
npx expo start
```

Scannez le QR Code avec **Expo Go**, ou appuyez sur `a` pour l'émulateur Android.

> Pour tester depuis un **téléphone réel**, le backend doit écouter sur le réseau
> et non seulement sur localhost :
> ```powershell
> php artisan serve --host=0.0.0.0 --port=8000
> ```
> L'application détecte automatiquement l'adresse IP du PC (voir `mobile/src/lib/api.ts`).
> Autorisez le port 8000 dans le pare-feu Windows au premier lancement.

---

## Lancer le projet au quotidien

Quatre terminaux, dans cet ordre :

| # | Dossier | Commande | Adresse |
|---|---|---|---|
| 0 | — | XAMPP → **Start MySQL** | port 3306 |
| 1 | `backend/` | `php artisan serve` | http://localhost:8000 |
| 2 | `web/` | `npm run dev` | http://localhost:5173 |
| 3 | `services/` | `npm run dev` | http://localhost:4000 |
| 4 | `mobile/` | `npx expo start` | Expo Go / émulateur |

---

## Vérifier que tout fonctionne

```powershell
# Diagnostic complet (PHP, extensions, base, migrations, stockage, service Node)
cd backend
php artisan cyclo:doctor

# État de l'API
curl http://localhost:8000/api/v1/health

# Configuration métier partagée web/mobile
curl http://localhost:8000/api/v1/config

# Tests
cd backend && php artisan test     # 128 tests (sur MySQL)
cd mobile  && npm test             # 19 tests d'écrans
```

Puis ouvrez **http://localhost:5173**.

### Comptes de démonstration

Créés par `php artisan migrate --seed` (mot de passe : `CycloDakar2026!`) :

| Rôle | Email | Téléphone |
|---|---|---|
| Super administrateur | `admin@cyclodakar.sn` | 77 000 00 00 |
| Collecteur | `collecteur@cyclodakar.sn` | 77 000 00 01 |
| Trésorier | `tresorier@cyclodakar.sn` | 77 000 00 02 |
| Membre | `membre@cyclodakar.sn` | 77 000 00 03 |

Le seeder crée aussi **6 membres sans compte de connexion** (les adhérents sans
smartphone) : matricule, QR Code et place dans l'effectif, mais pas de connexion.

La connexion accepte **l'email ou le téléphone**, dans n'importe quel format.
Changez le mot de passe de l'administrateur dès la première connexion.

En développement, `MAIL_MAILER=log` : les courriels de réinitialisation ne partent
pas, ils sont écrits dans `backend/storage/logs/laravel.log`.

---

## Structure

```text
CycloDakar/
├── assets/brand/     Logo, affiches, planche du prototype (identité visuelle)
├── backend/          Laravel 13 — API, MySQL, rôles, finances
├── web/              React 19 + TypeScript + Vite + Tailwind 4
├── mobile/           React Native / Expo — GPS, hors ligne, QR Code
├── services/         Node.js — WebSocket + rendu vidéo FFmpeg
├── database/         Schéma de référence, jeux de démonstration
└── docs/             Documentation technique
```

---

## Documentation

| Document | Contenu |
|---|---|
| [architecture.md](docs/architecture.md) | Vue d'ensemble, décisions d'architecture (ADR) |
| [installation.md](docs/installation.md) | Installation détaillée sous Windows, dépannage |
| [database.md](docs/database.md) | Schéma complet, relations, volumétrie |
| [api.md](docs/api.md) | Contrat d'API, conventions, routes par phase |
| [gps.md](docs/gps.md) | Algorithme GPS : filtrage, distance, dénivelé, synchro |
| [finance.md](docs/finance.md) | Règles d'intégrité financière (contrat) |
| [design-system.md](docs/design-system.md) | Couleurs, typographie, composants |
| [risques.md](docs/risques.md) | Risques GPS, hors ligne et financiers, et leurs parades |
| [roadmap.md](docs/roadmap.md) | Les 20 phases, état d'avancement |
| [mobile.md](docs/mobile.md) | Spécificités React Native, permissions, batterie |
| [video.md](docs/video.md) | Génération de la vidéo animée du parcours |
| [security.md](docs/security.md) | Authentification, permissions, uploads, RGPD |
| [deployment.md](docs/deployment.md) | Mise en production |
| [cahier-des-charges/](docs/cahier-des-charges/) | Documents sources du club |

---

## Avancement

| Phase | Objet | État |
|---|---|---|
| 1 | Initialisation, structure, environnement | ✅ **Terminée** |
| 2 | Authentification | ✅ **Terminée** |
| 3 | Membres, matricules et rôles | ✅ **Terminée** |
| 4 | Interface web | ✅ **Terminée** |
| 5 | Interface mobile | ✅ **Terminée** |
| 6–8 | GPS, carte, statistiques, historique | ⏳ |
| 9–12 | Événements, participations, paiements | ⏳ |
| 13–14 | Caisse et rapports financiers | ⏳ |
| 15–20 | Vidéo, challenges, notifications, tests, sécurité, déploiement | ⏳ |

Détail : [docs/roadmap.md](docs/roadmap.md)

---

<div align="center">
<sub>Cyclo Dakar · Passion · Dépassement · Solidarité</sub>
</div>
