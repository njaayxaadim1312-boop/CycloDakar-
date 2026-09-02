# Déploiement

> **Phase 20 — livrée.** Les sections 1 à 9 fixent la cible pour un hébergement
> Linux. La **section 10** décrit ce qui tourne réellement aujourd'hui :
> l'auto-hébergement, gratuit et vérifié de bout en bout. La **section 11** dit
> ce qu'un hébergement permanent demanderait de plus.

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

---

## 10. Auto-hébergement : la mise en service vérifiée

> **Ceci n'est pas la cible ci-dessus.** C'est ce qui tourne aujourd'hui, sans
> facture ni compte à créer, et qui a été éprouvé de bout en bout.

```powershell
.\deploiement\deployer.ps1                       # deploie et affiche l'adresse
.\deploiement\deployer.ps1 -Etat                 # ce qui tourne
.\deploiement\deployer.ps1 -Arreter              # arrete tout
.\deploiement\deployer.ps1 -ImporterDepuis cyclo_dakar   # met en service une base existante
```

### Ce qui distingue ceci d'une démonstration

`demarrer-demo.ps1` lance `php artisan serve` sur le dossier de travail. Le
déploiement, lui, **installe l'application ailleurs** — `C:\cyclo-production` —
avec sa propre base, son propre compte MySQL et ses propres caches.

Ce n'est pas de la propreté. `config:cache` fige les valeurs du `.env` dans un
fichier PHP ; fait dans le dossier de développement, il ferait tourner **la
suite de tests sur la base de production**, parce que phpunit surcharge des
variables d'environnement qu'une configuration mise en cache ne consulte plus.
Le dossier séparé est ce qui rend les caches sûrs.

Conséquence utile : modifier le code du dossier de travail ne change plus rien à
ce qui est en ligne. Mettre à jour, c'est relancer le script.

### L'architecture réelle

```text
        Internet (HTTPS, certificat Cloudflare)
                      │
            tunnel cloudflared (gratuit, sans compte)
                      │
            Apache 2.4  127.0.0.1:8080
              ├── fichiers statiques servis directement
              └── *.php  →  FastCGI  127.0.0.1:9101
                                │
                          php-cgi (PHP 8.3, opcache)
                                │
                          MySQL  cyclo_dakar_prod
                                 (compte cyclo_prod, jamais root)

        En arrière-plan : 2 × queue:work, 1 × schedule:work
```

### Trois pièges qui ont coûté cher, et leur raison

**`CGIPassAuth On` — sans lui, personne ne peut se connecter.**
Depuis Apache 2.4.13, l'en-tête `Authorization` n'est pas transmis aux
programmes FastCGI : il porte des mots de passe, et Apache refuse par défaut de
les confier à un programme tiers. Or c'est par cet en-tête que voyagent les
jetons de l'API.

Le symptôme est trompeur : **la connexion réussit et rend un jeton**, puis chaque
appel suivant répond 401. On soupçonne le jeton, le mobile, l'horloge — alors
que le serveur ne l'a jamais reçu.

**`SetHandler`, et jamais `ProxyPassMatch`.**
Les formes à base d'URL de proxy construisent un chemin invalide sous Windows :
le chemin se colle au port (`:9101C:/...`) ou reçoit un slash de tête
(`/C:/...`). PHP répond alors « No input file specified », sans autre
explication.

**Un aiguillage conditionnel vers PHP a pour cas de repli « fichier statique ».**
Une tentative de répartition de charge par `<If>` a servi, quand aucune
condition ne correspondait, **le code source de `index.php` en clair, avec un
code 200**. La règle qui envoie le `.php` à PHP doit rester inconditionnelle.

### La limite, dite franchement : une requête PHP à la fois

Sous Windows, `php-cgi` ne sait pas se dupliquer (`PHP_FCGI_CHILDREN` est une
notion Unix), et le répartiteur d'Apache refuse de relayer du FastCGI vers un
chemin Windows. **Mesuré, pas supposé** : huit requêtes en parallèle prennent
exactement le même temps qu'en série — environ 120 ms chacune, soit à peu près
huit requêtes par seconde.

Cela tient largement pour un club : Apache sert seul les images, le JavaScript
et les feuilles de style, qui font l'essentiel du trafic, et PHP n'est dérangé
que pour l'API. Mais un export PDF de plusieurs secondes fait patienter tout le
monde pendant ce temps.

La réponse n'est pas de bricoler ici, c'est PHP-FPM sur un hébergement Linux, où
le problème n'existe pas. Voir §11.

### Ce que le déploiement refuse de faire

Le script ne s'annonce pas tant qu'il n'a pas vérifié, **à travers l'adresse
publique** et non en local :

1. la sonde répond `healthy` — base, stockage, file d'attente, planificateur ;
2. un appel **authentifié** est servi (200) ;
3. une route protégée **refuse** sans jeton (401).

Le point 2 n'est pas décoratif. La sonde de santé est une route publique : elle
répondait parfaitement pendant que plus aucun membre ne pouvait utiliser
l'application. Un déploiement qui ne teste que des routes publiques ne teste pas
l'application.

### Ce qu'on paie à la place de l'hébergement

**C'est votre machine qui sert.** L'adresse ne répond que PC allumé, et un tunnel
gratuit change d'adresse à chaque redémarrage. Pour un usage régulier, `-Stable`
de `demarrer-demo.ps1` documente l'option Serveo, qui donne une adresse fixe
après avoir enregistré une clé SSH une fois.

---

## 11. Hébergement permanent : ce qu'il faut, et ce que je ne peux pas faire

Un service joignable 24 h sur 24, à une adresse stable, suppose un hébergeur —
donc **un compte à votre nom**, avec une adresse email vérifiée et souvent une
carte bancaire. Je ne crée pas de compte à votre place : les identifiants sont
les vôtres, et l'engagement aussi.

Ce qui est prêt, en revanche : l'application n'a aucune dépendance à Windows.
Sur n'importe quel hébergeur Linux avec PHP 8.3, MySQL 8 et Nginx, les sections
1 à 9 de ce document s'appliquent telles quelles — et **la limite d'une requête à
la fois disparaît**, PHP-FPM gérant nativement un banc de processus.

| Piste | Coût indicatif | Remarque |
|---|---|---|
| VPS (Hetzner, Scaleway, OVH) | 4 à 6 € / mois | le plus simple ; §1 à §9 s'appliquent |
| Hébergeur mutualisé sénégalais | variable | latence excellente ; vérifier PHP 8.3 |
| Offre gratuite d'un PaaS | 0 € | souvent sans MySQL, et le service s'endort |

**Les offres gratuites méritent un avertissement.** La plupart ne proposent que
PostgreSQL, endorment le service au bout de quelques minutes d'inactivité — le
premier membre qui ouvre l'application attend alors trente secondes — et
suppriment la base au bout de quelques semaines. Pour un club qui tient sa
comptabilité dans cette application, c'est un risque de perte de données, pas
une économie.

Entre les deux, l'auto-hébergement du §10 a un mérite : il ne coûte rien, il ne
dépend de personne, et les données restent sur une machine du club.
