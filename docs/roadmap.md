# Roadmap — Cyclo Dakar

Développement **par phases**. Chaque phase est livrée complète, testée et
documentée avant de passer à la suivante.

Légende : ✅ terminée · 🚧 en cours · ⏳ à venir

---

## ✅ Phase 1 — Initialisation *(terminée)*

Dépôt Git, structure du mono-dépôt, environnement Windows/XAMPP, PHP 8.3,
Laravel 13 + MySQL, React + Vite + Tailwind, Expo, service Node, fichiers `.env`,
identité visuelle, documentation d'architecture.

**Livré et vérifié**

- `GET /api/v1/health` et `GET /api/v1/config` opérationnels sur MySQL.
- `php artisan cyclo:doctor` : diagnostic complet en une commande.
- Web branché sur l'API via le proxy Vite, thème clair/sombre, palette du prototype.
- Mobile Expo : bundle Android validé (648 modules), écran d'état connecté à l'API.
- Service Node : `/health`, WebSocket `/ws`, signature HMAC vérifiée dans les deux sens.
- Documentation : architecture, base de données, API, GPS, finances, risques, design.

---

## ✅ Phase 2 — Authentification *(terminée)*

**Backend**

- Table `users` étendue : `uuid` public, téléphone, rôle, activation, dernière
  connexion, suppression douce.
- Énumération `UserRole` hiérarchique (5 rôles) avec capacités dérivées.
- Normalisation des numéros sénégalais : `+221 77 123 45 67`, `00221771234567` et
  `771234567` désignent le même compte — c'est ce qui empêche les doublons.
- Connexion par **email ou téléphone**, avec la même réponse pour « mot de passe
  incorrect » et « compte inexistant » : l'API ne permet pas d'énumérer les membres.
- Limitation de débit par identifiant **et** par IP, remise à zéro après succès.
- Mot de passe oublié / réinitialisation, lien pointant vers l'application web,
  jeton à usage unique, révocation de toutes les sessions après changement.
- Changement de mot de passe exigeant le mot de passe actuel.
- Déconnexion par appareil ou de tous les appareils.
- Middlewares `role:` (rôle minimum) et `active` (compte désactivé = accès coupé
  immédiatement, jeton révoqué au passage).
- Seeder : compte super administrateur + 3 comptes de démonstration en local.

**Web**

- Écrans Connexion, Inscription, Mot de passe oublié, Nouveau mot de passe.
- Garde de route, session vérifiée au démarrage, redirection vers la page demandée.
- Menu latéral filtré par rôle, menu de compte avec déconnexion simple ou globale.

**Mobile**

- Écrans Connexion et Inscription, accueil connecté, jeton dans le trousseau
  sécurisé du téléphone.
- Hors ligne : une coupure réseau au démarrage **ne déconnecte pas** — un membre
  sans data au départ d'une sortie doit rester connecté.

**Tests** — 75 tests, 209 assertions, tous au vert.

---

## ✅ Phase 3 — Membres, matricules et rôles *(terminée)*

**Backend**

- Table `members` : matricule `CD-000001` généré **sous verrou d'écriture**,
  jamais réattribué ; photo, statuts, contact d'urgence, jeton QR.
- `user_id` **facultatif** : un adhérent sans smartphone a une fiche, un
  matricule et un QR Code, sans compte de connexion.
- Jeton QR opaque de 43 caractères, sans aucune donnée personnelle, révocable.
- Recherche en une saisie : prénom, nom, nom complet dans les deux sens,
  matricule (`CD-000042` ou `42`), téléphone sous toutes ses formes, email.
- Deux routes distinctes : l'annuaire paginé et filtrable, et une recherche
  terrain allégée pour la collecte.
- `MemberPolicy` : chacun gère sa fiche, l'administration gère les autres ;
  le statut et le rôle échappent au membre concerné.
- Filtrage des champs **côté serveur** selon le lecteur (coordonnées, notes,
  jeton QR) — jamais délégué au client.
- Attribution de rôle avec quatre garde-fous, trace d'audit obligatoire et
  révocation immédiate des sessions.
- Table `audit_logs` et service `AuditLogger` (socle du module financier).
- L'inscription crée désormais le compte **et** sa fiche club.
- Traductions françaises complètes de la validation.

**Web**

- Annuaire : recherche différée, filtres dans l'URL, pagination, avatars à
  initiales colorées, étiquettes de statut et de rôle.
- Fiche membre : identité, coordonnées, rôle, QR Code, permissions.
- Formulaire de création et de modification avec photo.
- Attribution de rôle avec motif, depuis la fiche.

**Tests** — 118 tests, 377 assertions, tous au vert.

---

## ✅ Phase 4 — Interface web *(terminée)*

La coquille applicative (menu latéral orange et blanc, en-tête, navigation
filtrée par rôle, responsive, thème clair/sombre) avait été livrée par anticipation
juste après la phase 1. Cette phase a donc porté sur ce qui manquait réellement.

**Tableau de bord avec des données réelles**

- `GET /stats/dashboard` : effectifs par statut et par rôle, membres avec et sans
  compte, adhésions du mois, courbe sur douze mois.
- Les modules à venir renvoient `available: false` avec leur phase — **jamais un
  zéro**. Sur un tableau de bord qui affichera un solde de caisse, confondre
  « rien » et « pas encore mesuré » ruinerait la confiance du bureau.
- La tuile « Solde de caisse » n'apparaît qu'au trésorier et au-dessus, sauf si
  le club a choisi la transparence.
- Graphique des adhésions (recharts), mois creux inclus.

**Écran « Mon compte »**

Ces actions existaient dans l'API depuis la phase 2 mais n'avaient **aucun point
d'entrée dans l'interface** : un membre ne pouvait pas changer son mot de passe
autrement qu'en passant par « mot de passe oublié ».

- Fiche club personnelle, changement de mot de passe (avec option de déconnexion
  des autres appareils), rotation du QR Code, choix du thème, déconnexion globale.

**Fiabilité**

- Les tests tournent désormais sur **MySQL** et non plus SQLite : `SELECT … FOR
  UPDATE` (génération des matricules, et bientôt le solde de caisse) était
  purement ignoré par SQLite, et les fonctions de date divergentes masquaient des
  requêtes invalides en production.
- `Avatar` ne peut plus faire écran blanc si les initiales manquent.

**Tests** — 128 tests, 412 assertions, tous au vert. Rendu headless vérifié sur
16 combinaisons (4 rôles × 4 écrans).

---

## ✅ Phase 5 — Interface mobile *(terminée)*

**Navigation**

- Onglets **Accueil · Membres · Profil**, chacun avec sa pile d'écrans.
- L'aiguillage connecté / non connecté se fait par la présence d'une session,
  pas par une navigation impérative : un jeton révoqué ailleurs ramène
  l'utilisateur à la connexion, où qu'il se trouve dans l'application.
- La place centrale de la barre reste libre : elle accueillera le bouton
  « Démarrer une sortie » en phase 6.

**Écrans**

- **Accueil** : effectifs réels du club, répartition par statut, modules à venir
  avec leur phase. Le bouton « Démarrer » est visible mais désactivé — sa place
  est réservée pour que l'utilisateur sache déjà où le chercher.
- **Membres** : deux modes automatiques. Sans recherche, l'annuaire complet ;
  dès la première frappe, la route de recherche allégée. La saisie est différée
  de 350 ms — sinon « Khadim » déclencherait six requêtes.
- **Fiche membre** : le contenu suit ce que le serveur autorise à voir.
- **Mon compte** : fiche club, changement de mot de passe, rotation du QR Code,
  thème (clair / sombre / système, **persisté**), sessions.

**Tests**

Le mobile n'avait aucun test automatisé. Mise en place de Jest + Testing
Library : **19 tests**, couvrant la bascule annuaire/recherche, la saisie
différée, les messages d'échec hors ligne, la persistance du thème et le
refus d'afficher des chiffres inventés.

---

## ✅ Phase 6 — GPS et tracking *(terminée)*

### ✅ Livré : le domaine GPS côté serveur

- Tables `activities`, `activity_points`, `activity_stats`, `sync_logs`.
- **Filtre en cascade à 6 tests** : validité, précision, chronologie, duplicat,
  vitesse implicite (anti-multipath), accélération. Chaque rejet est compté par
  motif — sans quoi une trace courte serait inexplicable.
- **Calculs** : Haversine, temps actif distinct du temps total, vitesse lissée,
  dénivelé à hystérésis, splits kilométriques, allure, profil d'altitude.
- **Simplification Douglas-Peucker** (itérative, pas récursive) et encodage
  Google Polyline : 10 000 points → ~1 Ko.
- **Synchronisation idempotente** : l'uuid est généré par le client, la
  contrainte `UNIQUE(activity_id, seq)` absorbe le rejeu d'un lot.
- **Le client n'est jamais cru** : tout est recalculé serveur à la finalisation.

### ✅ Livré : la capture mobile

- **Tâche de localisation en arrière-plan** (`expo-task-manager`) enregistrée au
  chargement du module, avec service de premier plan Android. L'enregistrement
  continue écran éteint et survit à la mort de l'application.
- **Base SQLite locale** en mode WAL : chaque point est écrit immédiatement.
  Une batterie vide en pleine sortie ne perd rien.
- **Filtre GPS miroir du serveur**, avec les seuils servis par `GET /config`.
- **File de synchronisation reprenable** : ouverture, lots, finalisation —
  chaque étape est marquée en base, une coupure ne fait pas tout recommencer.
- **Écrans** : choix du sport avec explication des autorisations *avant* la
  demande système, suivi en direct, résumé.
- **Reprise automatique** : si Android a tué l'application en pleine sortie, la
  trace est retrouvée en base et la capture relancée.

⚠️ Nécessite une **Development Build** Expo (Expo Go ne gère pas la localisation
en arrière-plan). JDK 17 et le SDK Android sont présents sur le poste :

```powershell
cd mobile
npx expo install expo-dev-client
npx expo run:android
```

**Tests** — 34 tests mobiles, dont le filtre GPS qui rejoue les mêmes cas que le
serveur : les deux implémentations doivent donner le même verdict sur la même
trace, sinon le membre verrait une distance pendant sa sortie et une autre
après synchronisation.

Voir [gps.md](gps.md) et [risques.md](risques.md) §A et §B.

---

## ⏳ Phase 7 — Carte et statistiques

Leaflet + OpenStreetMap côté web, `react-native-maps` côté mobile, affichage de la
trace, marqueurs départ/arrivée, simplification Douglas-Peucker, polyline encodée,
zones traversées par reverse-geocoding groupé, graphiques vitesse/altitude.

---

## ⏳ Phase 8 — Historique des activités

Liste filtrable (sport, semaine, mois, année, période libre), miniature du parcours,
fiche détaillée, records personnels, cumuls.

---

## ⏳ Phase 9 — Événements

Création de sorties officielles, parcours prévu, inscriptions, liste des participants,
présence réelle, rappels.

---

## ⏳ Phase 10 — Participations

Campagnes de collecte, montant attendu, date limite, affectation des membres,
affectation d'un collecteur, suivi attendu / encaissé / reste.

---

## ⏳ Phase 11 — QR Code

Jeton opaque par membre (aucune donnée personnelle dans le QR), génération SVG,
rotation en cas de compromission, scanner mobile (`expo-camera`),
`GET /members/resolve/{token}`.

---

## ⏳ Phase 12 — Paiements

Encaissement par recherche ou par scan, moyens de paiement (espèces, Wave, Orange
Money, Free Money, virement), clé d'idempotence, statuts, contre-passation,
notification de reçu au membre.

Contrat à respecter : [finance.md](finance.md).

---

## ⏳ Phase 13 — Recettes, dépenses et caisse

Grand livre `financial_transactions`, catégories, dépenses avec justificatifs et
circuit de validation à seuil configurable, tableau de bord de caisse, journal de
caisse, commande de recalcul du solde.

---

## ⏳ Phase 14 — Rapports financiers

Rapports jour / semaine / mois / année / période libre, ventilation par catégorie,
export PDF, Excel et CSV, génération asynchrone pour les gros volumes.

---

## ⏳ Phase 15 — Vidéo animée du parcours

Rendu FFmpeg côté Node, carte animée, trace progressive, marqueur mobile, overlay
distance/vitesse/durée, écran final aux couleurs du club, formats 16:9, 9:16 et 1:1,
durées 15/30/60 s, file d'attente et notification.

Voir [video.md](video.md).

---

## ⏳ Phase 16 — Challenges et classements

Classements hebdomadaire, mensuel, annuel ; par distance, nombre d'activités, temps
et par sport ; challenges à objectif avec progression ; badges ; instantanés de
classement pour éviter de rebalayer les activités.

---

## ⏳ Phase 17 — Notifications

Notifications en base + push Expo : nouvelle sortie, rappel d'événement, rappel de
participation impayée, confirmation de paiement, dépense à valider, challenge,
classement, vidéo prête.

---

## ⏳ Phase 18 — Tests

Tests unitaires et d'intégration. Priorité absolue : l'algorithme GPS (sur traces
fixtures réelles) et les cinq invariants financiers.

---

## ⏳ Phase 19 — Sécurité et documentation d'API

Audit des permissions, durcissement des uploads, schéma OpenAPI + Swagger UI,
journaux d'audit consultables, conformité RGPD (export et suppression de compte).

---

## ⏳ Phase 20 — Déploiement

HTTPS, hébergement, sauvegardes automatiques, supervision, build Android (APK/AAB)
et iOS, procédure de mise à jour.

---

## MVP recommandé

Le cahier des charges du club définit un MVP plus resserré que les 20 phases.
Concrètement, **l'application devient utilisable par le club à la fin de la phase 13** :

> compte et profil · membres · vélo/course/randonnée · GPS et carte · distance,
> durée, vitesse · pause/reprise/arrêt · historique · participations · recherche
> rapide · QR Code · paiements · recettes et dépenses · caisse et solde automatique
> · journal de caisse

La vidéo animée, les challenges, les classements avancés et les paiements
électroniques automatisés viennent ensuite, sans refonte.
