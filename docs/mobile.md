# Application mobile — React Native / Expo

> Implémentation progressive : squelette en phase 1, navigation en phase 5,
> GPS en phase 6.

## 1. Choix de la variante Expo

| Variante | Verdict |
|---|---|
| **Expo Go** | Suffit pour les phases 1 à 5. **Ne gère pas** la localisation en arrière-plan. |
| **Expo Development Build** | ✅ **Retenue à partir de la phase 6.** Garde tout le confort Expo (mises à jour à chaud, `expo install`) et débloque les modules natifs. |
| React Native *bare* | Non retenue : coût de maintenance sans bénéfice ici. |

Passage en Development Build (phase 6) :

```powershell
npx expo install expo-dev-client
npx expo run:android      # nécessite Android Studio + JDK 17
```

## 2. Permissions

Les permissions sont demandées **au moment de l'usage**, jamais au premier lancement,
et toujours précédées d'un écran expliquant à quoi elles servent.

| Permission | Quand | Justification affichée |
|---|---|---|
| Localisation *pendant l'usage* | au premier « Démarrer » | tracer le parcours |
| Localisation *en arrière-plan* | juste après, écran dédié | continuer quand l'écran s'éteint |
| Caméra | premier scan QR ou première photo | scan membre, photo de sortie |
| Photothèque | ajout d'un justificatif | dépenses |
| Notifications | après la première sortie | rappels, paiements, vidéo prête |
| Mouvement (iOS) | démarrage d'activité | baromètre → dénivelé fiable |

Android exige en plus un **service de premier plan** avec notification permanente pour
la localisation en arrière-plan (Android 10+). Cette notification n'est pas une gêne :
c'est le témoin que l'enregistrement tourne, et l'utilisateur y accède d'un geste.

## 3. Stockage local

| Usage | Technologie | Pourquoi |
|---|---|---|
| Points GPS, activités en cours | `expo-sqlite` (WAL) | volume important, écriture continue, requêtable |
| Token d'authentification | `expo-secure-store` | Keychain iOS / Keystore Android |
| Préférences (thème, dernier sport) | `AsyncStorage` | données non sensibles |
| Photos en attente d'envoi | `expo-file-system` | fichiers volumineux |

Le mode WAL de SQLite est important : il permet d'écrire pendant qu'on lit, et il
résiste à une coupure brutale (batterie vide en pleine sortie).

## 4. Économie de batterie

Une sortie de 3 h avec GPS à 1 Hz consomme 15 à 20 % par heure si l'on ne fait rien.
Mesures prises :

- **Cadence adaptative** : 1 s en vélo/course, 3 s en randonnée (`config/cyclo.php`).
- **Aucun envoi réseau pendant l'activité** : tout part par lots, ou à la fin.
- **La carte n'est pas redessinée à chaque point** : la trace affichée est
  décimée (1 point sur N) et mise à jour au plus 2 fois par seconde.
- **L'écran peut être éteint** sans interrompre l'enregistrement.
- **Aucune animation** pendant l'enregistrement en dehors du chronomètre.

## 5. Rendu de longues traces

10 000 points ne sont jamais passés tels quels à `<Polyline>`. La trace affichée est
simplifiée (Douglas-Peucker, tolérance adaptée au zoom) et mémoïsée. La trace complète
n'existe qu'en base locale et sur le serveur.

## 6. Écrans prévus

```text
Splash → Login / Register
   │
   ├─ Accueil ......... résumé de la semaine, prochain événement, bouton Démarrer
   ├─ Démarrer ........ choix du sport → Tracking → Pause/Reprise → Terminer → Résumé
   ├─ Historique ...... liste filtrable → Détail d'activité
   ├─ Événements ...... liste → détail → inscription
   ├─ Participations .. mes participations, mon reste à payer
   ├─ Scanner QR ...... réservé aux rôles COLLECTOR et supérieurs
   ├─ Classements ..... hebdomadaire / mensuel / annuel
   ├─ Challenges ...... objectif et progression
   ├─ Notifications
   └─ Profil .......... photo, matricule, mon QR Code, thème, déconnexion
```

Le bouton **Démarrer / Arrêter** est volontairement surdimensionné (72 dp, voir
`touch.field` dans `src/theme/tokens.ts`) : il est visé en roulant, parfois avec des
gants, souvent en plein soleil.

## 7. Résolution de l'adresse de l'API

`src/lib/api.ts` déduit l'URL du backend dans cet ordre :

1. `EXPO_PUBLIC_API_URL` si elle est renseignée (production, ou dépannage) ;
2. l'IP du serveur Metro — c'est-à-dire celle du PC sur le Wi-Fi (téléphone réel) ;
3. `10.0.2.2` sur émulateur Android (alias de la machine hôte) ;
4. `127.0.0.1` sinon (simulateur iOS, Expo Web).

Aucune IP n'est donc à coder en dur, y compris en changeant de réseau.
L'écran d'état affiche l'URL réellement utilisée : c'est le premier endroit à regarder
quand le mobile ne joint pas le serveur.
