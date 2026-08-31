# Déploiement

> **Phase 20.** Ce document fixe la cible ; il sera complété au moment de la mise en
> production réelle. Il est écrit maintenant pour que les choix des phases 2 à 19
> restent compatibles avec elle.

## 1. Cible

```text
                    Internet (HTTPS)
                          │
                    ┌─────┴─────┐
                    │   Nginx   │  TLS, compression, fichiers statiques
                    └─────┬─────┘
          ┌───────────────┼───────────────┐
          ▼               ▼               ▼
   app.cyclodakar.sn  api.cyclodakar.sn   /ws
   (React, statique)   (PHP-FPM Laravel)  (Node.js)
                              │                │
                         ┌────┴────┐      rendu vidéo
                         │  MySQL 8│      (FFmpeg)
                         └─────────┘
```

## 2. Hébergement

| Critère | Recommandation |
|---|---|
| Localisation | Europe (OVH, Scaleway, Hetzner) ou hébergeur africain — la latence depuis Dakar reste bonne dans les deux cas |
| Dimensionnement initial | 2 vCPU, 4 Go RAM, 80 Go SSD |
| Points de vigilance | le rendu vidéo sature un cœur ; prévoir 4 vCPU si la fonction est très utilisée |
| Stockage | disque local au départ ; S3 compatible dès que les vidéos dépassent quelques Go |

## 3. Backend

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan storage:link
```

`.env` de production :

```env
APP_ENV=production
APP_DEBUG=false          # impératif : une trace révèle la structure de la base
APP_URL=https://api.cyclodakar.sn
DB_USERNAME=cyclo        # jamais root
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Files d'attente et tâches planifiées, sous **Supervisor** :

```ini
[program:cyclo-queue]
command=php /var/www/cyclo/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
```

```cron
* * * * * cd /var/www/cyclo/backend && php artisan schedule:run >> /dev/null 2>&1
```

Le scheduler exécute notamment `finance:recompute-balance`, qui vérifie chaque nuit
que le solde en cache correspond bien à la somme du grand livre.

## 4. Web

```bash
cd web
npm ci
VITE_API_URL=https://api.cyclodakar.sn/api/v1 npm run build
# publier dist/ derrière Nginx
```

Nginx doit renvoyer `index.html` pour toute route inconnue (application à routage
côté client) :

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

## 5. Service Node

```bash
cd services
npm ci --omit=dev
NODE_ENV=production pm2 start src/server.js --name cyclo-services
```

`SERVICE_SECRET` doit être **identique** à `NODE_SERVICE_SECRET` du backend, et
**différent** de celui du développement.

Le service n'est **pas** exposé directement sur Internet : seul `/ws` est relayé par
Nginx. Les routes `/render` et `/internal/*` restent sur le réseau interne.

## 6. Mobile

```bash
npm install -g eas-cli
eas login
eas build --platform android --profile production   # AAB pour le Play Store
eas build --platform ios --profile production       # nécessite un compte Apple Developer
```

Diffusion possible en interne (APK partagé aux membres) avant publication sur les
stores — c'est souvent le plus pragmatique pour un club.

`EXPO_PUBLIC_API_URL` doit pointer sur l'API de production dans le profil de build.

## 7. Sauvegardes

| Élément | Fréquence | Rétention |
|---|---|---|
| Base MySQL (`mysqldump`) | quotidienne | 30 jours |
| Justificatifs financiers | quotidienne | 5 ans (obligation comptable de fait) |
| Photos d'activités | hebdomadaire | 1 an |
| Vidéos générées | non sauvegardées | régénérables depuis la trace |

**Une restauration doit être testée au moins une fois par trimestre.** Une sauvegarde
jamais restaurée n'est pas une sauvegarde.

## 8. Supervision

- Sonde HTTP sur `https://api.cyclodakar.sn/api/v1/health` (renvoie 503 si la base ou
  le stockage sont en échec).
- Alerte si `finance:recompute-balance` détecte un écart — c'est le signal qu'une
  incohérence financière s'est glissée quelque part.
- Suivi de la taille de `activity_points` : c'est la table qui grossit.

## 9. Mise à jour

```bash
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache
php artisan queue:restart
php artisan up
```

Les migrations doivent être **rétrocompatibles** : une ancienne version de
l'application mobile reste installée sur les téléphones des membres pendant des
semaines. On ajoute des colonnes, on n'en supprime pas dans la même version.
