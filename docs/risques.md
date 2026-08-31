# Analyse des risques — Cyclo Dakar

Les trois familles de risque demandées au § 61 du cahier des charges, avec pour chacune
la parade **déjà prévue dans l'architecture** et la phase où elle est implémentée.

---

## A. Risques GPS

| # | Risque | Impact | Parade | Phase |
|---|---|---|---|---|
| G1 | **Dérive urbaine** : à Dakar, les immeubles des Almadies / du Plateau renvoient le signal (multipath). Le point « saute » de 50–200 m. | Distance surévaluée de 10–30 %. Le membre ne fait plus confiance à l'app. | Filtre en cascade : rejet si `accuracy > 25 m`, rejet si vitesse implicite > seuil du sport, lissage Kalman 1D sur la vitesse. Voir [gps.md](gps.md). | 6 |
| G2 | **Premier point aberrant** : au démarrage, l'OS renvoie la dernière position connue (parfois la veille, à 20 km). | Premier segment de 20 km fantôme. | On ignore tout point dont `timestamp` est antérieur de plus de 10 s au démarrage, et on impose une phase d'acquisition (3 points consécutifs avec `accuracy < 20 m`) avant de démarrer le chrono. | 6 |
| G3 | **Altitude GPS bruitée** : l'altitude GPS a une erreur de ±10–15 m. Sommée sur 5 000 points, elle fabrique un dénivelé de +2 000 m sur un parcours plat. | Dénivelé absurde. Dakar est quasi plat : un +2 000 m serait immédiatement décrédibilisant. | Seuil d'hystérésis : une variation d'altitude n'est comptée que si elle dépasse **3 m** cumulés dans la même direction, après lissage sur fenêtre glissante de 5 points. Le baromètre est utilisé s'il est disponible. | 6 |
| G4 | **Pause non détectée** (feu rouge, ravitaillement) | Vitesse moyenne fausse. | Distinction `duration_s` / `moving_time_s` : un point sous 0,8 m/s ne compte pas comme temps actif. Pause manuelle marquée `is_paused` et exclue du calcul de distance. | 6 |
| G5 | **Points dupliqués** lors d'un rejeu de synchronisation | Distance doublée. | `UNIQUE(activity_id, seq)` en base : le rejeu est absorbé silencieusement (`INSERT IGNORE`). | 6 |
| G6 | **Batterie** : GPS à 1 Hz + écran allumé = 15–20 %/h. Une sortie de 3 h peut vider le téléphone. | L'activité s'arrête toute seule. | Fréquence adaptative (1 s en vélo, 3 s en randonnée), écran éteignable sans perte, aucun envoi réseau point par point, carte non re-rendue à chaque point. | 6 / 50 |
| G7 | **Android tue le process** (Doze, gestionnaires de batterie agressifs Xiaomi/Oppo/Tecno — très répandus à Dakar) | Perte de la fin de la sortie. | `expo-task-manager` + notification permanente de premier plan (foreground service). Écran d'accueil qui invite explicitement à désactiver l'optimisation batterie pour l'app. Écriture SQLite **à chaque lot**, jamais uniquement en mémoire. | 6 |
| G8 | Le client envoie des statistiques falsifiées (classement) | Triche sur les classements. | Le serveur **recalcule tout** à `finalize` depuis les points bruts. Les stats envoyées par le client sont ignorées. | 6 |

---

## B. Risques du mode hors ligne

| # | Risque | Impact | Parade | Phase |
|---|---|---|---|---|
| O1 | **Perte de données si l'app crashe** | Sortie perdue = utilisateur perdu. | SQLite en écriture immédiate (WAL). L'activité est reconstructible au redémarrage : au lancement, toute activité `RECORDING` non finalisée est proposée en reprise. | 6 |
| O2 | **Double envoi** après reprise de réseau | Activité en double dans l'historique. | L'`uuid` d'activité est **généré par le client** et sert de clé d'idempotence : `POST /activities` avec un uuid connu renvoie `200` + la ressource existante, pas `201`. | 6 |
| O3 | **Synchronisation partielle interrompue** (réseau intermittent : le cas normal sur la Corniche) | État incohérent, points manquants. | Envoi par lots de 100 points avec `seq` explicite ; le serveur répond avec `last_seq_received` ; le client ne supprime localement que ce qui est accusé réception. La finalisation est refusée tant que tous les lots ne sont pas arrivés. | 6 |
| O4 | **Conflit** : la même activité modifiée sur deux appareils | Écrasement silencieux. | `updated_at` côté serveur fait autorité pour les métadonnées (titre, visibilité) ; les points GPS sont *append-only* donc non conflictuels par construction. | 6 |
| O5 | **Stockage local saturé** (téléphones d'entrée de gamme 32 Go) | Échec d'écriture silencieux. | Purge locale des activités synchronisées de plus de 7 jours ; alerte utilisateur sous 200 Mo libres. | 6 |
| O6 | Le collecteur encaisse hors ligne sur le terrain | Risque financier (voir F5). | **Décision : les paiements ne fonctionnent PAS hors ligne** en v1. Le formulaire est bloqué sans réseau, avec un message explicite. Une file d'attente de paiements hors ligne est reportée après un audit dédié. | 12 |

---

## C. Risques financiers

| # | Risque | Impact | Parade | Phase |
|---|---|---|---|---|
| F1 | **Le solde devient faux** (mise à jour concurrente, crash au milieu d'une opération) | Perte de confiance totale dans le module — le cœur du besoin exprimé par le club. | Le solde n'est **jamais** une donnée écrite indépendamment : il est la somme de `financial_transactions`. `current_balance` est un cache vérifié par `php artisan finance:recompute-balance`. | 13 |
| F2 | **Double encaissement** (double-clic, retry réseau) | Le membre est débité deux fois dans le journal. | `idempotency_key` unique sur `payments`, généré par le client. Le second appel renvoie le paiement existant. | 12 |
| F3 | **Paiement supérieur au dû** ou négatif | Solde faux, litige. | Validation serveur : `0 < amount ≤ reste_dû`. Un dépassement est refusé (422) et doit passer par une recette « CONTRIBUTION » explicite. | 12 |
| F4 | **Suppression d'une opération** pour « corriger une erreur » | Trou dans le journal, audit impossible. | Aucune route de suppression. `payments` et `financial_transactions` sont *append-only*. Une erreur se corrige par **contre-passation** (`reverses_transaction_id`) avec motif obligatoire. | 12–13 |
| F5 | **Le client dicte le solde** | Fraude triviale. | Aucune route n'accepte de solde, ni `collected_by`, ni `balance_after` en entrée. Ces champs viennent de la session et du serveur. | 12 |
| F6 | **Dépense en attente comptée dans le solde** | Solde sous-évalué, décisions faussées. | Une `expense` `PENDING` ne génère aucune écriture. La transaction n'est créée qu'à l'approbation. Le tableau de bord affiche « engagé » séparément du « solde ». | 13 |
| F7 | **Collecteur malveillant** : encaisse et n'enregistre pas | Détournement. | Chaque paiement horodaté, nommé, tracé dans `audit_logs`. Rapport « collectes par collecteur » consultable par le trésorier. Reçu envoyé (notification) au membre payeur : le membre est le contrôleur. | 12–14 |
| F8 | **Arrondis / centimes** | Écarts de quelques francs qui s'accumulent. | Tous les montants sont des **entiers de FCFA** (`BIGINT`). Aucun flottant ne touche jamais l'argent. | 13 |
| F9 | **Perte du justificatif** (fichier supprimé du disque) | Contrôle impossible en AG. | Justificatifs stockés hors `public/`, sauvegardés avec la base, taille et mime enregistrés pour détecter l'altération. | 13 |

---

## D. Risques projet (transverses)

| # | Risque | Parade |
|---|---|---|
| P1 | Le module vidéo (le plus coûteux) retarde tout le reste | Il est placé en phase 15, après le MVP utilisable. Le club a une app fonctionnelle bien avant. |
| P2 | Coût des tuiles cartographiques si le club grandit | OSM par défaut (gratuit) ; abstraction fournisseur (ADR-004) ; cache de tuiles côté mobile. |
| P3 | Reverse-geocoding : Nominatim limite à 1 req/s | Une seule requête par **zone détectée** (clustering des points par grille de 2 km), pas par point. Cache en base des zones connues de Dakar. |
| P4 | Données personnelles de géolocalisation | Consentement explicite, visibilité `PRIVATE` par défaut modifiable, export et suppression de compte. Voir [security.md](security.md). |
