# Schéma de base de données — Cyclo Dakar

SGBD : **MySQL 8 / MariaDB 10.4+**, moteur InnoDB, charset `utf8mb4_unicode_ci`.
Toutes les tables sont créées par des **migrations Laravel** (`backend/database/migrations`).
La base n'est jamais modifiée à la main.

## Conventions

| Règle | Valeur |
|---|---|
| Clés primaires | `BIGINT UNSIGNED AUTO_INCREMENT` |
| Identifiant public exposé | colonne `uuid CHAR(36)` sur les entités adressables par le client |
| Montants | `BIGINT` en **francs CFA entiers** — jamais de flottant (le XOF n'a pas de centimes) |
| Coordonnées | `DECIMAL(10,7)` latitude, `DECIMAL(11,7)` longitude |
| Dates | `TIMESTAMP` UTC en base, converties en `Africa/Dakar` à l'affichage |
| Suppression | `deleted_at` (soft delete) sur les entités métier ; **jamais** sur les tables financières |
| Énumérations | colonnes `VARCHAR` + validation applicative (évite les `ALTER TABLE` sur `ENUM`) |

---

## 1. Identité et membres

### `users`
Compte de connexion. Un `user` ↔ un `member` (1-1).

| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| uuid | CHAR(36) UNIQUE | |
| name | VARCHAR(120) | affichage |
| email | VARCHAR(180) UNIQUE NULL | login possible par email |
| phone | VARCHAR(20) UNIQUE NULL | login possible par téléphone (usage local) |
| password | VARCHAR(255) | bcrypt |
| role | VARCHAR(20) | `MEMBER` \| `COLLECTOR` \| `TREASURER` \| `ADMIN` \| `SUPER_ADMIN` |
| is_active | BOOLEAN | désactivation sans suppression |
| last_login_at | TIMESTAMP NULL | |
| email_verified_at, remember_token, timestamps, deleted_at | | |

Index : `email`, `phone`, `role`.

> **Note RBAC.** Le rôle principal vit sur `users.role` (rapide, suffisant pour 5 rôles).
> Les tables `roles` / `permissions` / `role_user` sont prévues pour les permissions fines
> (ex. : un collecteur autorisé sur une seule participation) et alimentées à partir de la
> phase 3. Le champ `users.role` reste la source de vérité du rôle global.

### `members`
Fiche club.

| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| user_id | BIGINT FK → users UNIQUE | |
| matricule | VARCHAR(12) UNIQUE | `CD-000001`, généré en transaction verrouillée |
| first_name, last_name | VARCHAR(80) | |
| phone | VARCHAR(20) | |
| email | VARCHAR(180) NULL | |
| photo_path | VARCHAR(255) NULL | |
| birth_date | DATE NULL | |
| gender | VARCHAR(10) NULL | |
| joined_at | DATE | date d'adhésion |
| status | VARCHAR(20) | `ACTIVE` \| `SUSPENDED` \| `FORMER` \| `PENDING` |
| qr_token | CHAR(43) UNIQUE | **jeton opaque aléatoire**, aucune donnée personnelle |
| qr_rotated_at | TIMESTAMP NULL | permet de révoquer un QR compromis |
| emergency_contact | VARCHAR(120) NULL | |
| notes | TEXT NULL | |
| timestamps, deleted_at | | |

Index : `matricule`, `qr_token`, `status`, `last_name`, index composite recherche
`(last_name, first_name)`.

### `roles`, `permissions`, `permission_role`, `role_user`
Structure RBAC fine. Créées dès la phase 1, exploitées à partir de la phase 3.

---

## 2. Sport et GPS

### `activities`
Une sortie enregistrée.

| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| uuid | CHAR(36) UNIQUE | **généré par le client**, sert de clé d'idempotence de la synchro |
| member_id | BIGINT FK → members | |
| event_id | BIGINT FK → events NULL | si la sortie relève d'un événement du club |
| sport | VARCHAR(20) | `CYCLING` \| `RUNNING` \| `HIKING` (extensible) |
| title | VARCHAR(140) NULL | |
| status | VARCHAR(20) | `RECORDING` \| `PAUSED` \| `FINALIZING` \| `COMPLETED` \| `DISCARDED` |
| visibility | VARCHAR(15) | `PRIVATE` \| `CLUB` \| `PUBLIC` |
| started_at, ended_at | TIMESTAMP | |
| distance_m | INT UNSIGNED | **mètres**, entier — calculé serveur |
| duration_s | INT UNSIGNED | durée totale |
| moving_time_s | INT UNSIGNED | temps actif |
| paused_time_s | INT UNSIGNED | `duration_s - moving_time_s` |
| avg_speed_mps, max_speed_mps | DECIMAL(6,3) | m/s (une seule unité en base) |
| elevation_gain_m, elevation_loss_m | INT | dénivelé |
| min_altitude_m, max_altitude_m | INT NULL | |
| avg_pace_s_per_km, best_pace_s_per_km | INT NULL | course / randonnée |
| calories_kcal | INT NULL | estimation |
| polyline | MEDIUMTEXT NULL | trace simplifiée encodée (ADR-002) |
| bounds | JSON NULL | `{minLat,minLng,maxLat,maxLng}` pour cadrer la carte |
| start_lat/lng, end_lat/lng | DECIMAL | départ / arrivée |
| zones | JSON NULL | `["Dakar","Ouakam","Ngor"]` |
| points_count | INT UNSIGNED | après filtrage |
| raw_points_count | INT UNSIGNED | avant filtrage — mesure la qualité du GPS |
| device_info | JSON NULL | modèle, OS, version app |
| synced_at | TIMESTAMP NULL | |
| timestamps, deleted_at | | |

Index : `member_id`, `(member_id, started_at)`, `sport`, `event_id`, `status`, `uuid`.

> **Unités.** Une seule unité en base (mètres, secondes, m/s). Toute conversion (km, km/h,
> min/km) est faite à l'affichage. Cela supprime une classe entière de bugs.

### `activity_points`
Trace GPS brute. La table la plus volumineuse du système.

| Colonne | Type |
|---|---|
| id | BIGINT PK |
| activity_id | BIGINT FK → activities (ON DELETE CASCADE) |
| seq | INT UNSIGNED — position dans la trace |
| lat | DECIMAL(10,7) |
| lng | DECIMAL(11,7) |
| altitude_m | DECIMAL(7,2) NULL |
| speed_mps | DECIMAL(6,3) NULL — fourni par le GPS |
| accuracy_m | DECIMAL(6,2) NULL |
| heading_deg | DECIMAL(5,2) NULL |
| recorded_at | TIMESTAMP(3) — milliseconde |
| is_paused | BOOLEAN — point capturé pendant une pause |

Contrainte : `UNIQUE (activity_id, seq)` → **rejeu d'un lot déjà reçu = aucun doublon**.
Index : `(activity_id, recorded_at)`.
Pas de `timestamps` (économie de 2 colonnes × des centaines de millions de lignes).

### `activity_photos`

| Colonne | Type |
|---|---|
| id, uuid | |
| activity_id | FK |
| path | VARCHAR(255) |
| thumb_path | VARCHAR(255) NULL |
| lat, lng | DECIMAL NULL |
| taken_at | TIMESTAMP |
| caption | VARCHAR(255) NULL |
| distance_offset_m | INT NULL — position sur la trace, pour la vidéo |

### `activity_stats`
Séries agrégées lourdes (splits kilométriques, profil d'altitude, histogramme de vitesse)
stockées en JSON, pour ne pas recalculer à chaque affichage.

| Colonne | Type |
|---|---|
| activity_id | FK UNIQUE |
| splits | JSON — un objet par kilomètre |
| elevation_profile | JSON — série réduite à ~200 points |
| speed_histogram | JSON |
| computed_at | TIMESTAMP |

---

## 3. Événements et présence

### `events`

| Colonne | Type | Notes |
|---|---|---|
| id, uuid | | |
| title | VARCHAR(160) | |
| description | TEXT NULL | |
| sport | VARCHAR(20) | |
| starts_at, ends_at | TIMESTAMP | |
| location_name | VARCHAR(160) | ex. « Place de la Nation » |
| start_lat/lng | DECIMAL NULL | |
| planned_distance_m | INT NULL | |
| route_polyline | MEDIUMTEXT NULL | parcours officiel |
| difficulty | VARCHAR(20) NULL | `EASY` \| `MEDIUM` \| `HARD` |
| cover_path | VARCHAR(255) NULL | affiche de la sortie |
| status | VARCHAR(20) | `DRAFT` \| `PUBLISHED` \| `ONGOING` \| `DONE` \| `CANCELLED` |
| max_participants | INT NULL | |
| created_by | FK → users | |
| timestamps, deleted_at | | |

### `event_participants`

| Colonne | Type |
|---|---|
| event_id, member_id | FK — `UNIQUE(event_id, member_id)` |
| registration_status | `REGISTERED` \| `CANCELLED` \| `WAITLIST` |
| attendance_status | `UNKNOWN` \| `PRESENT` \| `ABSENT` |
| checked_in_at | TIMESTAMP NULL |
| checked_in_by | FK → users NULL |
| activity_id | FK NULL — l'activité GPS réellement enregistrée |

---

## 4. Participations et paiements

### `participations`
Une campagne de collecte (« Sortie Lac Rose — 5 000 FCFA »).

| Colonne | Type | Notes |
|---|---|---|
| id, uuid | | |
| event_id | FK NULL | |
| name | VARCHAR(160) | |
| description | TEXT NULL | |
| expected_amount | BIGINT | montant unitaire attendu, en FCFA |
| starts_on, due_on | DATE | |
| status | VARCHAR(20) | `DRAFT` \| `OPEN` \| `CLOSED` \| `CANCELLED` |
| created_by | FK → users | |
| timestamps, deleted_at | | |

### `participation_members`
Le membre × la participation. **Une ligne = une dette.**

| Colonne | Type | Notes |
|---|---|---|
| participation_id, member_id | FK — `UNIQUE(participation_id, member_id)` |
| expected_amount | BIGINT | copie figée (un montant peut être individualisé) |
| paid_amount | BIGINT DEFAULT 0 | **dérivé** de la somme des `payments` non annulés |
| status | VARCHAR(20) | `NON_PAYE` \| `PARTIELLEMENT_PAYE` \| `PAYE` \| `ANNULE` |
| assigned_collector_id | FK → users NULL | collecteur responsable |
| last_payment_at | TIMESTAMP NULL | |

Index : `(participation_id, status)`, `member_id`.

### `payments`
Un encaissement. **Table append-only** : jamais de `UPDATE` du montant, jamais de `DELETE`.

| Colonne | Type | Notes |
|---|---|---|
| id, uuid | | |
| participation_member_id | FK | |
| member_id | FK | dénormalisé pour les recherches |
| amount | BIGINT | > 0 |
| method | VARCHAR(20) | `CASH` \| `WAVE` \| `ORANGE_MONEY` \| `FREE_MONEY` \| `TRANSFER` \| `OTHER` |
| reference | VARCHAR(80) NULL | n° de transaction Wave/OM |
| proof_path | VARCHAR(255) NULL | capture d'écran de preuve |
| collected_by | FK → users | **jamais saisi par le client**, pris de la session |
| collected_at | TIMESTAMP | |
| status | VARCHAR(20) | `CONFIRMED` \| `REVERSED` |
| reversed_by, reversed_at, reversal_reason | | annulation = nouvelle écriture, pas suppression |
| idempotency_key | VARCHAR(64) UNIQUE NULL | anti-double-clic / anti-rejeu mobile |
| timestamps | | |

---

## 5. Finances

### `cash_accounts`

| Colonne | Type | Notes |
|---|---|---|
| id, uuid | | |
| name | VARCHAR(100) | « Caisse principale » |
| opening_balance | BIGINT | solde initial |
| opened_at | DATE | |
| current_balance | BIGINT | **cache uniquement** — recalculable (ADR-003) |
| balance_computed_at | TIMESTAMP NULL | |
| is_default | BOOLEAN | |

### `financial_transactions`
**Le grand livre. Source de vérité unique du solde.**

| Colonne | Type | Notes |
|---|---|---|
| id, uuid | | |
| cash_account_id | FK | |
| direction | VARCHAR(3) | `IN` \| `OUT` |
| amount | BIGINT | toujours **positif** ; le signe vient de `direction` |
| balance_after | BIGINT | solde après opération — pour le journal de caisse |
| category_id | FK → income_categories ou expense_categories | |
| category_type | VARCHAR(10) | `INCOME` \| `EXPENSE` (polymorphisme explicite) |
| source_type, source_id | VARCHAR(40), BIGINT NULL | `payment` / `expense` / `manual` / `reversal` |
| label | VARCHAR(180) | |
| occurred_on | DATE | date métier (≠ date de saisie) |
| event_id | FK NULL | rattachement à un événement |
| created_by | FK → users | |
| reverses_transaction_id | FK self NULL | écriture de contre-passation |
| timestamps | | **pas de `deleted_at` : suppression interdite** |

Index : `(cash_account_id, occurred_on, id)`, `(source_type, source_id)`, `category_id`.

### `income_categories` / `expense_categories`

| Colonne | Type |
|---|---|
| id, code (UNIQUE), label, is_active, sort_order |

Recettes semées : `PARTICIPATION`, `COTISATION`, `DON`, `SPONSORING`, `VENTE`,
`CONTRIBUTION`, `AUTRE`.
Dépenses semées : `TRANSPORT`, `CARBURANT`, `NOURRITURE`, `EAU`, `SANTE`, `SECURITE`,
`COMMUNICATION`, `MATERIEL`, `EQUIPEMENT`, `AUTRE`.

### `expenses`

| Colonne | Type | Notes |
|---|---|---|
| id, uuid | | |
| cash_account_id, expense_category_id, event_id (NULL) | FK | |
| amount | BIGINT | |
| description | VARCHAR(255) | |
| spent_on | DATE | |
| status | VARCHAR(12) | `PENDING` \| `APPROVED` \| `REJECTED` \| `CANCELLED` |
| requested_by, approved_by | FK → users | |
| approved_at, rejection_reason | | |
| financial_transaction_id | FK NULL | **rempli seulement à l'approbation** |
| timestamps | | |

> Une dépense `PENDING` ne crée **aucune** ligne dans `financial_transactions` : elle
> n'affecte donc jamais le solde. Le seuil de validation est configurable
> (`settings.expense_approval_threshold`).

### `expense_attachments`

| Colonne | Type |
|---|---|
| expense_id | FK |
| path, original_name, mime_type, size_bytes | |
| uploaded_by | FK → users |

---

## 6. Communauté

### `challenges`

| Colonne | Type |
|---|---|
| id, uuid, title, description | |
| metric | `DISTANCE` \| `ACTIVITIES` \| `DURATION` \| `ELEVATION` |
| sport | VARCHAR(20) NULL — `NULL` = tous sports |
| target_value | BIGINT — mètres / nombre / secondes |
| starts_on, ends_on | DATE |
| status | `DRAFT` \| `ACTIVE` \| `FINISHED` |
| badge_path | VARCHAR(255) NULL |

### `challenge_participants`

| Colonne | Type |
|---|---|
| challenge_id, member_id | FK — UNIQUE ensemble |
| current_value | BIGINT |
| completed_at | TIMESTAMP NULL |
| rank | INT NULL |

### `achievements`
Badges obtenus : `member_id`, `code`, `label`, `earned_at`, `meta JSON`.

### `leaderboard_snapshots`
Classements figés par période (évite de rebalayer `activities` à chaque affichage) :
`period_type` (`WEEK`/`MONTH`/`YEAR`), `period_key` (`2026-W35`), `metric`, `sport`,
`rankings JSON`, `computed_at`.

---

## 7. Système

### `notifications`
Table standard Laravel (`id CHAR(36)`, `type`, `notifiable_*`, `data JSON`, `read_at`).

### `audit_logs`

| Colonne | Type |
|---|---|
| id | BIGINT PK |
| user_id | FK NULL |
| action | VARCHAR(60) — `payment.created`, `expense.approved`, … |
| entity_type, entity_id | VARCHAR(60), BIGINT |
| old_values, new_values | JSON NULL |
| reason | VARCHAR(255) NULL |
| ip_address | VARCHAR(45) |
| user_agent | VARCHAR(255) |
| created_at | TIMESTAMP |

Index : `(entity_type, entity_id)`, `user_id`, `action`, `created_at`.

### `video_jobs`

| Colonne | Type |
|---|---|
| id, uuid | |
| activity_id | FK |
| requested_by | FK → users |
| format | `16:9` \| `9:16` \| `1:1` |
| duration_s | 15 \| 30 \| 60 |
| theme | VARCHAR(30) |
| status | `QUEUED` \| `RENDERING` \| `DONE` \| `FAILED` |
| progress | TINYINT 0–100 |
| video_path, thumbnail_path | VARCHAR(255) NULL |
| error_message | TEXT NULL |
| started_at, finished_at | TIMESTAMP NULL |

### `settings`
Configuration club éditable : `key` (UNIQUE), `value JSON`, `type`, `description`.

### `sync_logs`
Traçabilité de la synchronisation mobile : `device_id`, `member_id`, `activity_uuid`,
`points_received`, `points_accepted`, `points_rejected`, `reason JSON`, `created_at`.

---

## 8. Diagramme des relations

```text
users ──1:1── members
                 │
                 ├──< activities ──< activity_points
                 │        │        ──< activity_photos
                 │        │        ──1:1 activity_stats
                 │        └──< video_jobs
                 │
                 ├──< event_participants >── events
                 │                              │
                 ├──< participation_members ────┤
                 │         │                    │
                 │         └──< payments        │
                 │                  │           │
                 └──< challenge_participants    │
                                                │
                                    participations
                                                │
payments ──────────────┐                        │
                       ▼                        ▼
              financial_transactions ──── cash_accounts
                       ▲
expenses ──approbation─┘
   └──< expense_attachments
```

**Chaîne d'intégrité financière**

```text
participation → participation_members (dette) → payments (encaissement)
                                                    │
                                                    ▼
                                          financial_transactions (IN)
                                                    │
expense (PENDING) ──approve──> financial_transactions (OUT)
                                                    │
                                                    ▼
                                          SOLDE = Σ IN − Σ OUT + opening_balance
```

## 9. Volumétrie et index

| Table | Volume à 3 ans (250 membres) | Stratégie |
|---|---|---|
| `activity_points` | ~250 M lignes | index `(activity_id, seq)` ; archivage > 24 mois |
| `activities` | ~75 000 | index `(member_id, started_at)` |
| `payments` | ~30 000 | négligeable |
| `financial_transactions` | ~40 000 | index `(cash_account_id, occurred_on, id)` |
| `audit_logs` | ~500 000 | purge/archivage annuel |

La lecture courante (listes, cartes, classements) ne touche **jamais** `activity_points` :
elle utilise `activities.polyline` et `leaderboard_snapshots`.
