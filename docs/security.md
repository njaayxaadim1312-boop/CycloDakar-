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

## 2. Autorisations (RBAC) ✅

**L'audit des permissions est fait par la machine, pas à l'œil.**
`tests/Feature/Security/RouteProtectionTest.php` énumère les routes réellement
enregistrées et vérifie que chacune exige une session, contrôle que le compte
est actif, et n'expose aucune clé primaire interne. Une liste blanche courte,
justifiée entrée par entrée, porte les seules exceptions.

Une relecture à l'œil protège mal : les routes sont soixante-dix, elles
grossissent à chaque phase, et il suffit d'en écrire une hors du bon groupe pour
l'ouvrir à tout internet. C'est le genre d'erreur qu'on ne voit pas dans son
propre code, parce qu'on sait ce qu'on a voulu écrire.

Le test se garde lui-même : il échoue s'il voit moins de soixante routes, sinon
un préfixe renommé le ferait passer sans rien vérifier.


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
| Métadonnées | **EXIF effacé** par ré-encodage, orientation appliquée d'abord |
| Dimensions | réduites à 1 024 px (photo) ou 1 920 px (fond d'écran) |

Les **justificatifs financiers** sont stockés **hors de `public/`** et servis
uniquement par une route contrôlée. Une facture ne doit pas être accessible en
devinant une URL.

### L'EXIF est effacé, et ce n'est pas un détail ✅

**Une photo prise au téléphone porte les coordonnées GPS du lieu de la prise de
vue.** Invisibles à l'œil, lisibles par n'importe quel outil en deux secondes.
Un membre qui envoie sa photo de profil prise chez lui publierait donc son
adresse, sur un disque public, sans jamais l'avoir su.

C'est exactement ce que le §10 interdit pour les traces GPS : une photo révèle
la même chose, en un point au lieu d'une trace.

L'effacement se fait par **ré-encodage** et non par suppression de champs : GD
ne recopie aucune métadonnée, si bien que l'image sortante ne porte que des
pixels. Une liste de champs à effacer, elle, finirait par en oublier un.

**Le piège : l'orientation.** Un téléphone n'écrit pas l'image tournée ; il note
dans l'EXIF « tourne-la de 90° avant d'afficher ». Effacer sans appliquer cette
rotation d'abord ferait sortir toutes les photos verticales couchées. On applique
donc l'orientation, puis on ré-encode.

Le test fabrique un JPEG portant un **segment EXIF GPS réellement conforme** —
décalages TIFF calculés, pas devinés — et vérifie qu'il est lisible AVANT
traitement. Sans cette garde, le test prouverait qu'on sait effacer… rien.

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

## 9. Audit ✅

`audit_logs` enregistre toute opération financière et administrative : auteur,
action, entité, valeurs avant/après, motif, IP, agent utilisateur, horodatage.

**Consultable depuis la phase 19**, à `/audit-logs` — administration seulement.
Il existait depuis la phase 3 sans que personne puisse le lire, ce qui est pire
qu'une absence : on renonce à d'autres contrôles en croyant celui-là actif.

Le **trésorier n'y a pas accès** : il est la personne que ce journal surveille.

**En lecture seule.** Il n'existe aucune route pour écrire, modifier ou effacer
une ligne, et il ne doit jamais en exister : un journal qu'on peut retoucher ne
prouve rien.

Aucune opération financière validée ne peut être supprimée : la correction se fait
par contre-passation. Voir [finance.md](finance.md), invariant I2.

## 10. Données personnelles et géolocalisation

La trace GPS d'un membre est une donnée sensible : elle révèle son domicile, ses
horaires et ses habitudes.

- **Consentement explicite** avant toute capture, avec explication en français.
- **Visibilité par activité** : `PRIVATE` (par défaut), `CLUB`, `PUBLIC`.
- **Export** de ses propres données — `GET /me/export` ✅. Tout y est : compte,
  fiche, sorties avec leur trace encodée, cotisations, reçus, défis,
  notifications. Un export qui ne donnerait que des résumés ne permettrait pas
  de reprendre ses données ailleurs.
- **Suppression de compte** — `DELETE /me` ✅. Mot de passe **et** confirmation
  écrite (« SUPPRIMER » en toutes lettres) : c'est irréversible, et c'est le seul
  endroit où un téléphone laissé déverrouillé permettrait de détruire un compte
  en deux appuis.

  Ce qui part : sorties, points GPS, statistiques, photo, fond d'écran,
  téléphone, contact d'urgence, notifications, appareils. Le QR est révoqué —
  une carte imprimée ne doit plus rien ouvrir.

  Ce qui reste, **sans le nom** : les écritures comptables. Elles engagent la
  caisse du club et figurent dans des rapports peut-être déjà présentés en
  assemblée ; les effacer les rendrait faux, et la règle I2 l'interdit de toute
  façon. La fiche est donc anonymisée plutôt que supprimée — le matricule
  survit, car il relie une écriture à une ligne sans rien dire de la personne.

  Le compte lui-même part en **suppression douce** : `audit_logs.user_id` le
  référence, et un effacement franc ferait disparaître l'auteur d'opérations
  financières. Le journal ne dirait plus qui a fait quoi — or c'est précisément
  le document qu'on consulte quand quelque chose cloche. L'identité, elle, est
  bien effacée.

  **L'écran dit tout cela AVANT de demander confirmation.** Le découvrir après
  coup ferait croire à un mensonge.
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
