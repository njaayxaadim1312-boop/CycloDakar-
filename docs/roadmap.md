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

## ✅ Phase 11 — QR Code *(terminée)*

**Backend**

- `GET /members/{uuid}/qr` : image **SVG**, nette à toutes les tailles et
  imprimable sur une carte de membre. Jamais mise en cache — un jeton peut
  être révoqué à tout moment, et une image en cache afficherait un code devenu
  invalide.
- `GET /members/resolve/{token}` : retrouve un membre à partir d'un scan.
  **Réservé aux collecteurs**, limité en débit.

Deux exigences gouvernent ce module :

1. **Le QR ne contient aucune donnée personnelle.** Ni nom, ni téléphone, ni
   matricule, ni même l'uuid : le préfixe `CD:` suivi d'un jeton opaque de
   43 caractères, et rien d'autre. Un QR photographié dans la rue ne dit rien
   de son porteur. C'est aussi ce qui rend le jeton **révocable** : le
   compromettre ne coûte qu'une rotation.
2. **Le scan n'est pas un annuaire.** La réponse porte l'identité minimale —
   nom, matricule, statut — et jamais le téléphone ni l'adresse. Reconnaître
   quelqu'un, pas aspirer le fichier des membres un QR à la fois.

Correction d'erreur en niveau **Q** (25 %) : ces codes passeront des mois dans
un portefeuille avant d'être scannés au bord d'une route poussiéreuse.

**Web** — le QR s'affiche sur « Mon compte », sur fond blanc obligatoire (un QR
sur fond sombre n'est pas lu), avec le bouton de régénération.

**Mobile** — même affichage, plus l'**écran de scan** (`expo-camera`). Un
verrou hors état React empêche dix requêtes pour un seul scan : une caméra
émet plusieurs lectures par seconde du même code. Le bouton n'apparaît que
pour les collecteurs.

**Vérifié** — backend : **323 tests / 959 assertions** (15 nouveaux, dont
« le QR ne contient aucune donnée personnelle » et la limite de débit).
Mobile : 61 tests. Web : `tsc -b`, build, rendu headless.

**Reporté** — l'action « Encaisser » depuis le scan : phase 12. C'est elle qui
justifie tout ce module, et le point d'accroche est marqué dans l'écran.

---

## ✅ Phase 12 — Paiements *(terminée)*

Le contrat comptable de [finance.md](finance.md) devient exécutable.

**Le grand livre, livré avec les encaissements**

La phase ne devait porter que les paiements, mais un encaissement qui ne bouge
pas la caisse est un mensonge — et `balance_after` est figé à l'écriture, donc
impossible à reconstituer après coup. Les tables `cash_accounts`,
`transaction_categories` et `financial_transactions` arrivent donc ici. Les
dépenses, le journal complet et les rapports restent en phases 13 et 14.

**Les trois protections de l'encaissement**

- **Idempotence.** Un collecteur encaisse sans réseau : la requête part, la
  réponse se perd, le téléphone réessaie. La clé, obligatoire et unique en
  base, fait retrouver le paiement au lieu d'en créer un second. Deux
  versements volontaires du même montant restent possibles — deux clés.
- **Plafond du reste dû**, relu **sous verrou** : sans quoi deux collecteurs
  simultanés solderaient chacun la totalité.
- **Statut dérivé.** `paid_amount` et `status` se recalculent depuis la somme
  réelle des paiements non annulés, jamais reçus du client.

**Ce qui est impossible, et vérifié comme tel**

Écrire un solde par l'API. Modifier ou supprimer une écriture du grand livre —
les modèles lèvent une exception, la règle n'est pas qu'un paragraphe. Annuler
un paiement qu'on a soi-même encaissé : c'est le contrôle élémentaire contre le
détournement, réservé au trésorier. Annuler deux fois la même écriture.

Une annulation est une **contre-passation** : sens inverse, même montant, motif
obligatoire de dix caractères. Le reçu reste consultable, marqué annulé — un
membre qui se présente avec son papier le retrouve toujours.

**Le contrôle contre le détournement**

`GET /finance/collections` : qui a encaissé combien, et combien d'opérations ont
été annulées, **comptées à part**. Les mélanger masquerait exactement ce qu'on
cherche à voir.

**`finance:recompute-balance`**, chaque nuit à 3 h, **sans `--fix`.** Un écart
signifie qu'une écriture est passée hors de `CashLedger` ; le réparer en silence
masquerait la cause. La commande sort en échec, ce qui se voit. Elle vérifie
aussi la suite des `balance_after` — la colonne « Solde » du journal imprimé en
AG — dans l'ordre d'enregistrement.

**Web**

Encaissement depuis la fiche de collecte ; **écran de terrain** du collecteur
répondant à « qui dois-je aller voir ? », toutes collectes confondues, avec le
téléphone cliquable ; journal des reçus avec annulation motivée ; **« Mes
cotisations »**, seule page financière ouverte à un membre et qui ne montre que
lui ; rapport par collecteur pour le trésorier.

**Mobile**

Le scan mène désormais à **« Encaisser »** en premier bouton — c'est le geste
qui justifiait tout le module QR de la phase 11. Reconnaître quelqu'un puis
percevoir, sans le chercher dans une liste.

**Ce qui est reporté, explicitement**

La notification de reçu au membre (phase 17) : le canal n'existe pas, et on ne
simule pas un envoi qui n'a pas lieu. Le reçu, lui, est réel et consultable.

Le solde de caisse est annoncé **`complete: false`** tant que les dépenses ne
sont pas saisies. Le présenter comme le solde réel du club tromperait le bureau.

**Tests** — 365 backend (39 nouveaux), 65 mobile (4 nouveaux). Un test mobile a
trouvé un vrai bug : `crypto.randomUUID` détaché de son receveur lève à
l'exécution, ce qui aurait cassé l'encaissement sur le terrain.

---

## ✅ Phase 13 — Recettes, dépenses et caisse *(terminée)*

Le grand livre et la commande de recalcul étaient arrivés avec la phase 12 — un
encaissement qui ne bouge pas la caisse est un mensonge. Restaient les sorties,
et c'est là que se joue l'essentiel.

**L'invariant qui gouverne toute la phase**

Une dépense en attente n'a **aucune** ligne au grand livre. Ce n'est pas de
l'argent sorti : c'est une intention. L'écriture naît dans la même transaction
SQL que l'approbation, jamais avant.

La conséquence se voit partout dans l'interface : le trésorier lit trois
nombres qui ne se mélangent pas — ce que le club **a**, ce qu'il a **engagé**,
et ce qu'il **attend** encore des collectes. Les additionner ferait engager une
dépense sur de l'argent qui n'est pas arrivé. C'est l'erreur qui coule un club,
et elle est ici rendue impossible à commettre par inadvertance : trois libellés
distincts, jusque dans le JSON.

**Le double regard, dans les deux sens**

On n'approuve pas sa propre dépense — **et on ne la refuse pas non plus**. La
symétrie est volontaire : sans elle, il suffirait de saisir puis de refuser pour
faire disparaître une demande gênante sans laisser de décideur au journal.

Ce n'est pas une supposition de malhonnêteté. C'est la protection de celui qui
tient la caisse, qui doit pouvoir montrer qu'il n'a jamais décidé seul.

**Le seuil demande DEUX conditions**

Sous 25 000 FCFA **et** saisie par quelqu'un qui aurait de toute façon le droit
d'approuver. Un circuit de validation pour 3 000 FCFA d'eau minérale ne serait
pas suivi, et une règle qu'on contourne protège moins qu'une règle
proportionnée — mais sans la seconde condition, le seuil deviendrait une porte
ouverte pour qui n'a pas la responsabilité de la caisse.

L'interface annonce la règle **avant** l'envoi : découvrir après coup qu'il faut
un second regard donne l'impression d'un refus, alors que c'est le
fonctionnement normal.

**Une dépense refusée reste, avec son motif**

Le bureau doit pouvoir expliquer pourquoi 80 000 FCFA de transport n'ont pas été
engagés, et le demandeur mérite de savoir pourquoi on lui a dit non. Une ligne
effacée ne répond à aucune de ces deux questions.

**Les justificatifs ne sont jamais publics**

Une facture porte un fournisseur, un montant, parfois un numéro de compte. Les
fichiers vivent sur le disque privé ; le téléchargement passe par une route qui
vérifie le rôle et refuse toute mise en cache. Un test vérifie qu'un membre
ordinaire reçoit 403, et qu'un fichier qui n'est ni image ni PDF est écarté.

**Recettes manuelles : l'asymétrie assumée**

Un don entre directement au grand livre, sans circuit de validation. De l'argent
qui entre ne peut pas appauvrir le club, et exiger un double regard pour
enregistrer un don ferait perdre la trace du don. Le libellé est en revanche
obligatoire : dans six mois, personne ne saura d'où venaient 150 000 FCFA.

**Le journal de caisse**

La pièce qu'on imprime en assemblée. Sa colonne « Solde » est **lue**, jamais
recalculée à l'affichage : c'est ce qui garantit qu'un journal se réimprime
identique six mois plus tard, même si une écriture antérieure a été
contre-passée entre-temps. Une note explique qu'elle suit l'ordre
d'enregistrement — sans elle, une saisie antidatée la ferait passer pour
incohérente et l'on chercherait un bug qui n'existe pas.

**Le tableau de bord dit enfin la vérité**

La tuile « Solde de caisse » affichait `available: false` depuis la phase 4.
Elle montre désormais un vrai montant, et l'engagé **à côté** — pas fondu
dedans. Une tuile se lit vite, sans réfléchir : c'est précisément là qu'un total
trop malin fait des dégâts.

**Le tableau de bord devient la page d'accueil**

À la demande du bureau, la connexion mène désormais au tableau de bord et non
plus à l'écran d'exercice. C'est un revirement par rapport à la réorganisation
« le sport devant » : ce qu'on voit en ouvrant une application dit ce qu'elle
est, et le club a tranché autrement. L'écran d'exercice n'est pas perdu — il
vit sous `/activite`, en deuxième entrée du menu — et les anciens liens vers
`/gestion/tableau-de-bord` redirigent, pour ne casser aucun signet.

Conséquence immédiate, corrigée dans la foulée : la carte « État des services »
— version de PHP, état de la base — s'affichait à tous. Sur une page d'accueil
que tout le club traverse, c'est du diagnostic hors sujet, et « un service ne
répond pas » alarme un membre qui n'y peut rien. Elle est désormais réservée à
l'administration ; le détail reste sous « État du système ».

**Vérifié**

396 tests backend (29 pour cette phase), 65 mobile. Les six écrans financiers
ont été rendus dans un **vrai Chrome** piloté au DevTools Protocol, avec une
session authentifiée : `tsc` vérifie les types, pas ce qui plante à
l'exécution.

---

## ✅ Phase 14 — Rapports financiers *(terminée)*

**L'écran montre exactement ce que le fichier contiendra.** C'est la seule règle
d'ergonomie qui compte ici : un rapport téléchargé sans avoir pu être regardé,
on l'ouvre, on découvre qu'il ne couvre pas la bonne période, et on recommence —
la veille d'une assemblée générale. Le même appel sert donc l'affichage et les
trois formats de fichier.

**Trois formats, trois usages, et ce n'est pas de la redondance**

Le PDF se signe et se distribue : il ne se retouche pas. L'Excel se
retravaille — le trésorier y ajoute une colonne, trie, refait ses totaux — et
ses montants sont donc de vrais NOMBRES, sans quoi la première somme faite dans
le tableur renverrait zéro. Le CSV s'importe ailleurs, et c'est le format qu'on
regrette de ne pas avoir le jour où il faut sortir des données d'une
application.

Deux détails d'encodage décident de tout pour le CSV : la BOM UTF-8, sans
laquelle Excel rend « Ravitaillement » illisible, et le point-virgule comme
séparateur, la virgule étant le séparateur décimal sur un Windows français. Un
test vérifie les deux, parce qu'ils ne se voient pas à la relecture.

**Ce qui rend un rapport STABLE**

Le solde d'ouverture suit la **date métier**, pas la saisie. Une opération de
septembre saisie en octobre appartient à septembre : elle entre dans le solde
d'ouverture d'octobre, pas dans ses recettes. C'est la seule définition qui
permette de ressortir en décembre le rapport de septembre et d'y retrouver le
même chiffre. On ne lit donc pas `balance_after` ici, alors que le journal de
caisse le fait : cette colonne répond à une autre question.

**Ce qui n'entre dans aucun total**

L'engagé, daté du jour d'édition — une dépense en attente n'a pas de date de
sortie. Et les collectes, volontairement hors période : une créance n'appartient
pas à un mois, elle existe tant qu'elle n'est pas réglée. Le rapport le dit
explicitement à chaque fois plutôt que de laisser deviner.

**La borne des deux ans**

Un rapport « depuis toujours » se génère ligne par ligne en mémoire et finirait
par faire tomber la requête au moment où l'on en a le plus besoin. La génération
asynchrone prévue par `finance.md` attend la phase 17, qui livre les
notifications. D'ici là, une borne annoncée coûte moins cher qu'un échec obscur.

**Le jeu de démonstration couvre enfin la caisse**

Les écrans de trésorerie s'ouvraient vides : impossible de juger la lisibilité
d'un journal ou de vérifier qu'un rapport s'imprime. `cyclo:demo` crée
désormais une collecte avec des encaissements partiels, un don et deux dépenses
— dont une au-dessus du seuil, qui reste en attente, parce qu'un jeu où tout est
parfait ne montre aucun des états écrits pour les cas imparfaits.

Il passe par les **services réels**, jamais par des insertions directes : une
démonstration qui court-circuiterait le verrou de caisse produirait des données
que le code de production ne sait pas produire. Et il n'efface JAMAIS le grand
livre, même avec `--fresh` : une commande qui viderait `financial_transactions`
apprendrait exactement le mauvais réflexe.

**Vérifié**

406 tests backend (10 pour cette phase). Les exports ne sont pas seulement
« générés » : le `.xlsx` est **relu** par la bibliothèque pour vérifier que les
montants sont des nombres et non du texte, le PDF est contrôlé sur sa signature
et sa taille, et le CSV sur sa BOM et son séparateur. Les fichiers ont aussi été
téléchargés depuis le serveur réel et ouverts.

---

## ✅ Phase 15 — Vidéo animée du parcours *(livrée côté navigateur)*

Avancée hors ordre, à la demande du club.

**Backend** — `GET /activities/{uuid}/replay` : trace **horodatée**, décimée à
600 points. Chaque point porte sa seconde depuis le départ, sa distance cumulée
et la vitesse de son segment. Sans le temps, une animation effacerait les pauses
et ferait monter une côte aussi vite qu'une descente.

**Web** — `/activities/{uuid}/video` : le parcours se dessine du départ à
l'arrivée sur un fond OpenStreetMap, le marqueur avance à la vitesse réelle,
les statistiques défilent avec lui. Écran d'ouverture et écran final aux
couleurs du club. Formats 9:16, 1:1, 16:9 ; durées 15, 30, 60 s.

**La vidéo se fabrique dans le navigateur** (`canvas.captureStream` +
`MediaRecorder`) : pas de FFmpeg à installer, pas de file d'attente. Le fichier
part dans les téléchargements, prêt pour WhatsApp.

**Vérifié** — backend : 301 tests / 904 assertions (7 nouveaux, dont la pause
qui doit se voir dans les temps). Web : `tsc -b`, build, rendu headless, et 13
assertions sur l'interpolation et le cadrage.

**Reste à faire** — le rendu serveur FFmpeg (§4 de video.md) : pour les
navigateurs qui ne savent pas encoder, et pour fermer l'onglet pendant la
fabrication.

Voir [video.md](video.md).

---

## ✅ Phase 16 — Challenges et classements *(terminée)*

**Une sortie privée ne classe jamais son auteur.**

C'est la règle qui gouverne tout le module, et elle vaut d'être dite avant les
fonctionnalités. Un membre qui marque une sortie « privée » a demandé qu'elle ne
soit pas vue ; la faire apparaître dans un classement — même sous forme d'un
total, même sans la carte — trahirait exactement cette demande. Un classement
est une publication.

Le corollaire est assumé : un membre qui met tout en privé n'apparaît nulle
part, et c'est normal. Mieux vaut un classement incomplet qu'un classement qui
publie ce qu'on lui a confié. L'écran le dit explicitement, pour qu'on n'y voie
pas un bug.

**Les instantanés ne sont pas d'abord une optimisation.**

La roadmap les demandait « pour éviter de rebalayer les activités ». La vraie
raison est ailleurs : les sorties bougent après coup — le mobile synchronise en
différé, un membre passe une sortie en privé une semaine plus tard, une trace
est corrigée. Recalculé, le classement de septembre changerait donc en octobre,
après que le club a félicité quelqu'un. Reprendre une première place déjà
annoncée est le plus sûr moyen de faire quitter un club.

Une période close est un fait, comme une collecte clôturée : `cyclo:snapshot-
leaderboards` la fige la nuit où elle s'achève, et elle ne se retouche plus. La
période en cours, elle, se calcule en direct — la figer n'aurait aucun sens.
L'interface annonce lequel des deux on regarde.

**Quatre mesures, parce qu'une seule ferait gagner toujours les mêmes**

Distance, régularité, temps, dénivelé. La régularité est celle qui compte le
plus pour un club : elle met en avant celui qui vient chaque dimanche, pas celui
qui a le vélo le plus rapide. Le filtre par sport évite qu'un marcheur soit
comparé à un cycliste — se mesurer sur la mauvaise échelle décourage au lieu
d'entraîner.

Les ex æquo suivent la convention du sport : deux membres à égalité partagent le
rang, et le suivant saute une place.

**Le rang du lecteur est affiché même hors du top 20**

Un classement qui ne montre que les vingt premiers dit à tous les autres qu'ils
ne comptent pas. Connaître son rang est précisément ce qui donne envie de le
remonter.

**Les défis : trois promesses faites aux membres**

La progression compte **depuis le début du défi**, pas depuis l'inscription :
celui qui découvre le défi le 15 alors qu'il roulait déjà ne repart pas de zéro.
Sa barre est remplie à l'instant où il s'inscrit.

Un **badge obtenu ne se reprend pas**. `completed_at` est figé : si la
progression retombe ensuite, la date reste. Un test le vérifie en repassant une
sortie en privé après coup — la progression tombe à zéro, le badge demeure.

Un défi **terminé ne se modifie plus** : des membres ont gagné des badges sur
ces règles-là, et en changer l'objectif après coup les invaliderait
rétroactivement.

Créer un défi relève du **chef de groupe** : c'est un acte d'animation sportive,
qui n'a aucune raison de demander l'accès à la caisse.

**Les badges ne sont pas une invention**

Un badge, ici, EST un défi réussi — avec ses règles, sa période et sa date.
Créer une taxonomie de badges détachée des défis aurait demandé d'inventer des
distinctions que le club n'a pas demandées.

**Vérifié**

426 tests backend (20 pour cette phase). Le jeu de démonstration couvre les
trois états qu'un écran de défi doit savoir montrer : un défi réussi avec son
badge, un défi bien engagé, un défi ambitieux à zéro. Il est idempotent sur le
titre — relancer `cyclo:demo` n'empile pas les défis, et n'en efface aucun,
puisque les effacer ferait disparaître des badges gagnés.

---

## ✅ Phase 17 — Notifications *(terminée)*

**La base d'abord, le push ensuite.**

L'écriture en base est le canal qui ne peut pas échouer : c'est elle qui garantit
qu'un membre retrouvera l'information en ouvrant l'application, même si son
téléphone était éteint, même si Expo était en panne. Le push n'est qu'un rappel.

D'où la règle du canal Expo : **une notification qui échoue ne casse jamais
l'acte qui l'a déclenchée**. Un encaissement enregistré, une dépense approuvée,
un badge gagné — ces actes sont accomplis. Faire remonter une erreur d'envoi
annulerait la transaction : on perdrait de l'argent pour un ping raté.

**Rien ne part avant que la transaction soit validée.**

Réglé par `after_commit` sur la file, dans `config/queue.php`. Sans lui, un
encaissement annulé en fin de transaction enverrait quand même « paiement
enregistré », et le membre croirait avoir payé. Pire : le worker pourrait traiter
le message AVANT que la transaction soit écrite, et ne trouverait alors aucun
paiement à décrire. Le réglage est global plutôt que répété sur chaque
notification — une règle qu'il faut penser à répéter finit par être oubliée
quelque part.

**Ce qui est envoyé, et surtout ce qui ne l'est pas**

Le reçu d'encaissement était reporté depuis la phase 12 : le canal n'existait
pas, et plutôt que de simuler un envoi, le reçu avait été rendu consultable.
Le voici, avec son numéro — « paiement enregistré » sans référence ne permet
rien de vérifier.

L'**annulation** prévient aussi, et ce n'est pas optionnel : un membre à qui l'on
a remis un reçu se croit à jour ; s'il apprend l'annulation devant un collecteur,
en public, la faute retombera sur le club.

Une **auto-approbation ne se notifie pas à elle-même**. Dire au trésorier « votre
dépense a été approuvée » deux secondes après qu'il l'a saisie serait du bruit
pur — et c'est ainsi qu'on apprend aux gens à ignorer les notifications. Pour la
même raison, le demandeur n'est jamais invité à approuver sa propre dépense.

**Les rappels ne se répètent pas**

La sortie, la veille à 18 h — c'est le soir qu'on prépare son vélo. La
cotisation, trois jours avant l'échéance, une seule fois. Un rappel utile devient
du harcèlement à la troisième répétition, et c'est particulièrement vrai quand il
parle d'argent : quelqu'un qui n'a pas payé ne l'a peut-être pas fait parce qu'il
ne pouvait pas. Le message dit le montant, la date et le collecteur — factuel,
sans reproche.

Le rappel de sortie ne va qu'aux **inscrits**. Le rappeler à quelqu'un qui ne
s'est pas inscrit n'est pas un rappel, c'est de la publicité.

**Les appareils**

Un membre, plusieurs appareils. Le jeton est unique en base : un téléphone prêté
ou revendu change de propriétaire plutôt que d'être dupliqué — sans quoi l'ancien
utilisateur continuerait de recevoir sur un appareil qui n'est plus le sien.

Expo répond `DeviceNotRegistered` quand l'application a été désinstallée : le
jeton est alors supprimé tout seul. Sans ce nettoyage, chaque envoi collectif
traînerait des adresses mortes.

Se désabonner revient à retirer son jeton — **pas de jeton, pas de push**. Une
table de préférences par catégorie serait plus fine, mais elle n'aurait de sens
qu'une fois qu'on saura quelles notifications gênent réellement, et on ne le
saura qu'en les envoyant.

**Vidéo prête : sans objet**

La roadmap prévoyait une notification « vidéo prête ». La vidéo de parcours est
générée **dans le navigateur** depuis la phase 15 : il n'existe aucun travail
serveur à annoncer. Une notification serait envoyée par le client à lui-même,
pour l'informer de ce qu'il vient de faire.

**Vérifié**

442 tests backend (16 pour cette phase). La cloche a été ouverte dans un vrai
Chrome, avec une notification réelle : la pastille compte, le panneau affiche le
numéro de reçu, et l'ouverture marque comme lu.

---

## Hors phase — le fond d'écran du compte

À la demande du club : **chaque membre choisit l'image de fond de son compte.**

La photo de profil dit qui l'on est aux autres ; le fond d'écran dit à quoi
ressemble SON application quand on l'ouvre. Un membre qui met la corniche au
lever du jour derrière ses anneaux s'approprie l'outil — et un outil qu'on
s'approprie s'ouvre plus souvent.

Sur `members` et non sur `users` : un adhérent sans compte de connexion a une
fiche, et rien ne justifie de le priver d'une image que quelqu'un aurait pu
choisir pour lui. Disque public, contrairement aux justificatifs de dépense :
c'est un décor, servi à chaque chargement d'écran, et le faire passer par une
route authentifiée coûterait une requête PHP par affichage pour protéger une
photo de paysage.

L'aperçu montre l'image **avec le voile** que l'application applique. Choisir sur
une vignette claire pour découvrir ensuite que l'image passe sous un dégradé
sombre fait recommencer trois fois.

`cyclo:promote` complète l'ensemble : attribuer un rôle depuis la console, avec
révocation des jetons et trace d'audit — exactement ce que fait l'API. Il faut
bien un premier administrateur, et pouvoir en rétablir un le jour où le seul
compte super administrateur est perdu.

---

## ✅ Phase 18 — Tests *(terminée)*

Les deux priorités de la phase étaient nommées : l'algorithme GPS sur traces
réelles, et les cinq invariants financiers. Les voici — plus deux trous que
cette phase a mis au jour.

**Des traces RÉELLES, et ce qu'elles ont révélé**

Quatre enregistrements d'un téléphone réel, à Dakar, sont désormais figés comme
fixtures : une sortie vélo de 7 km et 1 379 points, et les trois essais de
marche qui avaient produit le signalement « les mètres ne sont pas pris ».

Les traces fabriquées ont bien servi — elles isolent un phénomène et se règlent
à volonté — mais elles ressemblent à ce qu'on CROIT que fait un GPS. Une vraie
trace porte les irrégularités qu'on n'aurait pas pensé à simuler.

**Et elles ont tranché une question restée ouverte.** Les trois essais de marche
mesurent zéro, et c'est la bonne réponse : leur chemin brut fait 35 à 43 m, mais
l'excursion maximale depuis le départ n'est que de 7 à 13 m, pour une précision
annoncée de 4 à 8 m. La personne a fait quelques pas et est revenue, dans un
rayon à peine plus grand que l'incertitude de son propre récepteur. Les 35 m de
« chemin » sont, pour l'essentiel, du bruit.

La conséquence est dite franchement dans le test : **un aller-retour de moins
d'une quinzaine de mètres n'est pas mesurable par un téléphone.** Ce n'est pas un
défaut qu'on peut régler, c'est la limite de l'instrument. Pour éprouver la
marche, il faut marcher en ligne, sur cinquante mètres au moins.

`cyclo:export-trace` fige n'importe quelle trace en fixture : le jour où le club
signale un nouveau défaut, la première chose à faire est de conserver
l'enregistrement qui l'a produit, avant qu'il ne soit recalculé ou perdu.

**Les cinq invariants, rassemblés et nommés**

Ils étaient éprouvés — mais en morceaux, à travers quatre fichiers. Un invariant
vérifié en pièces détachées n'est plus lisible comme un invariant : personne ne
pouvait répondre à « la règle I2 est-elle vérifiée ? » sans relire tout le
dossier. Chaque règle porte maintenant son nom, sa formulation et sa preuve.

**Deux d'entre elles sont vérifiées STRUCTURELLEMENT, pas par l'exemple.**

I5 lit le schéma de la base : un exemple ne peut prouver qu'aucune colonne
monétaire n'est un flottant, il prouve seulement que celles auxquelles on a
pensé ne le sont pas. Celle qu'on ajoutera dans six mois, en `decimal(10,2)` par
réflexe, passerait sans que rien ne crie. Même chose pour I1 et I2, qui lisent la
table des routes plutôt que trois URL choisies à la main.

**Le web n'avait aucun test — c'était le trou le plus sérieux**

`web/src/lib/recording.ts` est le code qui tourne dans le navigateur du membre
pendant sa sortie. C'est lui qui a produit les deux signalements GPS, et il
n'avait aucun test automatisé. Le serveur recalcule tout à l'arrivée et fait
foi — mais entre le départ et l'arrivée, un compteur qui ment est la seule chose
que le membre voit.

Vitest en environnement `node`, et non jsdom : ce qui est testé ici est de la
logique, pas du rendu. Le rendu, lui, est vérifié dans un vrai Chrome — jsdom ne
calcule aucune mise en page et ne dirait rien de ce qui plante à l'affichage.

Les mêmes fixtures réelles y passent, lues **à leur emplacement d'origine** :
les dupliquer aurait créé une seconde vérité qui aurait divergé au premier
ré-export.

**Les fichiers miroirs se surveillent enfin tout seuls**

`CLAUDE.md` demande de tenir quatre paires de fichiers identiques à la main.
Ce n'est pas une crainte théorique : c'est arrivé deux fois. Le seuil du vélo
est resté à 1 m côté client quand le serveur utilisait 5 — le compteur affiché
ne correspondait pas au résultat final. Puis le seuil d'immobilité a été abaissé
sur le web avant de l'être sur le mobile : la même promenade aurait compté sur un
appareil et pas sur l'autre.

Ces divergences sont invisibles à la relecture. Une machine, elle, ne glisse pas
sur un `0.8` qui aurait dû être `0.3`. Les clients sont comparés au SERVEUR, pas
l'un à l'autre — sans quoi ils pourraient être d'accord tous les deux, et tous
les deux faux.

Le test a été éprouvé en cassant volontairement une valeur : il échoue en
nommant la ligne exacte. **Un test qui passe sans rien vérifier est pire qu'un
test absent — il rassure.**

**Total**

| Tier | Tests | Commande |
|---|---|---|
| Backend | 465 | `cd backend && php artisan test` |
| Mobile | 65 | `cd mobile && npx jest` |
| Web | 7 | `cd web && npm test` |

Auxquels s'ajoute la vérification de rendu dans un vrai Chrome, qui couvre les
dix écrans et le parcours de connexion.

---

## ✅ Phase 19 — Sécurité et données personnelles *(terminée, sauf OpenAPI)*

**L'audit des permissions est fait par la machine.**

Une relecture à l'œil protège mal : les routes sont soixante-dix et grossissent
à chaque phase. Le test énumère celles qui sont réellement enregistrées et
vérifie que chacune exige une session, contrôle que le compte est actif, et
n'expose aucune clé primaire interne — un identifiant séquentiel se devine, et
`/members/1`, `/members/2`… permettrait d'énumérer l'annuaire.

Il se garde lui-même : il échoue s'il voit moins de soixante routes, sinon un
préfixe renommé le ferait passer sans rien vérifier.

Résultat : **aucune route n'était ouverte par erreur.** L'audit n'a rien trouvé,
et c'est un résultat — désormais vérifié à chaque exécution plutôt que cru sur
parole.

**Un trou réel : l'EXIF des photos**

Une photo prise au téléphone porte les coordonnées GPS du lieu de la prise de
vue. Un membre qui envoie sa photo de profil prise chez lui publiait donc son
adresse, sur un disque public, sans jamais l'avoir su. C'est exactement ce que
`docs/risques.md` interdit pour les traces GPS.

L'effacement se fait par **ré-encodage** : GD ne recopie aucune métadonnée, si
bien que l'image sortante ne porte que des pixels. Une liste de champs à effacer
finirait par en oublier un.

Le piège était l'**orientation** : un téléphone note « tourne-la de 90° avant
d'afficher » dans l'EXIF, et l'effacer sans appliquer cette rotation d'abord
ferait sortir toutes les photos verticales couchées. L'extension `exif` a dû
être activée sur le serveur pour cela.

Au passage, les images sont réduites à 1 024 px (photo) ou 1 920 px (fond) :
servir huit mégaoctets pour un avatar de 72 pixels, sur des forfaits mobiles
sénégalais, est un gaspillage que le club paie deux fois.

**Le RGPD, avec sa ligne de partage assumée**

Un membre peut emporter tout ce que le club détient sur lui — compte, fiche,
sorties avec leur trace, cotisations, reçus, défis, notifications.

Il peut faire effacer ce qui ne concerne que lui. Il ne peut pas faire effacer
les écritures comptables auxquelles il a participé : elles engagent la caisse du
club et figurent dans des rapports peut-être déjà présentés en assemblée. Elles
sont donc **anonymisées** — le montant reste, le nom disparaît.

**L'écran le dit avant de demander confirmation**, pas après. Le découvrir après
coup ferait croire à un mensonge ; le dire avant, c'est le respect qu'on doit à
quelqu'un qui s'en va.

La suppression exige le mot de passe **et** le mot « SUPPRIMER » tapé en toutes
lettres : c'est le seul endroit de l'application où un téléphone laissé
déverrouillé sur une table permettrait de détruire un compte en deux appuis.

**Le journal d'audit, enfin lisible**

Il existait depuis la phase 3 et personne n'avait jamais pu le lire — il fallait
ouvrir la base. Un journal qu'on ne peut pas lire ne protège personne : il donne
le sentiment d'être protégé, ce qui est pire, car on renonce alors à d'autres
contrôles.

Administration seulement. **Le trésorier n'y a pas accès : il est la personne que
ce journal surveille.** En lecture seule, sans aucune route d'écriture — un
journal qu'on peut retoucher ne prouve rien.

**Ce qui n'est PAS livré : le schéma OpenAPI**

La roadmap le demandait. Il n'est pas fait, et je préfère le dire que cocher la
case.

`docs/api.md` documente déjà chaque route livrée, avec ses paramètres, ses codes
d'erreur et — surtout — les raisons de ses choix, ce qu'un schéma généré ne
porterait pas. Ajouter une seconde description, générée automatiquement, créerait
une source de vérité de plus à tenir d'accord avec la première : exactement le
problème que les fichiers miroirs ont déjà posé deux fois dans ce projet.

Un OpenAPI a du sens le jour où un client TIERS consomme l'API. Aujourd'hui les
deux seuls clients sont dans ce dépôt et partagent leurs types TypeScript. Le
faire maintenant serait de la machinerie sans besoin mesuré — à reprendre en
phase 20 si le club ouvre son API.

**Vérifié**

484 tests backend (19 pour cette phase), et douze écrans rendus dans un vrai
Chrome. Le harnais de rendu a d'ailleurs été corrigé au passage : il attendait
un délai fixe, et se mettait à échouer le jour où un écran avait vraiment
quelque chose à afficher — le rapport financier l'a fait tomber. Il attend
maintenant la fin du chargement.

---

## ✅ Phase 20 — Déploiement *(terminée)*

**Un déploiement, pas une démonstration.**

`demarrer-demo.ps1` existait déjà et lançait `php artisan serve` sur le dossier
de travail. Le déploiement installe l'application **ailleurs**, avec sa propre
base, son propre compte MySQL et ses propres caches.

Ce n'est pas de la propreté : `config:cache` fige les valeurs du `.env` dans un
fichier PHP, et fait dans le dossier de développement il ferait tourner **la
suite de tests sur la base de production** — phpunit surcharge des variables
d'environnement qu'une configuration mise en cache ne consulte plus. Le dossier
séparé est ce qui rend les caches sûrs.

**La sonde de santé savait mentir**

Elle ne vérifiait que la base et le stockage. Elle disait donc « tout va bien »
avec un ouvrier de file mort : les rappels de cotisation cessaient de partir, et
on ne l'aurait découvert qu'en s'étonnant, des semaines plus tard, que plus
personne ne paie. C'est le défaut du journal d'audit de la phase 19, transposé à
la production — une garde à laquelle on se fie sans qu'elle garde quoi que ce
soit.

Elle surveille maintenant la file et le planificateur, avec **deux niveaux de
panne**. Base ou stockage en échec : 503, l'application ne peut rien servir.
File ou planificateur arrêtés : 200 et `degraded`, parce que les écrans marchent
et qu'un 503 ferait redémarrer un serveur en parfait état.

Un planificateur arrêté ne peut pas signaler son propre arrêt : il écrit donc un
battement chaque minute, et c'est son **absence** qui le trahit. Deux pièges
évités au passage — une file pleine qui avance va très bien (alerter là-dessus
crierait au loup à chaque envoi groupé), et un travail différé n'est pas un
travail en retard.

**Le bug que seul un vrai test pouvait trouver**

Le déploiement passait tous ses contrôles, et **plus aucun membre ne pouvait
utiliser l'application**. Apache ne transmet pas l'en-tête `Authorization` aux
programmes FastCGI depuis la version 2.4.13 : il porte des mots de passe, et il
faut le lui autoriser explicitement (`CGIPassAuth On`).

Le symptôme est trompeur. La connexion **réussit** et rend un jeton, puis chaque
appel suivant répond 401. On soupçonne le jeton, le mobile, l'horloge — alors que
le serveur ne l'a jamais reçu.

La sonde de santé, elle, est une route publique : elle répondait parfaitement.
D'où l'ajout, dans le script, d'une vérification qu'aucune sonde publique ne
remplace : **un appel authentifié doit être servi, et une route protégée doit
refuser sans jeton**. Un déploiement qui ne teste que des routes publiques ne
teste pas l'application.

**Une alerte franche : le code source servi en clair**

Une tentative de répartition de charge par `<If>` a servi, lorsqu'aucune
condition ne correspondait, **`index.php` en clair avec un code 200**. Un
aiguillage conditionnel vers PHP a pour cas de repli « fichier statique ». La
règle qui envoie le `.php` à PHP est donc restée inconditionnelle, et la
répartition a été abandonnée.

**La limite, dite plutôt que maquillée**

Sous Windows, `php-cgi` ne sait pas se dupliquer et le répartiteur d'Apache
refuse de relayer du FastCGI vers un chemin Windows. **Une requête PHP à la
fois** — mesuré, pas supposé : huit requêtes en parallèle prennent exactement le
même temps qu'en série, environ 120 ms chacune.

Cela tient pour un club, Apache servant seul les fichiers statiques qui font
l'essentiel du trafic. La vraie réponse est PHP-FPM sur un hébergement Linux, où
le problème n'existe pas — et où tout le reste du document s'applique tel quel.

**Les sauvegardes, avec la seule vérification qui compte**

« Une sauvegarde jamais restaurée n'est pas une sauvegarde » était écrit dans la
documentation depuis la phase 1. `sauvegarde.ps1 -Verifier` **rejoue réellement
l'archive** dans une base jetable et compare les nombres de lignes, table par
table. Un `mysqldump` interrompu produit un fichier volumineux et inutilisable :
sa taille ne dit rien, et l'erreur n'apparaît qu'au moment où l'on en a besoin.

Le `.env` est exclu des archives : il porte le mot de passe de la base, et une
archive finit un jour sur une clé USB.

**Ce qui reste à faire, et qui ne dépend pas de moi**

Un hébergement permanent suppose un compte chez un hébergeur, donc une
inscription au nom du club. L'application n'a aucune dépendance à Windows : sur
n'importe quel serveur Linux avec PHP 8.3, MySQL et Nginx, les sections 1 à 9 de
`docs/deployment.md` s'appliquent telles quelles.

Les builds mobiles (`eas build`) demandent de même un compte Expo, et iOS un
compte Apple Developer payant. La diffusion d'un APK aux membres, elle, ne
demande rien — c'est souvent le plus pragmatique pour un club.

**Vérifié**

499 tests backend, et le déploiement éprouvé de bout en bout à travers son
adresse publique : sonde `healthy` sur ses quatre contrôles, connexion réelle,
appel authentifié servi, route protégée refusée sans jeton, `.env` inaccessible
(403), et la page de connexion rendue dans un vrai Chrome.

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
