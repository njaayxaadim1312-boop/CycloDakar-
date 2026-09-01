# Contrat d'API — Cyclo Dakar v1

Base : `/api/v1`
Format : JSON uniquement.
Le **web et le mobile consomment strictement les mêmes routes**. Il n'existe pas
d'API « web » et d'API « mobile ».

---

## 1. Conventions

### Enveloppe de réponse

Succès :

```json
{ "data": { ... }, "meta": { ... } }
```

Collection paginée :

```json
{
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 12,
    "per_page": 20,
    "total": 234,
    "has_more": true
  }
}
```

Erreur :

```json
{
  "message": "Les données envoyées sont invalides.",
  "errors": { "amount": ["Le montant dépasse le reste dû."] },
  "code": "VALIDATION_FAILED"
}
```

### Codes HTTP

| Code | Sens |
|---|---|
| 200 | Succès |
| 201 | Ressource créée |
| 202 | Accepté, traitement asynchrone en cours (vidéo, export) |
| 204 | Succès sans contenu |
| 401 | Non authentifié (`UNAUTHENTICATED`) |
| 403 | Authentifié mais non autorisé (`FORBIDDEN`) |
| 404 | Introuvable (`NOT_FOUND`) |
| 409 | Conflit (synchronisation incomplète, état incompatible) |
| 422 | Validation échouée (`VALIDATION_FAILED`) |
| 429 | Limite de débit atteinte (`TOO_MANY_ATTEMPTS`) |
| 501 | Fonctionnalité prévue mais non encore livrée |

### Authentification

Toutes les routes sauf `/health`, `/config` et `/auth/*` exigent :

```http
Authorization: Bearer <token Sanctum>
```

### En-têtes spécifiques

| En-tête | Usage |
|---|---|
| `Idempotency-Key` | Paiements et synchronisation GPS — évite le double traitement |
| `X-Device-Id` | Identifie l'appareil mobile dans `sync_logs` |

### Limites de débit

| Limiteur | Limite | Portée |
|---|---|---|
| `api` | 120/min authentifié, 30/min anonyme | général |
| `login` | 5/min par identifiant, 20/min par IP | connexion |
| `password-reset` | 5/h par IP | **demande** de réinitialisation |
| `password-reset-confirm` | 15/h par IP | **usage** du lien reçu (compteur distinct) |
| `gps-sync` | 240/min | ingestion de points |
| `qr-scan` | 60/min | recherche terrain et scan de QR |

### Unités — règle absolue

| Donnée | Unité en API |
|---|---|
| Distance | **mètres** (entier) |
| Durée | **secondes** (entier) |
| Vitesse | **m/s** (décimal) |
| Altitude, dénivelé | **mètres** |
| Montant | **entier de FCFA** — jamais de flottant |
| Date/heure | **ISO 8601 UTC** (`2026-09-12T07:30:00Z`) |

La conversion en km, km/h, min/km et en heure de Dakar se fait **côté client**.

---

## 2. Routes livrées (phases 1 à 4)

### `GET /health` — public

État du serveur, de la base et du stockage.

```json
{
  "data": {
    "application": "Cyclo Dakar",
    "api_version": "v1",
    "environment": "local",
    "laravel": "13.29.0",
    "php": "8.3.33",
    "timezone": "UTC",
    "server_time": "2026-08-31T14:05:17+00:00",
    "status": "healthy",
    "checks": {
      "database": { "ok": true, "driver": "mysql", "message": "…", "latency_ms": 2.5 },
      "storage":  { "ok": true, "message": "…" }
    }
  }
}
```

Renvoie **503** si un contrôle échoue.

### `GET /config` — public

Paramètres métier partagés : sports et seuils GPS, moyens de paiement, rôles,
cartographie, limites d'upload.

C'est la source unique qui garantit que le filtrage GPS du téléphone utilise
exactement les mêmes seuils que le recalcul serveur (voir [gps.md](gps.md)).

Ne contient **aucun** secret.

### `POST /auth/register` — public

```json
{
  "name": "Awa Ndiaye",
  "phone": "77 123 45 67",
  "email": null,
  "password": "cyclo2026",
  "password_confirmation": "cyclo2026",
  "device_name": "Chrome · Windows"
}
```

→ `201` avec `{ token, user }`.

Au moins **un** identifiant est requis : téléphone ou email. Le téléphone est
normalisé (`+221 77 123 45 67`, `00221771234567` et `771234567` désignent le même
compte). Le rôle n'est **jamais** lu dans la requête : tout compte créé ici est
`MEMBER`.

### `POST /auth/login` — public

```json
{ "login": "77 123 45 67", "password": "cyclo2026", "device_name": "Tecno Spark 10" }
```

`login` accepte l'email **ou** le téléphone, sous n'importe quelle mise en forme.

| Cas | Réponse |
|---|---|
| Succès | `200` `{ token, user }` |
| Mauvais mot de passe **ou** compte inexistant | `422` `INVALID_CREDENTIALS` — **réponse strictement identique** dans les deux cas, pour ne pas permettre d'énumérer les membres |
| Compte désactivé | `403` `ACCOUNT_DISABLED` |
| Plus de 5 tentatives par minute | `429` `TOO_MANY_ATTEMPTS` |

Une connexion réussie **remet le compteur de tentatives à zéro**.

### `POST /auth/forgot-password` — public

```json
{ "login": "awa@cyclodakar.sn" }
```

Renvoie **toujours** le même message, que le compte existe ou non.
Seule exception : un compte sans adresse email renvoie `422` `NO_EMAIL_ON_ACCOUNT` —
il ne peut pas se dépanner seul, autant le dire.

Le lien envoyé pointe vers l'**application web** (`FRONTEND_URL`), pas vers Laravel :
`{FRONTEND_URL}/reset-password?token=…&login=…`

Limite : 5 demandes par heure et par IP.

### `POST /auth/reset-password` — public

```json
{ "token": "…", "login": "awa@cyclodakar.sn", "password": "…", "password_confirmation": "…" }
```

Le jeton ne sert qu'**une fois**. La réinitialisation **révoque toutes les sessions** :
si la demande fait suite à une compromission, l'intrus perd l'accès immédiatement.

Limite : **compteur distinct** de la demande (15/h). Avec un compteur commun, un
membre ayant redemandé cinq liens ne pourrait plus utiliser celui qui arrive.

### `GET /me` — authentifié

Utilisateur courant, avec son rôle et ses capacités. Sert de sonde de validité du jeton.

```json
{
  "data": {
    "uuid": "638befd7-…",
    "name": "Awa Ndiaye",
    "email": null,
    "phone": "771234567",
    "phone_formatted": "77 123 45 67",
    "role": "MEMBER",
    "role_label": "Membre",
    "abilities": { "collect": false, "manage_finance": false, "administer": false },
    "is_active": true,
    "last_login_at": "2026-08-31T15:20:00+00:00"
  }
}
```

`abilities` sert au client à **masquer** ce qui est inaccessible — jamais à
autoriser. L'autorisation réelle est refaite côté serveur à chaque requête.

L'identifiant auto-incrémenté n'est **jamais** exposé : seul l'`uuid` circule.

### `POST /auth/logout` — authentifié

```json
{ "all_devices": false }
```

Par défaut, seul le jeton courant est révoqué : se déconnecter du web ne doit pas
couper le téléphone en pleine sortie GPS. `all_devices: true` révoque tout — c'est
le geste à faire quand on perd son téléphone.

### `POST /auth/change-password` — authentifié

```json
{
  "current_password": "…",
  "password": "…",
  "password_confirmation": "…",
  "logout_other_devices": true
}
```

Le mot de passe actuel est exigé même si la session est valide : un téléphone laissé
déverrouillé ne doit pas suffire à verrouiller le compte de son propriétaire.

### `GET /stats/dashboard` — authentifié · phase 4

Statistiques du tableau de bord.

```json
{
  "data": {
    "members": {
      "total": 12, "active": 9,
      "by_status": { "ACTIVE": { "label": "Actif", "count": 9 }, "…": {} },
      "by_role":   { "TREASURER": { "label": "Trésorier", "count": 1 }, "…": {} },
      "with_account": 5, "without_account": 7,
      "joined_this_month": 6,
      "growth": [{ "month": "2026-08", "label": "août 26", "count": 6 }]
    },
    "activities":     { "available": true, "total": 214, "distance_m": 4812300,
                        "moving_time_s": 618400, "this_month": 19 },
    "events":         { "available": false, "phase": 9 },
    "participations": { "available": false, "phase": 10 },
    "finance":        { "visible": true, "available": false, "phase": 13 },
    "generated_at": "2026-08-31T16:00:00+00:00"
  }
}
```

Trois règles gouvernent cette route :

1. **Aucun chiffre inventé.** Un module non livré renvoie `available: false` avec
   sa phase, jamais un `0`. « Aucune activité » et « module pas encore livré » ne
   veulent pas dire la même chose — et sur un tableau de bord qui affichera un
   solde de caisse, la confusion serait grave.
2. **Tous les statuts et rôles sont présents, même à zéro.** Un statut absent
   disparaîtrait de l'affichage et semblerait ne pas exister.
3. **La caisse est masquée** (`finance.visible: false`) aux membres ordinaires,
   sauf si le club a choisi la transparence
   (`settings.public_balance`).

La courbe couvre douze mois pleins, mois creux inclus : les sauter donnerait une
fausse impression de croissance continue.

---

## 3. Membres — phase 3

Les fiches sont adressées par leur `uuid`, jamais par l'identifiant
auto-incrémenté : sinon on pourrait énumérer les fiches et connaître l'effectif.


### `GET /stats/me` — authentifié · phase 8

Cumuls, régularité et records personnels du membre connecté.

| Paramètre | Valeurs | Défaut |
|---|---|---|
| `period` | `week` · `month` · `year` · `all` | `month` |

```json
{
  "data": {
    "period": "month",
    "period_label": "Ce mois-ci",
    "period_from": "2026-08-01",
    "totals": {
      "activities": 7, "distance_m": 214500, "moving_time_s": 28900,
      "duration_s": 31200, "elevation_gain_m": 340, "avg_speed_mps": 7.422
    },
    "by_sport": {
      "CYCLING": { "label": "Cyclisme", "activities": 5,
                   "distance_m": 190000, "moving_time_s": 24000 },
      "RUNNING": { "…": {} }, "HIKING": { "…": {} }
    },
    "records": {
      "longest_distance": {
        "value": 118400, "activity_uuid": "…", "activity_title": "Dakar — Popenguine",
        "sport": "CYCLING", "achieved_at": "2026-04-12T06:30:00+00:00"
      },
      "longest_duration": { "…": {} },
      "max_speed": { "…": {} },
      "most_elevation": null,
      "best_pace": { "…": {} }
    },
    "trend": [{ "week": "2026-06-08", "label": "8 juin",
                "distance_m": 0, "activities": 0 }]
  }
}
```

Quatre points de contrat, chacun destiné à empêcher un affichage trompeur :

- **Les cumuls suivent la période, les records non.** Les records portent sur toute
  la carrière du membre : un record du mois n'est pas un record.
- **Un record absent vaut `null`, jamais zéro.** Dakar est plate et beaucoup de
  sorties finissent à 0 m de dénivelé ; « record : 0 m » se lirait comme une
  performance mesurée. Le client affiche un tiret.
- **Tous les sports sont présents, même à zéro.** Un sport absent de la réponse
  disparaîtrait de l'affichage et semblerait ne pas exister dans le club.
- **Les douze semaines de `trend` sont toujours renvoyées**, les semaines creuses
  à zéro : une courbe qui les sauterait donnerait une fausse impression de
  régularité.

`avg_speed_mps` est calculée sur les totaux (distance cumulée ÷ temps cumulé), et
non en moyennant les moyennes : une sortie de 2 km ne doit pas peser autant qu'une
de 40 km.

Un compte sans fiche membre reçoit `404 NO_MEMBER_PROFILE` plutôt que des cumuls à
zéro — l'absence de fiche et l'absence de sorties n'ont pas la même réponse.

### `GET /members` — annuaire paginé

```
?search=Kha&status=ACTIVE&role=COLLECTOR&has_account=0&sort=name&per_page=20&page=2
```

| Paramètre | Valeurs |
|---|---|
| `search` | prénom, nom, matricule (`CD-000042` ou `42`), téléphone (toute forme), email |
| `status` | `ACTIVE` · `PENDING` · `SUSPENDED` · `FORMER` |
| `role` | rôle du compte associé |
| `has_account` | `1` avec compte, `0` sans compte (membres sans smartphone) |
| `sort` | `name` (défaut) · `matricule` · `recent` · `seniority` |
| `per_page` | 5 à 100, défaut 20 |

Tout membre connecté peut consulter l'annuaire : c'est un club sportif, et le
collecteur doit pouvoir retrouver n'importe qui.

### `GET /members/search?q=Kha&limit=10` — recherche terrain

Réponse volontairement allégée (`uuid`, `matricule`, `full_name`, `initials`,
`phone_formatted`, `photo_url`, `status`) et **non paginée** : le collecteur tape
trois lettres et choisit dans une courte liste, souvent sur un réseau médiocre.

Les membres `FORMER` en sont exclus — un ancien membre n'a pas à polluer une
collecte en cours.

C'est cette route qui remplace la saisie manuelle des noms.

### `GET /members/{uuid}` · `GET /members/me`

Le **serveur** décide de ce qu'il expose, selon qui regarde :

| Champ | Visible par |
|---|---|
| nom, matricule, photo, statut, ancienneté | tout membre connecté |
| `phone`, `email`, `account` | l'intéressé, les collecteurs et au-dessus |
| `birth_date`, contact d'urgence, `qr_token` | l'intéressé et les administrateurs |
| `notes` | les administrateurs |

Un champ **absent** signifie « pas le droit de voir » ; un champ **`null`**
signifie « non renseigné ». Le filtrage n'est jamais délégué au client.

`permissions` indique ce que le visiteur peut faire sur cette fiche — pour
masquer les boutons inutiles, jamais pour autoriser.

### `POST /members` — création

`multipart/form-data` (à cause de la photo). Réservé aux collecteurs et au-dessus :
c'est le cas « un nouveau se présente au départ d'une sortie ».

Le **matricule** (`CD-000001`, généré sous verrou d'écriture) et le **jeton QR**
sont posés par le serveur. Un client qui les enverrait les verrait ignorés.

Le membre créé n'a **pas** de compte de connexion — c'est normal, tous les
adhérents n'ont pas de smartphone.

### `POST /members/{uuid}` — modification

`POST` et non `PATCH` : ni les navigateurs ni React Native n'envoient de fichier
en multipart sur une requête `PATCH`.

Un membre modifie sa propre fiche ; un administrateur, n'importe laquelle.
Le champ `status` n'est accepté que d'un administrateur — sinon un membre
suspendu se réactiverait lui-même.

### `POST /members/{uuid}/role` — attribution d'un rôle

```json
{ "role": "TREASURER", "reason": "Élu trésorier en assemblée générale" }
```

L'opération la plus sensible du module : elle ouvre l'accès à la caisse.

| Règle | Effet |
|---|---|
| Réservé aux administrateurs | `403` sinon |
| On ne modifie pas son propre rôle | `403` |
| Seul un `SUPER_ADMIN` touche à un administrateur | `403` sinon |
| On ne nomme pas plus haut que soi | `422` |
| Membre sans compte | `422` `MEMBER_HAS_NO_ACCOUNT` |

Effets systématiques : écriture dans `audit_logs` (auteur, avant/après, motif)
et **révocation de toutes les sessions** du membre — les jetons émis portaient
les capacités de l'ancien rôle, et une rétrogradation doit prendre effet
immédiatement.

### `POST /members/{uuid}/rotate-qr`

Révoque le QR Code et en émet un nouveau. Accessible à l'intéressé et aux
administrateurs. À utiliser si un membre pense que son QR a été copié.

### `DELETE /members/{uuid}` — archivage

Suppression **douce** uniquement : les activités et les paiements du membre y
font référence. Le compte associé est désactivé et ses jetons révoqués, mais il
n'est pas supprimé — ses écritures financières doivent rester rattachées.

Dans la plupart des cas, passer le statut à `FORMER` est le geste juste.

---

## 4. Contrôle par rôle

Le middleware `role:` raisonne en **rôle minimum** :

```php
Route::middleware('role:TREASURER')  // trésorier, administrateur, super administrateur
```

Sans cela, il faudrait énumérer les rôles supérieurs sur chaque route financière,
et l'oubli finirait par arriver. Refus : `403` `FORBIDDEN`.

Le middleware `active` est appliqué à **toutes** les routes authentifiées : un compte
désactivé perd l'accès immédiatement, sans attendre l'expiration de son jeton, et le
jeton présenté est révoqué au passage.

---

## 5. Routes à venir, par phase



### Phase 6 — Activités et GPS

```http
POST   /activities                  { uuid, sport, started_at, device_info }
                                    idempotent sur `uuid`
POST   /activities/{uuid}/points    { points: [{ seq, lat, lng, altitude_m, speed_mps,
                                                 accuracy_m, heading_deg, recorded_at,
                                                 is_paused }] }
                                    → { accepted, rejected, last_seq }
POST   /activities/{uuid}/finalize  { ended_at, expected_points_count }
                                    → 409 si des lots manquent
GET    /activities?sport=&from=&to=&page=
GET    /activities/{uuid}
DELETE /activities/{uuid}
POST   /activities/{uuid}/photos    (multipart)
GET    /activities/{uuid}/gpx
```

### Phase 9 — Événements

```http
GET    /events?status=&from=
POST   /events
GET    /events/{id}
PATCH  /events/{id}
POST   /events/{id}/join
DELETE /events/{id}/join
POST   /events/{id}/check-in        { member_id }   présence réelle
```

### Phase 10–11 — Participations et QR

```http
GET    /participations?status=&event_id=
POST   /participations              { name, expected_amount, due_on, event_id?, member_ids[] }
GET    /participations/{id}         → attendu / encaissé / reste, par membre
POST   /participations/{id}/members { member_ids[] }
GET    /members/resolve/{qr_token}  → identifie un membre depuis son QR Code
```

### Phase 12 — Paiements

```http
POST   /participations/{id}/payments
       { member_id, amount, method, reference?, idempotency_key }
       → 422 si amount > reste dû  (voir finance.md, invariant I3)
POST   /payments/{id}/reverse       { reason }   contre-passation, jamais suppression
GET    /payments?from=&to=&collector_id=&method=
GET    /me/participations
```

### Phase 13 — Finances

```http
GET    /finance/dashboard           → solde, recettes, dépenses, à collecter, engagé
GET    /finance/transactions?from=&to=&direction=&category_id=&event_id=
POST   /finance/income              { amount, income_category_id, label, occurred_on }
POST   /expenses                    { amount, expense_category_id, description, spent_on }
POST   /expenses/{id}/approve
POST   /expenses/{id}/reject        { reason }
POST   /expenses/{id}/attachments   (multipart : image ou PDF)
GET    /finance/categories
```

Il n'existe **aucune** route permettant d'écrire un solde. Voir
[finance.md](finance.md), invariant I1.

### Phase 14 — Rapports

```http
GET    /finance/reports?period=day|week|month|year|custom&from=&to=&format=json|pdf|xlsx|csv
```

### Phase 15 — Vidéo

```http
POST   /activities/{uuid}/video     { format, duration_s, theme }  → 202 + job
GET    /video-jobs/{uuid}                                          → statut, progression, url

# Internes, appelées par Node, signées en HMAC (jamais par un utilisateur) :
POST   /internal/video-jobs/{uuid}/progress
POST   /internal/video-jobs/{uuid}/complete
POST   /internal/video-jobs/{uuid}/fail
```

### Phase 16–17 — Communauté

```http
GET    /leaderboard?period=week|month|year&metric=distance|activities|duration&sport=
GET    /challenges
POST   /challenges/{id}/join
GET    /notifications
POST   /notifications/{id}/read
POST   /notifications/read-all
```

### Phase 19 — Administration

```http
GET    /audit-logs?entity_type=&user_id=&action=&from=&to=
GET    /settings
PATCH  /settings
```

---

## 6. Documentation interactive

À partir de la **phase 19**, un schéma **OpenAPI 3.1** est généré et servi sur
`/api/documentation` (Swagger UI). D'ici là, ce document fait foi.
