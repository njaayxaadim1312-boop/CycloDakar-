# GPS — capture, filtrage et calcul des statistiques

> Implémentation : phase 6 (mobile) et phase 6/7 (serveur).
> Ce document fixe **l'algorithme de référence**. Le mobile et le serveur doivent produire le
> même résultat sur le même jeu de points ; les tests de la phase 18 le vérifient sur des
> traces fixtures.

## 1. Principe directeur

Le client affiche des chiffres **provisoires** pour le confort de l'utilisateur.
Le serveur **recalcule tout** à la finalisation, à partir des points bruts reçus.
En cas de divergence, c'est le serveur qui a raison.

## 2. Capture

| Sport | Intervalle | Distance minimale | Précision requise |
|---|---|---|---|
| `CYCLING` | 1 s | 5 m | ≤ 25 m |
| `RUNNING` | 1 s | 3 m | ≤ 20 m |
| `HIKING` | 3 s | 3 m | ≤ 30 m |

Android : `expo-location` en `Location.Accuracy.BestForNavigation` + `expo-task-manager`
avec un *foreground service* et une notification permanente (obligatoire depuis Android 10
pour la localisation en arrière-plan).
iOS : `allowsBackgroundLocationUpdates`, `activityType` adapté (`otherNavigation` /
`fitness`), `UIBackgroundModes: ["location"]`.

Permissions demandées **au moment de l'utilisation**, avec un écran d'explication préalable
(pourquoi l'arrière-plan est nécessaire), jamais au premier lancement.

## 3. Phase d'acquisition (anti-G2)

Avant de démarrer le chronomètre :

1. On collecte des points sans les enregistrer.
2. Le départ n'est validé qu'après **3 points consécutifs** avec `accuracy ≤ 20 m`
   espacés d'au moins 1 s.
3. Tout point dont le `timestamp` est antérieur de plus de **10 s** au moment du démarrage
   est rejeté (position en cache de l'OS).

L'écran affiche « Recherche du signal GPS… » pendant cette phase.

## 4. Filtre en cascade

Chaque point candidat passe six tests, dans cet ordre. Le premier échec le rejette
(en incrémentant un compteur par motif, remonté dans `sync_logs.reason`).

```text
point candidat
   │
   ├─ 1. VALIDITÉ      lat ∈ [-90,90], lng ∈ [-180,180], non (0,0)      → REJET
   ├─ 2. PRÉCISION     accuracy > seuil_sport                            → REJET
   ├─ 3. CHRONOLOGIE   recorded_at ≤ recorded_at du point précédent      → REJET
   ├─ 4. DUPLICAT      distance < 1 m ET Δt < 1 s                        → REJET (silencieux)
   ├─ 5. VITESSE       v_implicite = d / Δt  >  v_max_sport              → REJET (saut GPS)
   └─ 6. ACCÉLÉRATION  |v − v_précédent| / Δt > 5 m/s²                   → REJET
   │
   └─ ACCEPTÉ → seq++ → SQLite
```

Vitesses maximales plausibles (`v_max_sport`) :

| Sport | v_max |
|---|---|
| CYCLING | 25 m/s (90 km/h — descente) |
| RUNNING | 12 m/s (43 km/h) |
| HIKING | 6 m/s (21 km/h) |

Le test 5 est le plus important : c'est lui qui absorbe les sauts de multipath urbain (G1).
Un saut de 150 m en 1 s donne 150 m/s, largement au-dessus du seuil.

**Note importante** : un point rejeté n'est pas jeté ; il est conservé localement avec un
marqueur pour permettre l'audit et le comptage `raw_points_count` vs `points_count`.

## 5. Distance

Formule de **Haversine**, rayon terrestre 6 371 008,8 m.

```
a = sin²(Δφ/2) + cos φ₁ · cos φ₂ · sin²(Δλ/2)
d = 2R · atan2(√a, √(1−a))
```

Haversine plutôt que Vincenty : l'erreur (~0,3 %) est négligeable devant l'erreur GPS,
et le coût est 20× moindre sur 10 000 points.

Un segment n'est ajouté à la distance que si :
- les deux points sont acceptés ;
- aucun des deux n'est marqué `is_paused` ;
- `d ≥ 1 m` (sous 1 m, c'est du bruit à l'arrêt).

## 6. Temps

```
duration_s     = ended_at − started_at
paused_time_s  = Σ des intervalles marqués is_paused
                 + Σ des intervalles où v_lissée < 0,8 m/s pendant ≥ 10 s
moving_time_s  = duration_s − paused_time_s
```

Le seuil de 0,8 m/s (≈ 2,9 km/h) est sous la vitesse de marche lente : il capture les feux
rouges et les arrêts sans amputer une randonnée lente.

## 7. Vitesse

- **Instantanée** (affichage) : vitesse fournie par le GPS, lissée par un filtre de Kalman 1D
  (`Q = 0,05`, `R = accuracy`). Si le GPS ne fournit pas de vitesse, on dérive
  `d / Δt` sur les 3 derniers points.
- **Moyenne** : `distance_m / moving_time_s` — sur le temps **actif**, pas le temps total.
- **Maximale** : maximum de la vitesse **lissée**, jamais de la vitesse brute
  (sinon un seul point aberrant fixe un record à 87 km/h).

## 8. Allure (course / randonnée)

```
allure (s/km) = moving_time_s / (distance_m / 1000)
```
Affichée `MM:SS /km`. L'allure « maximale » est en réalité la **meilleure** allure,
calculée sur le kilomètre le plus rapide (split), pas sur un point isolé.

## 9. Dénivelé (anti-G3)

L'altitude GPS a une erreur de ±10 à 15 m. Sommer naïvement les différences fabrique
plusieurs centaines de mètres de dénivelé sur un parcours plat — inacceptable à Dakar.

```text
1. Lissage : moyenne mobile centrée sur 5 points → alt_lissée
2. Hystérésis : on suit une direction (montée/descente) ;
   le changement de direction n'est acté que si l'écart cumulé dépasse SEUIL = 3 m
3. gain  += écart, uniquement sur les segments montants confirmés
   loss  += écart, uniquement sur les segments descendants confirmés
```

Si le baromètre est disponible (`expo-sensors`), son altitude relative est préférée :
elle est ~5× plus stable que l'altitude GPS.

## 10. Simplification de la trace

Algorithme de **Ramer–Douglas–Peucker**, tolérance **5 m**.
Sur une trace typique de 10 000 points on descend à 400–900 points sans écart visible à
l'échelle d'affichage. Le résultat est encodé au format *Google Encoded Polyline* (précision 5)
et stocké dans `activities.polyline`.

Cette polyline est ce que consomment : la liste d'activités, la miniature, la carte de détail,
le classement. Les points bruts ne sont relus que pour l'export GPX et le rendu vidéo.

## 11. Zones traversées (anti-P3)

On n'appelle **jamais** le reverse-geocoding par point.

```text
1. On projette tous les points acceptés sur une grille de 0,02° (≈ 2,2 km)
2. On garde les cellules distinctes traversées      → typiquement 3 à 15 cellules
3. Pour chaque cellule : lecture du cache `geo_zones_cache` (clé = cellule)
4. Cache manquant → 1 appel Nominatim (respect du 1 req/s), résultat mis en cache définitif
5. Les libellés sont dédupliqués et ordonnés selon l'ordre de passage
```

Une sortie de 46 km génère ainsi **au plus une quinzaine** d'appels la première fois,
et **zéro** ensuite — Dakar étant un territoire fini, le cache se remplit en quelques semaines.

## 12. Protocole de synchronisation

```text
POST /api/v1/activities                      { uuid, sport, started_at, device_info }
     → 201 (ou 200 si uuid déjà connu — idempotent)

POST /api/v1/activities/{uuid}/points        { points: [ {seq, lat, lng, ...} × 100 ] }
     → 200 { accepted, rejected, last_seq }
     Réémission d'un lot déjà reçu : absorbée par UNIQUE(activity_id, seq)

POST /api/v1/activities/{uuid}/finalize      { ended_at, expected_points_count }
     → 409 si expected_points_count ≠ points reçus (lots manquants → le client rejoue)
     → 200 { activity avec toutes les stats recalculées serveur }
```

Le client ne supprime ses points locaux qu'après un `finalize` réussi.

## 13. Tests de référence (phase 18)

Fixtures dans `backend/tests/Fixtures/gps/` :

| Fixture | Ce qu'elle vérifie |
|---|---|
| `corniche-46km.json` | distance à ±0,5 % d'une référence Strava |
| `multipath-plateau.json` | les sauts sont rejetés, distance stable |
| `flat-dakar.json` | dénivelé calculé < 30 m sur un parcours plat |
| `pause-5min.json` | `moving_time_s` exclut la pause |
| `duplicate-batch.json` | rejeu d'un lot → aucun point en double |
| `cold-start.json` | le premier point aberrant est ignoré |

## Enregistrement depuis le navigateur

Le web sait désormais enregistrer une sortie (`/record`), en plus du mobile.

**Le mobile reste la bonne façon d'enregistrer.** Lui seul suit la position
écran éteint, via une tâche de fond déclarée. Un navigateur cesse de recevoir
des positions dès que l'onglet passe en arrière-plan ou que l'écran s'éteint —
c'est une limite de la plateforme, pas du code.

L'écran en tient compte plutôt que de la masquer :

- Il l'annonce **avant** de démarrer, pas après trois heures de sortie.
- Il demande le verrou d'écran (`navigator.wakeLock`) quand il existe ; son
  absence n'empêche pas d'enregistrer.
- Il **signale l'interruption** dès que la page passe en arrière-plan, pour que
  le membre sache que sa trace aura un trou.

Le filtre est le **même fichier** que sur mobile (`web/src/lib/gps.ts`, miroir
de `mobile/src/lib/gps.ts`). Ce n'est pas de la commodité : un point accepté
d'un côté et rejeté de l'autre donnerait deux distances pour la même sortie.

Le protocole d'envoi est celui de la phase 6, sans exception :

| Étape | Garantie |
|---|---|
| `POST /activities` | l'`uuid` vient du client et sert de clé d'idempotence |
| `POST /activities/{uuid}/points` | lots de 500 au plus, points numérotés (`seq`) |
| curseur d'envoi | n'avance **qu'après** confirmation du serveur |
| `POST /activities/{uuid}/finalize` | `expected_points_count` révèle une trace tronquée |

Les chiffres affichés pendant la sortie sont **provisoires**, et l'écran le
dit. Le serveur refiltre et recalcule tout à la finalisation : c'est son
résultat qui fait foi, conformément à la règle « le client n'est jamais cru ».

Le titre se pose **après** la finalisation, par un `PATCH` : la finalisation
fige la trace et recalcule les statistiques, l'y glisser ferait passer un champ
d'affichage pour une donnée de mesure.

## Le sur-comptage à la marche — corrigé

Signalé par le club : « en marchant doucement, la vitesse exagère, et moins de
6 m me sont comptés 20 m ».

### La cause

Deux défauts se combinaient.

**1. La référence avançait à chaque point.** La distance se mesurait de proche
en proche : point 1 → point 2, point 2 → point 3… Chaque tremblement du GPS
était donc mesuré *depuis le tremblement précédent*, et tous ceux qui
dépassaient le seuil s'additionnaient.

**2. Le seuil ne tenait pas compte du sport.** Un mètre convient au vélo, où
une seconde franchit six mètres. À 1,2 m/s, **le marcheur avance moins vite que
ne bouge l'incertitude de position** : le seuil ne filtrait plus rien.

Mesuré sur trace synthétique — 60 s à 1,2 m/s, soit 72 m réels, avec 3 m de
tremblement **latéral** (perpendiculaire à la marche : il n'ajoute aucune
distance réelle, donc tout mètre en plus est à coup sûr une erreur) :

| | Distance | Vitesse moyenne | 90 s immobile |
|---|---|---|---|
| Avant | **135 m** (+87 %) | 2,29 m/s — 8,2 km/h | **209 m** de pur bruit |
| Après | **69 m** (−4 %) | 1,25 m/s — 4,5 km/h | **0 m** |

### La correction — le point d'ancrage

On ne mesure plus depuis le point précédent, mais depuis **le dernier point qui
a produit un déplacement réel**. Sous le seuil, le point est enregistré — la
carte en a besoin — mais l'ancre **ne bouge pas**. Un membre immobile reste
donc à quelques mètres de son ancre quoi qu'affiche le GPS, et rien ne
s'accumule.

Effet de bord bienvenu : la vitesse se calcule sur une base plus longue, donc
bien plus stable.

Le seuil est désormais **par sport** (`cyclo.sports.*.min_distance_m`) :

| Sport | Seuil | Pourquoi |
|---|---|---|
| Cyclisme | 5 m | une seconde à 6 m/s en franchit six |
| Course | 3 m | une seconde à 3 m/s en franchit trois |
| Randonnée | 3 m | allure lente mais terrain accidenté |
| **Marche** | **8 m** | mesuré : 4 m → 90 m, 6 m → 84 m, 8 m → 69 m |

Au-delà de 8 m on rognerait les virages d'une promenade. À 1,2 m/s, 8 m
représentent environ 7 s : la trace reste fidèle.

Le seuil **s'adapte enfin à la précision annoncée** par l'appareil : deux points
donnés à ±15 m ne prouvent pas un déplacement de 9 m. Sous bon signal, le seuil
du sport domine et rien n'est rogné.

### Les trois étages corrigés ensemble

Le serveur, le web et le mobile appliquent la **même** règle. Un affichage en
direct qui divergerait du calcul final ferait douter du chiffre le plus
important de l'application.

| Étage | Vérification |
|---|---|
| `ActivityStatsCalculator` | 6 tests PHP, dont vélo et course inchangés |
| `RecordingSession` (web) | 6 assertions — mêmes chiffres que le serveur, 69 m et 0 m |
| `locationTask` (mobile) | même ancre, même seuil adaptatif |

## Le téléphone posé — corrigé

Signalé juste après : « je démarre en cyclisme, je suis sur place, il m'affiche
déjà 67 m ».

### Pourquoi l'ancre ne suffisait pas

Un récepteur à l'arrêt ne tremble pas au hasard : il **dérive lentement**, de
plusieurs mètres par minute, en suivant les satellites qui passent. Cette
dérive finit donc par franchir n'importe quel seuil de **distance** — l'ancre
suit, et le cycle recommence.

Mesuré, téléphone posé cinq minutes :

| Dérive | Précision | Avant | Après |
|---|---|---|---|
| 4 m | 5 m | 41 m | **0 m** |
| 6 m | 8 m | 50 m | **0 m** |
| 10 m | 12 m | 99 m | **0 m** |

### Deux rejets, deux comportements

C'est la distinction qui manquait.

| Rejet | Cause | L'ancre |
|---|---|---|
| **Trop court** | tremblement vif du GPS | **reste** — il faut continuer d'accumuler la preuve d'un déplacement réel |
| **Trop lent** | dérive, ou arrêt réel | **avance** — sinon le temps d'un feu rouge serait crédité au premier segment roulé |

Le second point n'est pas théorique : sans lui, un arrêt de trois minutes
comptait comme du temps actif. C'est un test existant qui l'a révélé.

La vitesse tranche ce que la distance ne peut pas : 10 m parcourus en 60 s font
0,17 m/s, ce qui n'est ni rouler ni marcher.

### Le seuil vaut deux fois la précision annoncée

Deux points donnés chacun à ±8 m peuvent se trouver à 16 m l'un de l'autre sans
que personne n'ait bougé. Le facteur est réglé dans
`cyclo.gps.accuracy_factor`, et il a été **choisi sur mesure** :

| Facteur | Téléphone posé | Vélo | Marche |
|---|---|---|---|
| 1,5 | 13 m | −0,8 % | −4,2 % |
| **2,0** | **0 m** | **−0,8 %** | **−4,2 %** |
| 2,5 | 0 m | −1,1 % | −8,3 % |

2,0 élimine la dérive sans rien coûter aux vraies sorties ; 2,5 dégraderait la
marche pour rien.

### Les seuils du client étaient restés à 1 m

Le serveur utilisait `min_distance_m` par sport, les clients gardaient un
`minSegmentM: 1` hérité. Ils sont désormais alignés — cyclisme 5 m, course et
randonnée 3 m, marche 8 m — parce qu'un affichage en direct qui diverge du
calcul final fait douter du chiffre.
