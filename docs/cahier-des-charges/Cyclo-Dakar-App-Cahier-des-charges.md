# Cyclo Dakar — Application de suivi d'activités sportives
## Analyse, Cahier des charges et Prompt de conception

---

## 1. Analyse du besoin

### 1.1 Contexte
Le club **Cyclo Dakar** pratique principalement le vélo, mais aussi la randonnée et la course à pied. Actuellement, les membres utilisent des applications tierces (Strava/Forme, Relive) pour mesurer distance, vitesse, temps, et pour générer des vidéos récapitulatives de leurs sorties. L'objectif est de disposer d'une **application propre au club**, qui centralise ces fonctions et renforce l'identité et la cohésion du groupe.

### 1.2 Pourquoi une application propre au club ?
- **Indépendance** : ne plus dépendre des limites gratuites de Strava/Relive (nombre de vidéos, publicités, abonnements payants).
- **Communauté** : classements internes, événements de club, défis entre membres, sorties groupées.
- **Image de marque** : logo, couleurs, nom du club visibles dans chaque vidéo/partage.
- **Données maîtrisées** : le club garde le contrôle sur les données de ses membres (RGPD, confidentialité, export).

### 1.3 Analyse concurrentielle rapide
| Application | Points forts | Limites |
|---|---|---|
| Strava | Communauté large, segments, classements | Payant pour fonctions avancées, pas personnalisable |
| Relive | Vidéo 3D du parcours très qualitative | Vidéo seule, peu de gestion de club |
| Komoot | Bonne planification d'itinéraires | Peu orienté performance/vidéo |
| **Cyclo Dakar App (à créer)** | Vidéo type Relive + gestion club + 100% adapté aux besoins | À développer |

### 1.4 Fonctionnalité phare à répliquer (type Relive)
Une **vidéo accélérée** qui suit le tracé GPS sur une carte animée (vue aérienne/3D), avec incrustation en overlay de : distance parcourue en temps réel, vitesse, temps écoulé, dénivelé, et éventuellement photos prises pendant la sortie.

---

## 2. Cahier des charges

### 2.1 Objectifs du projet
1. Permettre à chaque membre d'enregistrer ses sorties (vélo, course, randonnée) via GPS.
2. Générer automatiquement une vidéo récapitulative animée du parcours (façon Relive).
3. Centraliser les statistiques individuelles et collectives du club.
4. Favoriser l'engagement communautaire (classements, défis, événements).

### 2.2 Public cible
- Membres du club Cyclo Dakar (cyclistes principalement, coureurs et randonneurs occasionnels).
- Niveau technique varié → interface simple, en français, adaptée à un usage mobile terrain (parfois zones à connectivité faible).

### 2.3 Fonctionnalités — Périmètre MVP (version minimale viable)

**A. Enregistrement d'activité**
- Démarrer / mettre en pause / arrêter un enregistrement GPS.
- Choix du type d'activité : vélo, course à pied, randonnée (extensible).
- Suivi en temps réel : distance, vitesse instantanée, vitesse moyenne, temps écoulé, allure (pour la course).
- Enregistrement du tracé GPS (latitude/longitude/altitude/horodatage à intervalles réguliers).
- Fonctionnement en arrière-plan (écran verrouillé) et en mode faible connectivité (le tracé est stocké localement puis synchronisé).

**B. Génération de la vidéo façon Relive**
- Après l'activité, génération automatique d'une vidéo courte (30s à 2 min) :
  - Carte animée retraçant le parcours en accéléré (vue satellite ou vectorielle).
  - Overlay dynamique : distance cumulée, vitesse, temps, dénivelé.
  - Marqueurs sur les zones/points passés (POI : sommet, pause, ravitaillement, zone dangereuse, point de vue).
  - Possibilité d'insérer des photos prises pendant l'activité aux endroits correspondants.
  - Export/partage (WhatsApp, Instagram, Facebook) et enregistrement dans la galerie du téléphone.

**C. Statistiques et historique**
- Fiche détaillée par activité (carte, graphique de vitesse/dénivelé, distance, durée, calories estimées).
- Historique personnel filtrable (par type d'activité, période).
- Statistiques cumulées (distance totale du mois/année, dénivelé total, nombre de sorties).

**D. Fonctionnalités club (différenciant clé)**
- Profil de membre avec photo, statistiques, badge d'ancienneté.
- Fil d'actualité du club (activités récentes des membres, façon réseau social).
- Classements internes (distance mensuelle, dénivelé, nombre de sorties) — configurables par l'admin du club.
- Création d'événements/sorties groupées avec inscription des membres.
- Notifications (nouvelle sortie créée, un membre a battu un record, rappel d'événement).

### 2.4 Fonctionnalités avancées (Version 2 / évolutions)
- Défis et badges (ex : "1000 km en un mois", "10 sorties consécutives").
- Segments comparatifs type Strava (comparer son temps sur un tronçon connu avec les autres membres).
- Mode hors-ligne complet avec cartes téléchargées à l'avance.
- Intégration capteurs externes (cardiofréquencemètre Bluetooth, capteur de cadence).
- Export des données (GPX, FIT, CSV).
- Tableau de bord admin club (gestion des membres, modération, statistiques globales).
- Multi-langue (français / wolof / anglais).

### 2.5 Exigences non-fonctionnelles
- **Plateformes** : Application mobile iOS + Android (privilégier un framework cross-platform : Flutter ou React Native).
- **Performance** : suivi GPS fiable même en cas de connexion intermittente ; faible consommation de batterie.
- **Confidentialité / RGPD** : consentement explicite pour la géolocalisation, possibilité de rendre une activité privée, suppression de compte et des données sur demande.
- **Scalabilité** : architecture backend capable de supporter la croissance du club (au-delà de Dakar, potentiellement d'autres clubs).
- **Sécurité** : authentification sécurisée (email/téléphone + mot de passe ou OAuth), chiffrement des données sensibles.
- **Ergonomie** : interface simple, utilisable avec des gants ou en mouvement, gros boutons pour start/stop.

### 2.6 Architecture technique proposée (pistes)
- **Frontend mobile** : Flutter (un seul code pour iOS/Android) ou React Native.
- **Backend** : Node.js (NestJS/Express) ou Django (Python), API REST ou GraphQL.
- **Base de données** : PostgreSQL avec extension PostGIS (adaptée aux données géospatiales).
- **Cartographie** : Mapbox ou OpenStreetMap (moins coûteux) pour l'affichage et le rendu des cartes.
- **Génération vidéo** : rendu côté serveur (FFmpeg + génération de frames à partir du tracé GPS) ou moteur de rendu type Mapbox GL + capture d'animation.
- **Stockage fichiers/vidéos** : service cloud (S3, Firebase Storage, ou équivalent).
- **Notifications** : Firebase Cloud Messaging.
- **Hébergement** : cloud (OVH, AWS, ou hébergeur local sénégalais/africain selon budget et latence).

### 2.7 Jalons / Roadmap indicative
1. **Phase 1 (MVP – 2-3 mois)** : enregistrement GPS + statistiques de base + historique + profil membre.
2. **Phase 2 (1-2 mois)** : génération vidéo type Relive + partage.
3. **Phase 3 (1-2 mois)** : fonctionnalités club (classements, événements, fil d'actualité).
4. **Phase 4** : fonctionnalités avancées (défis, segments, hors-ligne, capteurs).

### 2.8 Module de gestion de caisse / trésorerie du club (nouvelle fonctionnalité)

**Problème constaté** : la personne chargée de collecter les participations (le caissier) rencontre parfois des difficultés à écrire correctement les noms des participants (erreurs d'orthographe, illisibilité, doublons). Il n'y a pas non plus de vision claire et en temps réel du solde de la caisse (participations encaissées moins dépenses effectuées).

**Objectif** : digitaliser la gestion financière du club (cotisations/participations aux sorties ou événements, dépenses) avec un contrôle et une traçabilité complets.

**A. Participations (entrées d'argent)**
- Chaque événement/sortie peut avoir une **participation financière associée** (ex : cotisation pour une sortie, frais d'inscription à un événement).
- Le **participant envoie l'argent** (par un moyen convenu par le club : Wave, Orange Money, espèces remises en main propre, etc.), puis **fait une demande de validation dans l'application** en précisant : son nom (sélectionné dans la liste des membres enregistrés — évite les erreurs de saisie manuelle), le montant, l'événement/motif concerné, et éventuellement une preuve de paiement (capture d'écran/reçu à joindre).
- Le membre est **sélectionné depuis la liste des membres du club** (et non saisi en texte libre), ce qui élimine les problèmes d'orthographe et de doublons rencontrés jusqu'ici.

**B. Validation par le caissier**
- Le **caissier/trésorier** reçoit une notification de chaque nouvelle demande de participation.
- Il consulte la demande (nom du membre, montant, preuve de paiement) et peut :
  - **Approuver** → la participation est validée, le montant est automatiquement ajouté au solde de la caisse, et le statut passe à "Validé".
  - **Rejeter** → avec un motif (ex : montant incorrect, preuve manquante), le participant est notifié et peut refaire sa demande.
- Tant qu'une demande n'est pas approuvée, elle reste en statut **"En attente"** et n'impacte pas le solde de la caisse (évite les doubles comptages ou erreurs).

**C. Dépenses (sorties d'argent)**
- Le caissier (ou un rôle autorisé, ex : trésorier/bureau) peut **enregistrer une dépense** : montant, motif (ex : achat de matériel, location de salle, ravitaillement), date, justificatif (photo de facture/reçu optionnelle).
- Chaque dépense validée **diminue automatiquement le solde de la caisse**.
- Possibilité de catégoriser les dépenses (matériel, événements, communication, autres) pour faciliter les rapports.

**D. Vision globale de la caisse**
- **Tableau de bord caisse** accessible au caissier/trésorier et aux membres du bureau (accès en lecture pour tous les membres si le club le souhaite, pour plus de transparence) :
  - Solde actuel en caisse (mis à jour automatiquement).
  - Total des participations validées (par période, par événement).
  - Total des dépenses (par période, par catégorie).
  - Historique complet des transactions (entrées et sorties) avec date, auteur, statut.
- **Filtres** par période (semaine/mois/année), par événement, par membre.
- **Export** des mouvements de caisse (PDF/Excel/CSV) pour les rapports du club ou les assemblées générales.

**E. Rôles et permissions liés à la caisse**
- **Membre** : peut soumettre une demande de participation, voir l'historique de ses propres participations, et consulter le solde global si autorisé.
- **Caissier/Trésorier** : peut approuver/rejeter les demandes de participation, enregistrer les dépenses, consulter tout l'historique et exporter les rapports.
- **Admin du club** : accès complet, peut nommer/changer le rôle de caissier, superviser toutes les opérations.
- Toute action (validation, rejet, dépense) doit être **horodatée et associée à l'utilisateur qui l'a effectuée** (traçabilité/audit).

**F. Notifications liées à la caisse**
- Au participant : confirmation de soumission de sa demande, notification d'approbation ou de rejet.
- Au caissier : alerte à chaque nouvelle demande en attente.
- Optionnel : rappel automatique si une demande reste en attente trop longtemps.

### 2.9 Livrables attendus
- Application mobile (iOS + Android) publiée sur les stores ou en distribution interne (APK/TestFlight).
- Backend et base de données hébergés.
- Documentation technique et guide d'utilisation pour les membres du club, incluant le fonctionnement du module de caisse.

---

## 3. Prompt complet (à utiliser avec un développeur ou un outil d'IA de développement)

> **Prompt à copier-coller :**
>
> Je souhaite concevoir une application mobile de suivi d'activités sportives pour un club nommé **"Cyclo Dakar"**, pratiquant principalement le vélo, ainsi que la course à pied et la randonnée. L'application doit remplacer l'usage combiné de Strava/Forme et Relive.
>
> **Fonctionnalités principales à implémenter :**
>
> 1. **Enregistrement d'activité GPS** : start/pause/stop, choix du type d'activité (vélo, course, rando), suivi en temps réel de la distance, vitesse instantanée et moyenne, temps écoulé, allure, dénivelé positif/négatif, fonctionnement en arrière-plan et tolérant aux coupures réseau (stockage local + synchronisation différée).
>
> 2. **Génération automatique d'une vidéo récapitulative animée du parcours**, à la manière de l'application Relive : une carte qui se retrace en accéléré (time-lapse) du départ à l'arrivée, avec incrustation d'informations en overlay (distance cumulée, vitesse, temps, dénivelé), marquage visuel des zones/points d'intérêt passés (sommet, pause, ravitaillement, point de vue, danger), insertion optionnelle de photos prises pendant l'activité aux endroits GPS correspondants, et export/partage vers réseaux sociaux ou galerie du téléphone.
>
> 3. **Historique et statistiques** : fiche détaillée par activité (carte du tracé, graphiques vitesse/dénivelé/allure, distance, durée, calories estimées), historique filtrable, cumuls (distance/dénivelé/nombre de sorties par semaine/mois/année).
>
> 4. **Fonctionnalités communautaires/club** : profils de membres, fil d'actualité des activités du club, classements internes configurables (distance, dénivelé, nombre de sorties), création et inscription à des événements/sorties groupées, notifications (nouvelle sortie, record battu, rappel).
>
> 5. **Fonctionnalités avancées à prévoir pour une V2** : défis et badges, segments comparatifs entre membres, mode hors-ligne avec cartes téléchargées, intégration de capteurs Bluetooth externes (cardiofréquencemètre, capteur de cadence), export des données au format GPX/FIT/CSV, tableau de bord d'administration pour les responsables du club.
>
> 6. **Module de gestion de caisse/trésorerie** : possibilité pour chaque événement ou sortie d'avoir une participation financière associée ; un participant qui a envoyé de l'argent (Wave, Orange Money, espèces, etc.) soumet une demande de participation dans l'application en sélectionnant son nom dans la liste des membres enregistrés (pour éviter les erreurs de saisie manuelle des noms), en indiquant le montant et éventuellement une preuve de paiement ; le caissier/trésorier reçoit une notification, consulte la demande et peut l'approuver (le montant est alors ajouté automatiquement au solde de la caisse) ou la rejeter avec motif ; le caissier peut également enregistrer des dépenses (montant, motif, justificatif) qui diminuent automatiquement le solde ; un tableau de bord affiche en temps réel le solde de la caisse, le total des participations validées, le total des dépenses, et l'historique complet des transactions avec traçabilité (auteur, date, statut) ; export des rapports en PDF/Excel/CSV ; gestion de rôles (membre, caissier/trésorier, admin) avec permissions différenciées.
>
> **Contraintes techniques :**
> - Application mobile multiplateforme (iOS et Android), idéalement en Flutter ou React Native.
> - Backend avec base de données géospatiale (PostgreSQL + PostGIS recommandé).
> - Utilisation d'une solution de cartographie (Mapbox ou OpenStreetMap) pour l'affichage et pour la génération des vidéos animées.
> - Respect de la confidentialité des données de géolocalisation (consentement explicite, activités privées possibles, suppression de compte sur demande — conformité type RGPD).
> - Interface simple et lisible, utilisable en mouvement (gros boutons, lecture facile en plein soleil).
> - Faible consommation de batterie et de données mobiles pendant l'enregistrement GPS.
>
> **Livrables attendus :** architecture technique détaillée, modèle de base de données, maquettes des écrans principaux (accueil, enregistrement d'activité, résumé d'activité avec vidéo, profil, classement club, fil d'actualité), et un plan de développement par phases (MVP puis fonctionnalités avancées).
>
> Merci de proposer une architecture technique détaillée, un schéma de base de données, et de commencer par le développement du MVP en priorisant : enregistrement GPS, statistiques de base, et génération de la vidéo animée du parcours.

---

### Notes finales
- Pensez à valider ce cahier des charges avec le bureau du club avant de le transmettre à un développeur/agence, notamment sur le **budget disponible** et le **délai souhaité**, deux éléments non fixés ici.
- La fonctionnalité vidéo (point 2) est techniquement la plus complexe et la plus coûteuse en développement : il peut être pertinent de commencer le MVP sans elle, en l'ajoutant en phase 2, pour sortir une première version plus rapidement.
