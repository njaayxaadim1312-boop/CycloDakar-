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

## ✅ Phase 7 — Carte et statistiques *(terminée)*

**Zones traversées**

- Regroupement sur une grille de 2,2 km : une sortie de 30 min déclenche **4 à 5
  appels** de géocodage au lieu de 1 800.
- Cache définitif en base — mais **jamais** en cas de panne du service : sinon
  une coupure de dix minutes empoisonnerait le territoire pour toujours.
- Résolution en file d'attente : Nominatim impose une seconde entre deux
  requêtes, le membre n'a pas à attendre douze secondes après sa sortie.
- Vérifié en conditions réelles : `Médina · Biscuiterie · Grand Yoff · Patte d'Oie`.

**Web**

- Carte Leaflet + OpenStreetMap, trace décodée depuis la polyline (~1 Ko),
  marqueurs départ/arrivée dessinés en HTML.
- Historique filtrable, fiche détaillée complète (§22 du cahier des charges).
- Profil d'altitude à échelle cadrée sur le relief réel, splits kilométriques
  avec le meilleur kilomètre en orange.
- Encart « qualité du signal GPS » qui explique les positions écartées.

**Mobile**

- Carte du parcours sur le résumé, lue depuis la base **locale** : elle
  s'affiche sans réseau, immédiatement après l'arrêt.
- Trace décimée à 500 points pour ne pas faire ramer les téléphones d'entrée
  de gamme.

---

## ✅ Phase 8 — Historique, cumuls et records *(terminée)*

**Backend**

- `GET /stats/me?period=week|month|year|all` : cumuls de la période, répartition
  par sport, tendance sur douze semaines et records personnels.
- Les cumuls suivent la période demandée ; les **records portent sur toute la
  carrière** — un record du mois n'est pas un record.
- Un record inexistant renvoie `null`, jamais zéro. Dakar est plate : beaucoup de
  sorties finissent à 0 m de dénivelé, et « record : 0 m » se lirait comme une
  performance mesurée.
- Tous les sports figurent dans la réponse, même à zéro ; les douze semaines de la
  tendance aussi, les creuses comprises.
- Vitesse moyenne calculée sur les totaux, pas en moyennant les moyennes.
- Le tableau de bord porte enfin de **vraies mesures d'activité** (`available: true`)
  au lieu d'annoncer la phase 8.
- Générateur de matricules corrigé : le prochain numéro suit le **plus grand
  matricule**, pas le dernier membre créé, et une collision fait avancer le
  compteur au lieu de réessayer cinq fois le même numéro. Défaut révélé par la
  suite complète ; deux tests de non-régression le verrouillent.

**Web**

- Écran **Mes statistiques** (`/stats`) : filtre de période porté par l'URL,
  cumuls, graphique de régularité sur douze semaines, répartition par sport et
  records cliquables menant à la sortie qui les a établis.
- Le menu distinguait mal « livré en phase N » de « à venir en phase N » :
  `DELIVERED_THROUGH_PHASE` tranche, la pastille ne marque plus que l'à-venir.

**Mobile**

- Accueil recentré sur **mes chiffres du mois** — ce qu'un membre vient vérifier
  après une sortie, ce n'est pas l'effectif du club.
- Écran **Mes sorties** : filtre de période, cumuls en tête, liste des sorties.
  Les cumuls viennent de `/stats/me`, jamais de l'addition de la page affichée.
- Écran **détail d'une sortie** : trace décodée depuis la polyligne du serveur
  (les points bruts d'une sortie ancienne ont été purgés du téléphone), chiffres,
  qualité du signal GPS et notes.
- Pile de navigation sous l'onglet Accueil plutôt qu'un cinquième onglet, qui
  aurait rétréci des cibles tactiles visées parfois avec des gants.

**Vérifié**

- Backend : **205 tests / 630 assertions** sur MySQL.
- Mobile : **44 tests** ; bundle Android exporté (4,7 Mo).
- Web : `tsc -b` et build propres ; rendu headless vérifié sur `/stats`,
  `/dashboard`, `/activities` et `/profile`.

**Reporté**

- Pagination infinie de l'historique mobile → phase 18.
- Période libre (dates au choix) sur le web → avec les rapports, phase 14.

---

## ✅ Phase 9 — Événements *(terminée)*

**Backend**

- Tables `events` et `event_participants`, plus `activities.event_id`.
  `dateTime()` et non `timestamp()` — rappel du piège MariaDB.
- Dix routes : liste filtrable, fiche, création, modification, changement
  d'état, suppression douce, inscription, désistement, liste des inscrits,
  pointage.
- **Concurrence sur les places.** Le bureau annonce une sortie à 25 places sur
  WhatsApp ; vingt membres touchent « Je participe » dans la même minute. La
  ligne de l'événement est verrouillée en écriture le temps de compter et
  d'écrire — même protection que pour les matricules, et même raison de tester
  sur MySQL : SQLite ignore `SELECT ... FOR UPDATE`.
- **Liste d'attente** plutôt que refus sec : refuser ferait perdre au club des
  participants qui seraient venus si une place s'était libérée. Un
  désistement promeut le premier de la file.
- **Le rang dans la file ne se recalcule jamais.** Renuméroter à chaque
  désistement ferait remonter et descendre des membres sans qu'ils
  comprennent pourquoi.
- **`UNKNOWN` n'est pas `ABSENT`.** Confondre les deux accuserait d'absence des
  membres présents que personne n'a eu le temps de pointer — et ces listes
  justifieront des participations financières.
- **Un brouillon est introuvable**, pas seulement inaccessible : s'y inscrire
  renvoie 403, car un 422 nommant « Brouillon » confirmerait son existence.
- `created_by` et `checked_in_by` viennent de la session. Un membre ne se
  déclare pas présent lui-même.

**Web**

- Calendrier filtrable (à venir / passées, sport, mes inscriptions), filtres
  portés par l'URL.
- Fiche : inscription, cycle de vie réservé au bureau, liste des participants
  avec pointage en un geste.
- Formulaire : saisie en kilomètres, envoi en mètres — la conversion vit à la
  frontière, pas dispersée dans les écrans.
- `SelectField` / `TextareaField` extraits : la même chaîne de classes était
  recopiée pour la quatrième fois.

**Mobile**

- Nouvel onglet **Sorties**, placé avant « Démarrer » pour que le geste
  principal reste au centre de la barre.
- Calendrier et fiche, avec inscription en bouton 72 dp.

**Vérifié**

- Backend : **254 tests / 757 assertions** sur MySQL (49 nouveaux).
- Mobile : **58 tests** ; bundle Android exporté (4,8 Mo).
- Web : `tsc -b` et build propres ; rendu headless de `/events`,
  `/events/:uuid` et `/dashboard`, en collecteur et en membre.

**Reporté**

- Pointage par **scan du QR Code** → phase 11. C'est le geste réel du jour J :
  chercher cinquante membres dans une liste sur un téléphone serait
  inutilisable au départ d'une sortie. Le pointage web reste disponible.
- Rappels avant la sortie et notification du membre promu depuis la liste
  d'attente → phase 17. Les points d'accroche sont marqués dans le code.
- Tracé du parcours prévu sur la carte → avec l'éditeur d'itinéraire, phase 15.

---

## ✅ Phase 10 — Participations *(terminée)*

**Backend**

- Tables `participations` et `participation_members`. Dix routes : liste, fiche
  avec suivi, création, modification, changement d'état, suppression,
  affectation des membres, modification et retrait d'une ligne, et
  « ce que je dois aller chercher » pour un collecteur.

Quatre invariants comptables, chacun testé :

1. **L'argent est en entiers de FCFA.** `5000.5` est refusé avec un message
   explicite, pas arrondi en silence. Un arrondi invisible sur de l'argent est
   pire qu'un refus.
2. **`paid_amount` et `status` sont dérivés**, jamais reçus du client. Les
   accepter laisserait quiconque se déclarer à jour de cotisation. Un test
   envoie explicitement ces champs et vérifie qu'ils sont ignorés.
3. **Le montant est figé à l'affectation.** Relever le tarif ne réécrit pas
   les dettes déjà créées : sinon un versement de 5 000 apparaîtrait partiel
   sur une dette rétroactivement portée à 7 500.
4. **On ne supprime pas ce qui a reçu de l'argent.** Une ligne payée est
   ANNULÉE ; une collecte ayant reçu des paiements refuse d'être supprimée.
   Supprimer laisserait des paiements orphelins.

Autres décisions :

- Sans liste, l'affectation prend **tous les membres actifs** — le geste réel
  d'une cotisation annuelle. Opération idempotente.
- Les lignes annulées sortent du montant attendu : un membre dispensé ne doit
  pas gonfler ce que le club croit avoir à recevoir.
- Créer et modifier demande le rôle de **trésorier** ; un collecteur encaisse,
  il ne décide pas de ce que le club demande à ses membres.
- Les transitions ont leur propre droit, plus permissif qu'`update` : sans
  cela, rouvrir une collecte close renvoyait 403 quand rouvrir une annulée
  renvoyait 422, pour une même cause.

**Web**

- Liste des collectes avec les trois chiffres et une barre de progression.
- Fiche : suivi, actes sur la campagne, liste nominative des dettes (impayés
  en tête — c'est ce qu'un collecteur vient chercher).
- Formulaire : montant saisi en francs, entier, **sans conversion**. Un aperçu
  mis en forme (« 5 000 FCFA ») lève l'ambiguïté d'un « 5000 » tapé à la volée.
- Tableau de bord : « Reste à collecter » porte de vrais montants, et
  disparaît complètement sous le rôle de collecteur.

**Vérifié**

- Backend : **294 tests / 880 assertions** sur MySQL (29 nouveaux).
- Web : `tsc -b` et build propres ; rendu headless de `/participations`,
  `/participations/:uuid` et du tableau de bord, en trésorier et en collecteur.

**Reporté**

- Les **encaissements** → phase 12. `paid_amount` vaut donc zéro partout, et
  c'est la vérité : le module n'est pas livré. On ne simule aucun montant.
  `ParticipationMember::recalculate()` existe déjà pour que le statut n'ait,
  dès le premier jour, qu'un seul chemin d'écriture.
- La vue « ma dette » côté membre → phase 12, avec les paiements.
- Les relances avant échéance → phase 17.

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
