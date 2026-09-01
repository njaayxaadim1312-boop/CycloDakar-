# Vidéo animée du parcours

> **Livrée — dans le navigateur.** Le parcours se rejoue et s'exporte en
> fichier vidéo depuis `/activities/{uuid}/video`, sans serveur de rendu.
> Le rendu serveur décrit au §4 reste à venir : voir §7.

## 1. Intention

Produire une courte vidéo où la carte apparaît, la trace se dessine progressivement
et un marqueur avance du départ à l'arrivée, avec les statistiques en incrustation
et un écran final aux couleurs du club.

**Identité propre à Cyclo Dakar.** Aucune reprise de l'interface, du design ou des
éléments propriétaires d'applications existantes : c'est le *concept* (revoir sa
sortie en accéléré) qui est repris, pas leur habillage.

## 2. Déroulé

```text
0 s      Écran d'ouverture : logo Cyclo Dakar, sport, date
0,5 s    Carte cadrée sur les limites du parcours
0,5–90 % Trace qui se dessine + marqueur qui avance
         Incrustation : distance cumulée, vitesse, durée, dénivelé
         Photos géolocalisées affichées quand l'animation atteint leur position
90–100 % Écran final : résumé complet + logo + « Ensemble, plus loin, plus forts ! »
```

## 3. Formats et durées

| Format | Résolution | Usage |
|---|---|---|
| 9:16 | 1080 × 1920 | Statuts WhatsApp, stories (défaut) |
| 1:1 | 1080 × 1080 | Publications Instagram/Facebook |
| 16:9 | 1920 × 1080 | Écrans, projection en assemblée |

Durées : **15 s**, **30 s** (défaut), **60 s**.

Le facteur d'accélération est calculé pour que la sortie entière tienne dans la durée
demandée : une sortie de 2 h en 30 s tourne à ×240.

## 4. Chaîne technique

```text
Mobile/Web
   │  POST /api/v1/activities/{uuid}/video   { format, duration_s, theme }
   ▼
Laravel  ── crée video_jobs (QUEUED) ── répond 202 + job_id
   │
   │  Job en file d'attente
   ▼
NodeServiceClient ── POST /render (signé HMAC) ──▶ Node.js
                                                     │
                                    trace + statistiques → frames PNG
                                                     │
                                                  FFmpeg → MP4 (H.264 + AAC)
                                                     │
   ◀── POST /internal/video-jobs/{uuid}/complete (HMAC) ──┘
   │
Laravel ── video_jobs DONE + url ── Notification « Votre vidéo est prête »
```

La progression est diffusée en direct sur le canal WebSocket `video-job.{uuid}`
du service Node : l'utilisateur voit une barre avancer plutôt qu'un sablier.

## 5. Rendu des frames

Approche retenue pour la première version : **rendu 2D côté serveur**.

- Fond de carte : tuiles OpenStreetMap assemblées **une seule fois** pour l'emprise du
  parcours, puis mises en cache. On ne retélécharge pas les tuiles à chaque frame.
- Trace, marqueur, incrustations : dessinés par-dessus avec `node-canvas` ou `sharp`.
- 30 images/seconde. Une vidéo de 30 s = 900 frames.
- FFmpeg assemble les frames : `-c:v libx264 -pix_fmt yuv420p -crf 23`
  (`yuv420p` est indispensable pour la lecture sur iOS et WhatsApp).

Une version 3D façon survol est envisageable plus tard (Mapbox GL headless), mais
elle multiplie le coût et la complexité : elle n'est pas dans le périmètre de la v1.

## 6. Contraintes

| Contrainte | Décision |
|---|---|
| Rendu long (30–90 s CPU) | asynchrone obligatoire, jamais dans une requête HTTP |
| Rendus simultanés | `VIDEO_CONCURRENCY=1` par défaut — le rendu sature un cœur |
| Politique d'usage OSM | tuiles mises en cache, jamais de téléchargement massif |
| Poids du fichier | viser < 15 Mo pour un partage WhatsApp fluide |
| Échec de rendu | `video_jobs.status = FAILED` + message ; jamais de fichier tronqué livré |

## 7. Prérequis

FFmpeg doit être installé et accessible :

```powershell
winget install Gyan.FFmpeg
ffmpeg -version
```

Ou renseigner `FFMPEG_PATH` dans `services/.env`.

## 8. Évolutions prévues après la v1

Musique de fond, thèmes graphiques (`classic`, `night`, `sunset`), vidéo collective
d'un événement (plusieurs traces animées ensemble), incrustation du classement du jour.

## 6. Ce qui est livré

### 6.1 Le rejeu, côté serveur

`GET /activities/{uuid}/replay` renvoie la trace **horodatée** : chaque point
porte sa seconde depuis le départ, sa distance cumulée et la vitesse de son
segment.

C'est le point essentiel du module. La polyligne stockée suffit à *dessiner* un
parcours, pas à le *rejouer* : elle ne porte aucun temps. Une animation qui la
parcourrait à vitesse constante **effacerait les pauses** et ferait monter une
côte aussi vite qu'une descente — or c'est exactement ce qu'un membre veut
revoir.

La trace est décimée à 600 points au plus, avec un pas **régulier** et non une
simplification de Douglas-Peucker comme pour la polyligne stockée : celle-ci
supprime les points alignés, or ce sont eux qui portent le temps. Une longue
ligne droite parcourue lentement doit rester lente à l'écran.

### 6.2 La vidéo, fabriquée dans le navigateur

Le film est dessiné sur un `<canvas>` et non avec Leaflet, pour une raison
décisive : `captureStream()` n'existe que sur un canevas. C'est lui qui permet
d'obtenir un vrai fichier vidéo depuis le téléphone, sans FFmpeg ni file
d'attente.

| Point | Décision |
|---|---|
| Tuiles OSM | chargées en `crossOrigin="anonymous"` — sans quoi le canevas est « souillé » et l'export échoue |
| Tuiles manquantes | on dessine ce qu'on a et on redessine à leur arrivée ; attendre le réseau rendrait l'animation saccadée |
| Garde-fou | au-delà de 400 tuiles, on n'en demande aucune : un cadrage aberrant ferait bannir l'application d'OSM |
| Format | MP4 (`avc1`) d'abord, WebM en repli — seul MP4 se lit partout sur WhatsApp et iOS |
| Enregistrement | en **temps réel** : `captureStream` filme ce que le canevas affiche |

Formats 9:16, 1:1 et 16:9 ; durées 15, 30 et 60 s. Le facteur d'accélération
est affiché : « sortie de 30 min condensée en 30 s, soit ×60 ».

## 7. Ce qui reste — rendu serveur

Le pipeline du §4 garde deux justifications que le navigateur ne couvre pas :

1. **Les navigateurs qui ne savent pas encoder.** L'écran le dit et propose
   l'enregistrement d'écran, mais un rendu serveur ferait mieux.
2. **Fermer l'onglet.** L'enregistrement se fait en temps réel, page au
   premier plan. Une file d'attente permettrait de demander la vidéo et de la
   récupérer plus tard.

L'infrastructure (file, HMAC, WebSocket, webhook) est en place depuis la
phase 1 et n'a pas bougé.
