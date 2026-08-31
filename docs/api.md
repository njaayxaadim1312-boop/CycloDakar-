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
| 429 | Limite de débit atteinte |
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
| `password-reset` | 5/h par IP | mot de passe oublié |
| `gps-sync` | 240/min | ingestion de points |
| `qr-scan` | 60/min | scan de QR sur le terrain |

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

## 2. Routes livrées (phase 1)

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

### `GET /me` — authentifié

Utilisateur courant. Sert de sonde de validité du token.

---

## 3. Routes à venir, par phase

### Phase 2 — Authentification

```http
POST   /auth/register              { name, phone, email?, password }
POST   /auth/login                 { login, password }            → { token, user }
POST   /auth/logout
POST   /auth/forgot-password       { login }
POST   /auth/reset-password        { token, password }
POST   /auth/change-password       { current_password, password }
```

### Phase 3 — Membres

```http
GET    /members?search=&status=&role=&page=
GET    /members/{id}
POST   /members
PATCH  /members/{id}
GET    /members/{id}/qr             → SVG du QR Code
POST   /members/{id}/rotate-qr      → révoque l'ancien jeton
```

La recherche accepte nom, prénom, téléphone ou matricule — c'est elle qui remplace
la saisie manuelle des noms lors des collectes.

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

## 4. Documentation interactive

À partir de la **phase 19**, un schéma **OpenAPI 3.1** est généré et servi sur
`/api/documentation` (Swagger UI). D'ici là, ce document fait foi.
