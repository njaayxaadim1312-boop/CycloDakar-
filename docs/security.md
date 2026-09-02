# Sécurité et confidentialité

> Mesures en place dès la phase 1 marquées ✅ ; les autres sont rattachées à leur phase.

## 1. Authentification

- **Laravel Sanctum**, mode *token* (pas cookie) : même flux pour le web et le mobile,
  révocation immédiate côté serveur via `personal_access_tokens`.
- Mots de passe hachés en **bcrypt**, coût 12.
- Connexion possible par email **ou** téléphone (usage local à Dakar).
- Le token mobile est stocké dans le **trousseau du système** (Keychain / Keystore),
  jamais en clair. ✅ *(`mobile/src/lib/api.ts`)*
- Limite de débit sur la connexion : 5/min par identifiant **et** 20/min par IP. ✅
  Les deux compteurs sont nécessaires : le premier freine le bourrinage d'un compte,
  le second empêche un attaquant de verrouiller le compte d'un membre à sa place.

## 2. Autorisations (RBAC)

Six rôles : `MEMBER`, `RIDE_LEADER`, `COLLECTOR`, `TREASURER`, `ADMIN`,
`SUPER_ADMIN`.

**`RIDE_LEADER` (chef de groupe) se place entre le membre et le collecteur.**
C'est une séparation des pouvoirs, pas une commodité : encadrer une sortie —
la planifier, en tracer l'itinéraire, pointer les présences — n'ouvre aucun
accès à l'argent du club. Auparavant, planifier exigeait le rôle de collecteur,
si bien que nommer un chef de groupe revenait à lui confier la caisse.

Règle du projet : **aucune vérification de rôle dans un contrôleur.** Tout passe par
des Policies Laravel (`authorize('pay', $participation)`), ce qui garantit qu'une
règle est écrite une seule fois et testable isolément.

Le détail des permissions financières est dans [finance.md](finance.md) §7.

## 3. Le client n'est jamais cru

| Donnée | Origine réelle |
|---|---|
| `collected_by`, `created_by` | session authentifiée |
| Solde de la caisse | somme des transactions, recalculée serveur |
| Statistiques d'activité | recalculées serveur depuis les points bruts |
| Statut de paiement | dérivé des paiements enregistrés |
| Rôle | jamais modifiable par l'utilisateur lui-même |

Une requête qui tenterait d'envoyer l'un de ces champs le voit ignoré, et
`Model::preventSilentlyDiscardingAttributes()` fait échouer bruyamment en
développement toute assignation non déclarée. ✅

## 4. Validation

Toute entrée passe par une **Form Request** Laravel. Les montants sont validés
`integer|min:1`, les coordonnées bornées, les énumérations restreintes par `Rule::in`.

Erreurs renvoyées en 422 avec le détail par champ, en français. ✅

## 5. Uploads

| Contrôle | Valeur |
|---|---|
| Taille maximale | 10 Mo (`UPLOAD_MAX_SIZE_KB`) |
| Types image | `image/jpeg`, `image/png`, `image/webp` |
| Types document | `application/pdf` |
| Vérification | **contenu réel** du fichier, jamais l'extension ni l'en-tête client |
| Nom de fichier | régénéré (UUID) — jamais celui fourni par le client |

Les **justificatifs financiers** sont stockés **hors de `public/`** et servis
uniquement par une route signée à durée limitée. Une facture ne doit pas être
accessible en devinant une URL.

## 6. Limitation de débit ✅

Voir [api.md](api.md) §1. Régimes distincts pour la connexion, l'API générale,
l'ingestion GPS et le scan de QR : un seul seuil global rendrait soit la connexion
trop permissive, soit la synchronisation impossible.

## 7. QR Code des membres

Le QR **ne contient aucune donnée personnelle** : ni nom, ni téléphone, ni matricule.
Il porte un **jeton opaque aléatoire** (43 caractères) qui ne sert qu'à interroger le
serveur. Un QR photographié par un tiers ne révèle donc rien, et il peut être
**révoqué** (`POST /members/{id}/rotate-qr`) sans changer l'identité du membre.

## 8. Échanges Laravel ↔ Node ✅

Signés en **HMAC-SHA256** avec un secret partagé, horodatage inclus dans la base
signée (anti-rejeu, fenêtre de 5 min), comparaison à **temps constant**.
Node n'a **aucun** accès à la base de données.

## 9. Audit

`audit_logs` enregistre toute opération financière et administrative : auteur,
action, entité, valeurs avant/après, motif, IP, agent utilisateur, horodatage.

Aucune opération financière validée ne peut être supprimée : la correction se fait
par contre-passation. Voir [finance.md](finance.md), invariant I2.

## 10. Données personnelles et géolocalisation

La trace GPS d'un membre est une donnée sensible : elle révèle son domicile, ses
horaires et ses habitudes.

- **Consentement explicite** avant toute capture, avec explication en français.
- **Visibilité par activité** : `PRIVATE` (par défaut), `CLUB`, `PUBLIC`.
- **Export** de ses propres données (GPX, JSON).
- **Suppression de compte** avec effacement des activités et des points GPS.
  Les écritures financières, elles, sont **anonymisées** et non supprimées : elles
  engagent la comptabilité du club, pas seulement le membre.
- Aucune position n'est transmise à un tiers. Le reverse-geocoding n'envoie à
  Nominatim que des **centres de cellules de 2 km**, jamais la trace.

## 11. Production (phase 20)

- **HTTPS obligatoire**, HSTS activé.
- `APP_DEBUG=false` — une trace d'exception révèle la structure de la base.
- Identifiants de base dédiés, jamais `root`.
- Sauvegardes automatiques quotidiennes, **restauration testée** (une sauvegarde
  jamais restaurée n'est pas une sauvegarde).
- Journaux d'erreurs centralisés, avec masquage des secrets. ✅ *(déjà en place côté Node)*
- Dépendances mises à jour (`composer audit`, `npm audit`).
