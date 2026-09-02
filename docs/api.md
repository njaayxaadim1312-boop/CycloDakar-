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

## Participations — phase 10

Campagnes de collecte. **Réservé aux collecteurs et au-dessus** ; créer et
modifier demande le rôle de trésorier. Un membre verra SA dette dans son espace
personnel — phase 12.

**Tous les montants sont des entiers de FCFA**, à l'entrée comme à la sortie.
Un décimal est **refusé**, jamais arrondi : un arrondi invisible sur de
l'argent est pire qu'un refus.

### `GET /participations`

| Paramètre | Valeurs | Défaut |
|---|---|---|
| `scope` | `open` · `all` | `open` |
| `status` | `DRAFT` · `OPEN` · `CLOSED` · `CANCELLED` | — |

Par défaut, ce qui demande une action : les collectes closes n'appellent plus
rien et encombreraient la liste. Les brouillons ne sortent que pour leur auteur
et l'administration.

### `GET /participations/{uuid}`

```json
{
  "data": {
    "name": "Sortie Lac Rose",
    "status": "OPEN", "status_label": "Ouverte",
    "expected_amount": 5000,
    "due_on": "2026-09-20", "is_overdue": false,
    "tally": {
      "expected_amount": 15000, "collected_amount": 7000,
      "remaining_amount": 8000, "members": 3, "paid_members": 1,
      "progress_percent": 46.7
    },
    "lines": [{
      "id": 1,
      "member": { "matricule": "CD-000042", "full_name": "…", "phone_formatted": "…" },
      "expected_amount": 5000, "paid_amount": 0, "remaining_amount": 5000,
      "status": "NON_PAYE", "status_label": "Non payé"
    }]
  }
}
```

Le **suivi est calculé par le serveur**, jamais déduit par le client : deux
clients qui additionneraient différemment afficheraient deux « restes à
collecter », ce qui est inacceptable sur de l'argent.

Les lignes **annulées sont exclues** de `expected_amount` : un membre dispensé
ne doit pas gonfler ce que le club croit avoir à recevoir. Les impayés
arrivent en tête de `lines`.

### `POST /participations` · `PATCH /participations/{uuid}` — trésorier

`created_by` vient de la session. Une collecte naît en `DRAFT`. Changer
`expected_amount` ne réécrit **pas** les lignes existantes : le montant y est
figé à l'affectation, sinon un versement de 5 000 apparaîtrait partiel sur une
dette rétroactivement portée à 7 500.

Une collecte **clôturée ne se modifie plus** : c'est un fait comptable, et la
retoucher fausserait un rapport peut-être déjà présenté en assemblée.

### `PATCH /participations/{uuid}/status`

```
DRAFT  → OPEN, CANCELLED
OPEN   → CLOSED, CANCELLED
CLOSED, CANCELLED → (aucune)
```

Une collecte close **ne se rouvre pas** : les comptes ont été arrêtés. On en
crée une nouvelle. Transition interdite : `422 INVALID_TRANSITION`.

### `POST /participations/{uuid}/members`

```json
{ "members": ["<uuid>", "…"], "amount": 2500, "collector": "<uuid>" }
```

**Tout est facultatif.** Sans `members`, ce sont **tous les membres actifs** :
le geste réel d'une cotisation annuelle, et on ne demande pas au bureau de
cocher 250 cases pour dire « tout le monde ». Les anciens membres et les
suspendus sont écartés : les appeler gonflerait un attendu que personne ne
versera.

L'opération est **idempotente** : relancer ne crée pas de doublon et ne
réinitialise aucune ligne. `meta` renvoie `{ created, skipped }`.

### `PATCH /participations/{uuid}/members/{line}`

Accepte `expected_amount`, `collector`, `exempt`, `note`.

**N'accepte ni `paid_amount`, ni `status`.** Ces champs sont dérivés des
paiements réels ; les recevoir du client laisserait quiconque se déclarer à
jour de cotisation — la falsification la plus simple imaginable sur cette
application. Un montant inférieur à ce qui a déjà été versé est refusé.

### `DELETE /participations/{uuid}/members/{line}`

Tant que rien n'a été encaissé, la ligne disparaît — c'est une erreur de
saisie. **Dès qu'un franc a été reçu, elle est ANNULÉE et conservée** :
supprimer laisserait un paiement orphelin, c'est-à-dire de l'argent encaissé
sans dette correspondante. La réponse dit lequel des deux s'est produit
(`outcome`).

De même, `DELETE /participations/{uuid}` refuse (`422 HAS_PAYMENTS`) si la
collecte a reçu des paiements : on l'annule.

### `GET /participations/mine` — collecteur

Ce qu'un collecteur doit aller chercher sur le terrain, **toutes collectes
confondues** : c'est la vraie question du jour J. Sans cette route, il devrait
ouvrir chaque collecte et y chercher son nom. Les lignes soldées en sont
exclues — elles n'appellent plus de déplacement.

## Événements — phase 9

Les sorties officielles du club. Trois cercles de droits : tout membre voit et
s'inscrit, un collecteur crée et pointe, seul l'auteur ou un administrateur
modifie et annule.

### `GET /events` — authentifié

| Paramètre | Valeurs | Défaut |
|---|---|---|
| `scope` | `upcoming` · `past` · `all` | `upcoming` |
| `sport` | `CYCLING` · `RUNNING` · `HIKING` | — |
| `status` | `DRAFT` · `PUBLISHED` · `ONGOING` · `DONE` · `CANCELLED` | — |
| `mine` | `1` — seulement mes inscriptions | — |

Les **brouillons ne sortent jamais** de cette liste, sauf pour leur auteur et
l'administration : le bureau prépare une sortie, corrige l'horaire, hésite sur
le parcours. Annoncer puis déplacer une date coûte plus de confiance
qu'annoncer tard.

### `GET /events/{uuid}` — authentifié

```json
{
  "data": {
    "uuid": "…",
    "title": "Grand Tour Cyclo Dakar",
    "sport": "CYCLING", "sport_label": "Cyclisme",
    "status": "PUBLISHED", "status_label": "Annoncé",
    "starts_at": "2026-09-08T07:30:00+00:00",
    "location_name": "Place de la Nation",
    "planned_distance_m": 35000,
    "difficulty": "MEDIUM", "difficulty_label": "Modéré",
    "max_participants": 25, "seats_taken": 24, "seats_left": 1, "is_full": false,
    "registrations_open": true,
    "my_registration": { "status": "WAITLIST", "queue_position": 2, "…": {} },
    "participants": [{ "member": { "…": {} }, "registration_status": "REGISTERED", "…": {} }],
    "permissions": { "update": false, "delete": false, "manage_attendance": false }
  }
}
```

`planned_distance_m` est en **mètres**, comme toutes les distances de l'API.
`seats_left` vaut `null` quand la sortie n'est pas limitée — et non un grand
nombre, qui laisserait croire à une limite haute.

La liste des participants ne porte que le **nom, les initiales et le
matricule** : savoir qui vient ne suppose pas d'obtenir l'annuaire.

### `POST /events` · `PATCH /events/{uuid}` — collecteur et au-dessus

`created_by` vient de la session, jamais du corps de la requête. Une sortie
naît en `DRAFT` sauf si `status: "PUBLISHED"` est demandé. `starts_at` doit être
dans le futur à la création ; à la modification, non — corriger l'heure d'une
sortie en cours doit rester possible.

Une sortie **terminée ne se modifie plus** : c'est un fait, pas un projet, et la
retoucher fausserait les présences déjà pointées.

### `PATCH /events/{uuid}/status` — auteur ou administrateur

Route distincte de la modification : publier, démarrer ou annuler sont des
**actes**, pas des champs. Transitions autorisées :

```
DRAFT      → PUBLISHED, CANCELLED
PUBLISHED  → ONGOING, DONE, CANCELLED
ONGOING    → DONE, CANCELLED
DONE       → (aucune)
CANCELLED  → (aucune)
```

Une sortie annoncée **ne redevient pas un brouillon** : les membres l'ont déjà
notée, on l'annule — ce qui les prévient. Une transition interdite renvoie
`422 INVALID_TRANSITION`. Redemander l'état courant est sans effet et réussit :
un double appui sur un réseau lent ne doit rien casser.

### `POST /events/{uuid}/register` · `DELETE /events/{uuid}/register`

Le membre vient de la **session** : on ne s'inscrit pas à la place d'un autre.

Si la sortie est pleine, l'inscription bascule en `WAITLIST` avec un
`queue_position` — refuser sèchement ferait perdre au club des participants qui
seraient venus si une place s'était libérée. Un désistement libérant une place
**promeut immédiatement** le premier de la file.

Le rang dans la file **ne se recalcule jamais**. Il est attribué à l'inscription
et ne bouge plus ; une réinscription après désistement repart en fin de file.

Réinscrire un membre déjà inscrit est **idempotent** : pas de doublon, pas de
retour en fin de file.

Les deux routes renvoient les compteurs à jour dans `meta` :

```json
{ "meta": { "registered": 25, "waitlist": 3, "cancelled": 1,
            "present": 0, "max_participants": 25, "seats_left": 0 } }
```

Le client n'a ainsi jamais à recalculer ce compte lui-même — ce qui divergerait
dès qu'un autre membre s'inscrit en même temps.

### `POST /events/{uuid}/attendance` — collecteur et au-dessus

```json
{ "member": "<uuid du membre>", "status": "PRESENT" }
```

`checked_in_by` et `checked_in_at` viennent de la session et de l'horloge du
serveur. C'est une **signature** : si le client pouvait la fournir, la liste des
présents ne vaudrait plus rien — et ces listes serviront à justifier des
participations financières.

Trois états : `UNKNOWN` (personne n'a pointé), `PRESENT`, `ABSENT`. **`UNKNOWN`
n'est pas `ABSENT`** : les confondre accuserait d'absence des membres présents
que le bureau n'a pas eu le temps de pointer. Repasser à `UNKNOWN` efface
l'heure et l'auteur du pointage.

Pointer un membre **non inscrit l'inscrit sur place** : celui qui se présente le
jour même est un participant réel, et c'est précisément ce que la liste doit
établir. Le pointage n'est possible que sur une sortie `ONGOING` ou `DONE`
(`422 ATTENDANCE_CLOSED`).

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
    "events":         { "available": true, "upcoming": 3, "my_upcoming": 1,
                        "next": { "uuid": "…", "title": "Grand Tour Cyclo Dakar",
                                  "starts_at": "2026-09-08T07:30:00+00:00",
                                  "location_name": "Place de la Nation" } },
    "participations": { "visible": true, "available": true, "open_campaigns": 2,
                        "expected_amount": 1250000, "collected_amount": 480000,
                        "remaining_amount": 770000, "lines": 250 },
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

### `POST /members/{uuid}/cover` · `DELETE /members/{uuid}/cover`

L'image de fond du compte — le « fond d'écran » de chaque membre. Multipart,
champ `cover`, image seulement.

Autorisation `update` sur la fiche : **chacun choisit le sien**, l'administration
gère les autres. C'est déjà la règle de la photo, et un décor n'a pas à être
plus verrouillé qu'un portrait.

`cover_url` est `null` quand rien n'a été choisi. Le serveur ne renvoie pas
d'image par défaut : sans quoi on ne distinguerait plus « n'a rien choisi » de
« a choisi ceci », et changer le décor par défaut demanderait de revenir jusqu'au
serveur.

### `POST /members/{uuid}/rotate-qr`

Révoque le QR Code et en émet un nouveau. Accessible à l'intéressé et aux
administrateurs. À utiliser si un membre pense que son QR a été copié.

### `DELETE /members/{uuid}` — archivage

Suppression **douce** uniquement : les activités et les paiements du membre y
font référence. Le compte associé est désactivé et ses jetons révoqués, mais il
n'est pas supprimé — ses écritures financières doivent rester rattachées.

Dans la plupart des cas, passer le statut à `FORMER` est le geste juste.

---

## 3 bis. Encaissements — phase 12

Tous les montants sont des **entiers de FCFA**, à l'entrée comme à la sortie.
Le contrat d'intégrité est [finance.md](finance.md).

### `POST /participations/{uuid}/payments` — collecteur assigné, trésorier, admin

```json
{
  "member": "uuid-du-membre",
  "amount": 5000,
  "method": "CASH",
  "reference": "WV-123456",
  "note": null,
  "idempotency_key": "b4f1…",
  "paid_on": "2026-09-01"
}
```

`idempotency_key` est **obligatoire**. Le client la fabrique une fois et la
réutilise à l'identique sur chaque tentative de la même saisie : c'est ce qui
empêche un double débit quand le réseau lâche entre la requête et la réponse.
Deux versements volontaires du même montant restent possibles — ils portent
deux clés différentes.

Le membre est désigné par son **uuid**, jamais par une clé interne. C'est la
règle générale de cette API, et elle prime sur l'esquisse de `finance.md` §3.1,
antérieure à cette convention.

| Réponse | Sens |
|---|---|
| `201` | encaissement enregistré |
| `200` avec `meta.replayed: true` | la clé était déjà connue — le reçu existant est renvoyé, rien n'a été écrit |
| `422 PAYMENT_REFUSED` | montant supérieur au reste dû, collecte non ouverte, membre dispensé |
| `404 LINE_NOT_FOUND` | ce membre n'est pas rattaché à cette collecte |
| `403` | collecteur non assigné à cette ligne |

`meta.line` renvoie la dette mise à jour : le collecteur voit immédiatement ce
qu'il reste à percevoir.

Ne sont **jamais** lus dans la requête : `collected_by`, `paid_amount`,
`status`, `balance_after`, le solde. Le serveur les détermine (règle I3).

### `GET /participations/{uuid}/payments` — collecteur et au-dessus

Filtres : `member`, `method`, `include_cancelled`, `per_page`. Les annulations
sont masquées par défaut mais restent atteignables — les cacher tout à fait
empêcherait de comprendre un écart de caisse.

### `GET /payments/{uuid}` · `POST /payments/{uuid}/cancel` — trésorier, admin

```json
{ "reason": "Saisi deux fois lors de la sortie du 14 septembre." }
```

**Un POST, pas un DELETE, et c'est délibéré.** Rien n'est supprimé : une
écriture de sens inverse est ajoutée au grand livre, le reçu reste consultable
et porte son motif. Le motif fait dix caractères au minimum — « erreur »
n'explique rien et ne se vérifie pas en assemblée générale.

Celui qui encaisse ne peut pas annuler : c'est le contrôle élémentaire contre
le détournement.

### `GET /payments/mine` — tout membre

La **seule** route financière ouverte à un membre ordinaire, et elle ne montre
que lui : ses dettes, ses reçus, ses totaux. Ni solde de caisse, ni versements
des autres.

### `GET /members/{uuid}/dues` — collecteur et au-dessus

Ce qu'un membre doit sur les collectes **ouvertes**, avec `can_pay` calculé
ligne par ligne. C'est ce qui donne son sens au scan du QR Code : reconnaître
quelqu'un puis encaisser, sans le chercher dans une liste.

### `GET /finance/collections?from=&to=` — trésorier, admin

Qui a encaissé combien, et **combien d'opérations ont été annulées**, comptées
à part. Ce n'est pas une statistique de confort : c'est le contrôle contre le
risque F7 (`finance.md` §6). Trente derniers jours par défaut.

### `GET /finance/cash` — trésorier, admin (ou tous si `public_balance`)

Renvoie `balance` (le cache), `derived_balance` (le même solde recalculé depuis
le grand livre — un écart signale une écriture passée hors du chemin autorisé),
et **`complete: false`** tant que les dépenses ne sont pas saisies. Aucune
interface ne doit présenter ce montant comme le solde réel du club.

Il n'existe **aucune** route acceptant un solde en entrée, et il ne doit jamais
en exister (règle I1).

---

## 3 ter. Caisse et dépenses — phase 13

### `GET /finance/dashboard?from=&to=` — trésorier, admin

Le mois en cours par défaut. **Trois nombres qui ne se mélangent pas** :

| Champ | Ce que c'est |
|---|---|
| `balance` | Ce que le club a réellement, écriture par écriture |
| `committed` | Dépenses décidées, **pas encore approuvées** — aucune ligne au grand livre (I4) |
| `receivable` | Créances sur les collectes ouvertes — **pas de la trésorerie** |

Les additionner ferait croire au bureau qu'il peut engager une dépense sur de
l'argent qui n'est pas arrivé. C'est l'erreur qui coule un club, et c'est
pourquoi les trois portent des noms distincts jusque dans le JSON.

`income`, `expenses`, `net` et `by_category` portent sur la période. Tout est
**calculé** depuis le grand livre ; aucun total n'est stocké.

### `GET /finance/transactions` — le journal de caisse

Filtres : `from`, `to`, `direction`, `category` (code), `event` (uuid).

`balance_after` est **lu, jamais recalculé** : c'est ce qui garantit qu'un
journal imprimé en assemblée se réimprime identique six mois plus tard. Il suit
l'ordre d'**enregistrement**, pas la date métier — voir
[finance.md](finance.md) §2.

### `POST /finance/income` — trésorier, admin

```json
{ "category": "DON", "amount": 150000, "label": "Don de la mairie de Dakar" }
```

Entre **directement** au grand livre, sans circuit de validation. L'asymétrie
avec les dépenses est voulue : de l'argent qui entre ne peut pas appauvrir le
club, et exiger un double regard pour enregistrer un don ferait perdre la trace
du don. Le libellé est obligatoire — un don anonyme n'est pas auditable.

### `GET /finance/categories`

Les postes actifs avec leur **sens** (`IN`/`OUT`). Sans lui, un formulaire de
recette proposerait « Transport ».

### `GET|POST /expenses` — trésorier, admin

```json
{ "category": "TRANSPORT", "amount": 80000, "label": "Bus Lac Rose",
  "supplier": "Dakar Dem Dikk", "spent_on": "2026-09-01" }
```

Une dépense naît **toujours** `PENDING`, et une dépense `PENDING` n'a **aucune**
ligne au grand livre. Le client ne peut pas demander un statut : sous
25 000 FCFA (`cyclo.finance.expense_approval_threshold`) et saisie par un
trésorier, elle est approuvée immédiatement — c'est le serveur qui en décide.

Un collecteur ne saisit pas de dépense : l'argent qui **entre** relève du
terrain, celui qui **sort** relève du bureau.

### `POST /expenses/{uuid}/approve` · `POST /expenses/{uuid}/reject`

Des **actes**, donc des POST — pas un `PATCH` sur `status`, qui laisserait
croire qu'on peut repasser d'approuvé à en attente, c'est-à-dire défaire une
écriture.

**On n'approuve pas sa propre dépense**, et on ne la refuse pas non plus. La
symétrie est volontaire : sans elle, il suffirait de saisir puis de refuser pour
faire disparaître une demande gênante sans laisser de décideur au journal. Le
refus exige un motif d'au moins dix caractères — le demandeur mérite de savoir
pourquoi.

### `POST|GET|DELETE /expenses/{uuid}/attachments[/{attachment}]`

Image ou PDF. Le fichier va sur le disque **privé** et n'est jamais servi depuis
`public/` : une facture porte un fournisseur, un montant, parfois un numéro de
compte. Le téléchargement passe par cette route, qui vérifie le rôle et répond
`Cache-Control: private, no-store`.

---

## 3 quater. Classements et défis — phase 16

### `GET /leaderboard` — tout membre

```http
GET /leaderboard?period=week|month|year&metric=distance|activities|duration|elevation&sport=&key=
```

**Une sortie privée ne classe jamais son auteur.** C'est la règle qui gouverne
tout ce module. Un membre qui marque une sortie « privée » a demandé qu'elle ne
soit pas vue ; la faire apparaître dans un classement — même sous forme d'un
total — trahirait exactement cette demande. Un classement est une publication.

Le corollaire est assumé : un membre qui met tout en privé n'apparaît nulle
part.

`meta.frozen` dit si le classement est **figé**. Une période close ne bouge
plus ; une période en cours peut encore changer. Ce n'est pas un détail
technique à cacher : c'est la différence entre « j'ai gagné » et « je suis en
tête pour l'instant ».

`meta.me` porte le rang du lecteur **même hors du top 20** — `rank: null` s'il
n'a rien de classé. Un classement qui ne montre que les premiers dit à tous les
autres qu'ils ne comptent pas.

`key` permet de relire une période passée : `2026-08`, `2026-W35`.

Les valeurs sont en **unité SI** : mètres, secondes, ou nombre de sorties.

### `GET|POST /challenges` — lecture ouverte, création réservée au chef de groupe

```json
{ "title": "100 km en septembre", "metric": "distance", "target": 100000,
  "starts_on": "2026-09-01", "ends_on": "2026-09-30", "status": "PUBLISHED" }
```

`target` est en **unité SI** — « 500 km » vaut `500000`. C'est l'interface qui
convertit ce que saisit un chef de groupe ; un champ unique qui signifierait
tantôt des kilomètres, tantôt des mètres produirait un défi mille fois trop
court.

**Créer relève du chef de groupe**, pas du trésorier : un défi est un acte
d'animation sportive. Un défi terminé ne se modifie plus — des membres ont gagné
des badges sur ces règles-là.

### `POST /challenges/{uuid}/join` · `POST /challenges/{uuid}/leave`

**La progression compte depuis le DÉBUT du défi**, pas depuis l'inscription : un
membre qui découvre le défi le 15 et roulait déjà depuis le 1er ne repart pas de
zéro. La réponse renvoie le défi complet, progression comprise.

**Quitter est refusé si le défi est déjà réussi** (`422 LEAVE_REFUSED`) : la
ligne porte un badge, et un badge fait partie de l'histoire du membre.

### `GET /challenges/{uuid}/standings`

Les finisseurs d'abord, **dans l'ordre où ils ont fini** — puis les autres par
progression. Un défi n'est pas un classement à la performance mais un objectif :
celui qui l'a atteint le premier passe devant celui qui l'a atteint après, quel
que soit son total.

### `GET /challenges/badges` — tout membre

Les défis réussis du lecteur. Un badge n'est pas une récompense inventée : c'est
un défi réel, avec ses règles, sa période et sa date de réussite.

**Un badge obtenu ne se reprend pas.** `completed_at` est figé : si la
progression retombe ensuite — sortie supprimée, passée en privé, trace
corrigée — la date reste.

---

## 3 quinquies. Notifications — phase 17

**Tout est strictement personnel.** Aucune de ces routes ne prend d'identifiant
d'utilisateur : on ne lit et on ne marque que ses propres notifications, celles
de la session. Un paramètre `user` ouvrirait la lecture des notifications
d'autrui — et elles portent des montants, des dettes, des décisions
financières.

### `GET /notifications?unread=&per_page=`

`meta.unread` accompagne toujours la liste. `code` est un identifiant **stable**
(`payment.received`, `event.reminder`), pas le nom de la classe PHP : le client
choisit son icône et sa destination dessus, et un client plus ancien que le
serveur continue de fonctionner — titre, corps et URL suffisent.

### `GET /notifications/unread-count`

Route séparée, et c'est délibéré : c'est le seul chiffre dont l'interface a
besoin en continu. Charger trente notifications pour afficher une pastille
serait absurde sur un réseau mobile.

### `POST /notifications/{id}/read` · `POST /notifications/read-all`

Marquer une notification qui n'est pas la sienne renvoie **404**, pas 403 : elle
ne doit pas être distinguable d'une notification inexistante, sinon on pourrait
éprouver l'existence d'un identifiant.

### `POST /devices` · `DELETE /devices`

```json
{ "token": "ExponentPushToken[...]", "device_name": "Redmi Note 12" }
```

Appelé à chaque démarrage du mobile : Expo peut changer le jeton après une mise
à jour du système, et un jeton périmé ne prévient pas — il cesse simplement de
recevoir.

Le jeton est **unique en base**. S'il appartenait à quelqu'un d'autre — un
téléphone prêté, revendu — il change de propriétaire plutôt que d'être dupliqué.

`DELETE` est aussi le réglage « ne plus me notifier » : **pas de jeton, pas de
push**. Les notifications en base, elles, continuent d'arriver — elles ne
réveillent personne, et un membre doit retrouver ce qu'on lui a dit même s'il a
coupé les alertes.

### Ce que le club envoie

| Code | Quand | À qui |
|---|---|---|
| `payment.received` | Encaissement enregistré | Le membre qui a payé |
| `payment.cancelled` | Reçu annulé | Le membre — il a un reçu en main |
| `expense.pending` | Dépense au-dessus du seuil | Trésoriers et admins, **sauf le demandeur** |
| `expense.decided` | Approuvée ou refusée | Le demandeur, sauf auto-approbation |
| `event.announced` | Sortie annoncée | Tout le club, **une seule fois** |
| `event.reminder` | La veille, 18 h | Les **inscrits** seulement |
| `participation.due` | 3 jours avant l'échéance | Les membres qui doivent encore |
| `challenge.completed` | Objectif atteint | Le membre |

---

## 3 sexies. Données personnelles et audit — phase 19

### `GET /me/export` — tout membre

Tout ce que le club détient sur le lecteur, en un fichier JSON téléchargeable :
compte, fiche club, sorties **avec leur trace encodée**, cotisations, reçus,
défis, notifications.

**La route ne prend aucun identifiant.** On n'exporte que son propre compte : un
paramètre `user` transformerait l'export RGPD en fuite de l'annuaire complet —
tout y est, y compris les téléphones et les contacts d'urgence.

### `DELETE /me` — tout membre

```json
{ "password": "…", "confirmation": "SUPPRIMER" }
```

Mot de passe **et** confirmation écrite en toutes lettres. C'est irréversible.

| Effacé | Conservé, sans le nom |
|---|---|
| Sorties, points GPS, statistiques | Encaissements et écritures comptables |
| Photo, fond d'écran | Le matricule (relie une écriture à une ligne) |
| Téléphone, email, contact d'urgence | Le journal d'audit |
| Notifications, appareils, sessions | |

Le QR est révoqué : une carte imprimée ne doit plus rien ouvrir. Le compte part
en suppression **douce** — `audit_logs.user_id` le référence, et un effacement
franc ferait disparaître l'auteur d'opérations financières.

La réponse détaille poste par poste ce qui a été fait : une suppression qui
répondrait « c'est fait » laisserait le membre se demander si ses traces ont
vraiment disparu.

### `GET /audit-logs` · `GET /audit-logs/actions` — administration seulement

Filtres : `action`, `entity_type`, `user` (uuid), `from`, `to`.

**Le trésorier n'y a pas accès** : il est la personne que ce journal surveille.
C'est déjà ce que dit le tableau des droits de [finance.md](finance.md), où
« voir les journaux d'audit » est la seule ligne où il a un refus.

En **lecture seule**. Il n'existe aucune route pour écrire, modifier ou effacer
une ligne d'audit, et il ne doit jamais en exister.

---

## 4. Contrôle par rôle

Six rôles, du moins au plus étendu :

| Rôle | Ce qu'il fait |
|---|---|
| `MEMBER` | S'inscrit aux sorties, enregistre ses parcours, consulte **ses** cotisations |
| `RIDE_LEADER` | **Chef de groupe** : planifie les sorties, trace l'itinéraire, pointe les présences |
| `COLLECTOR` | Encaisse — uniquement sur les dettes qui lui sont assignées |
| `TREASURER` | Caisse, annulations, rapports, dépenses |
| `ADMIN` | Rôles, paramètres, journal d'audit |
| `SUPER_ADMIN` | Tout |

**Le chef de groupe se place SOUS le collecteur, et c'est délibéré.** Encadrer
une sortie et manier de l'argent sont deux responsabilités distinctes. Tant que
planifier un itinéraire exigeait le rôle de collecteur, nommer quelqu'un chef de
groupe revenait à lui ouvrir la caisse. Son jeton ne porte d'ailleurs **aucune**
capacité de collecte : `['rides:*', 'member:*']`.

L'inverse est assumé : un collecteur, étant au-dessus, peut aussi proposer une
sortie. Une sortie mal placée se corrige ; un franc disparu ne se retrouve pas.

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

### Phase 15 — Vidéo

```http
POST   /activities/{uuid}/video     { format, duration_s, theme }  → 202 + job
GET    /video-jobs/{uuid}                                          → statut, progression, url

# Internes, appelées par Node, signées en HMAC (jamais par un utilisateur) :
POST   /internal/video-jobs/{uuid}/progress
POST   /internal/video-jobs/{uuid}/complete
POST   /internal/video-jobs/{uuid}/fail
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
