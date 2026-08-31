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

## ⏳ Phase 2 — Authentification

Inscription, connexion (email **ou** téléphone), déconnexion, mot de passe oublié,
changement de mot de passe, tokens Sanctum, `users.role`, middleware de rôle,
protection des routes web et mobile, écrans de connexion.

*Dépend de : phase 1.*

---

## ⏳ Phase 3 — Membres, rôles et permissions

Modèle `members`, matricule automatique `CD-000001` généré sous verrou, photo,
statuts, recherche multi-critères (nom, prénom, téléphone, matricule), tables RBAC,
Policies Laravel, CRUD web.

---

## ⏳ Phase 4 — Interface web

Coquille applicative (barre latérale, en-tête, navigation par rôle), tableau de bord
administrateur, composants réutilisables, responsive complet, mode sombre.

---

## ⏳ Phase 5 — Interface mobile

Navigation (`@react-navigation`), écrans Accueil, Profil, Historique, splash,
gestion de session, sélecteur de thème.

---

## ⏳ Phase 6 — GPS et tracking *(phase la plus délicate)*

Capture en arrière-plan (`expo-task-manager` + service de premier plan Android),
filtre en cascade à 6 tests, calcul distance/vitesse/dénivelé, pause et reprise,
stockage SQLite local, synchronisation par lots idempotente, recalcul serveur.

⚠️ Nécessite une **Development Build** Expo (Expo Go ne gère pas la localisation en
arrière-plan). Voir [gps.md](gps.md) et [risques.md](risques.md) §A et §B.

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
