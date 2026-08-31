# Installation détaillée — Windows + XAMPP

Ce document complète le [README](../README.md) : il explique **pourquoi** chaque étape,
et surtout comment se dépanner quand ça coince.

---

## 1. Le point délicat : la version de PHP

XAMPP est livré avec **PHP 8.1**. Laravel 13 exige **PHP 8.3 minimum**.
Trois options, par ordre de préférence :

### Option retenue sur ce poste — PHP 8.3 en parallèle (recommandé)

PHP 8.3.33 (build *NTS x64*) est installé dans `C:\php83`, et ce chemin est placé **en tête**
du `PATH` utilisateur. Conséquences :

- `php` en ligne de commande = **8.3.33** → Laravel et Composer fonctionnent ;
- Apache dans XAMPP continue d'utiliser **son** PHP 8.1 → vos autres projets ne bougent pas ;
- MySQL de XAMPP est utilisé tel quel.

**Vérifier :**

```powershell
php -v          # doit afficher 8.3.x
php -m          # doit lister pdo_mysql, mbstring, openssl, curl, gd, zip, intl, bcmath
where php       # C:\php83\php.exe doit apparaître EN PREMIER
```

**Réinstaller depuis zéro :**

```powershell
# 1. Télécharger le build NTS x64 depuis https://windows.php.net/download/
#    (php-8.3.x-nts-Win32-vs16-x64.zip) et l'extraire dans C:\php83

# 2. Créer le php.ini
copy C:\php83\php.ini-development C:\php83\php.ini
```

Éditez `C:\php83\php.ini` :

```ini
extension_dir = "C:/php83/ext"
date.timezone = "Africa/Dakar"
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
```

Ajoutez également le **bundle de certificats racine**, sans lequel PHP sous
Windows ne peut valider aucun certificat HTTPS — tout appel sortant (géocodage
Nominatim, envoi de courriels, notifications push) échouerait avec
« unable to get local issuer certificate » :

```powershell
curl.exe -L -o C:\php83\cacert.pem https://curl.se/ca/cacert.pem
```

```ini
curl.cainfo = "C:/php83/cacert.pem"
openssl.cafile = "C:/php83/cacert.pem"
```

Puis décommentez (retirez le `;` devant) :

```ini
extension=bcmath
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=exif
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=pdo_sqlite
extension=sodium
extension=sqlite3
extension=zip
```

Puis ajoutez `C:\php83` en tête du PATH utilisateur :

```powershell
[Environment]::SetEnvironmentVariable("Path", "C:\php83;" + [Environment]::GetEnvironmentVariable("Path","User"), "User")
```

**Fermez et rouvrez le terminal** pour que le changement prenne effet.

**Revenir en arrière** (retirer PHP 8.3 du PATH) :

```powershell
$p = [Environment]::GetEnvironmentVariable("Path","User") -replace 'C:\\php83;?', ''
[Environment]::SetEnvironmentVariable("Path", $p, "User")
```

### Autres options

- **Mettre à jour XAMPP** vers une version livrant PHP 8.3+ : plus propre à terme,
  mais casse les autres projets du poste qui dépendent du PHP 8.1.
- **Laragon** ou **Herd** : gèrent plusieurs versions de PHP nativement. À envisager
  si le poste doit héberger plusieurs projets PHP de versions différentes.

---

## 2. MySQL / MariaDB

Le projet utilise le MySQL de XAMPP (MariaDB 10.4).

### Démarrer

Via le **XAMPP Control Panel** → bouton **Start** en face de *MySQL*.

Ou en ligne de commande :

```powershell
Start-Process "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini","--standalone" -WindowStyle Hidden
```

### Créer la base

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS cyclo_dakar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Ou via phpMyAdmin (`http://localhost/phpmyadmin` — nécessite qu'Apache tourne) :
nouvelle base `cyclo_dakar`, interclassement `utf8mb4_unicode_ci`.

### Vérifier

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "SHOW DATABASES LIKE 'cyclo%';"
```

> **Le port 3306 est déjà pris ?**
> Un autre MySQL tourne (installation autonome, service Windows, Docker).
> Arrêtez-le, ou changez le port dans `C:\xampp\mysql\bin\my.ini` **et** dans
> `backend/.env` (`DB_PORT`).

---

## 3. Backend

```powershell
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan cyclo:doctor
```

`cyclo:doctor` vérifie en une passe : version de PHP, extensions, clé applicative,
connexion à la base, migrations appliquées, droits d'écriture sur `storage/`, et
joignabilité du service Node.

### Le secret partagé avec Node

`backend/.env` contient `NODE_SERVICE_SECRET`. Cette valeur doit être **recopiée à
l'identique** dans `services/.env` sous le nom `SERVICE_SECRET`. C'est ce secret qui
signe les échanges entre Laravel et Node (HMAC-SHA256).

Pour en générer un nouveau :

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

---

## 4. Web

```powershell
cd web
npm install
copy .env.example .env
npm run dev
```

En développement, Vite relaie `/api` vers `http://127.0.0.1:8000` (voir `vite.config.ts`).
Le navigateur ne voit donc qu'**une seule origine** : aucun problème de CORS.

Pour consulter le web depuis un téléphone sur le même Wi-Fi, Vite écoute déjà sur le
réseau (`host: true`) : ouvrez `http://<IP-du-PC>:5173`.

---

## 5. Service Node

```powershell
cd services
npm install
copy .env.example .env
# éditer .env : SERVICE_SECRET = la valeur de NODE_SERVICE_SECRET du backend
npm run dev
```

Vérification :

```powershell
curl http://localhost:4000/health
```

Le service n'est **pas indispensable** avant la phase 15 (vidéo) : le reste de
l'application fonctionne sans lui.

---

## 6. Mobile

```powershell
cd mobile
npm install
copy .env.example .env
npx expo start
```

### Tester dans l'émulateur Android

Appuyez sur `a` dans le terminal Expo. L'application vise automatiquement
`http://10.0.2.2:8000` — l'alias de la machine hôte vu depuis l'émulateur.

### Tester sur un téléphone réel (Expo Go)

1. Installez **Expo Go** depuis le Play Store / App Store.
2. Le téléphone et le PC doivent être sur **le même Wi-Fi**.
3. Lancez le backend en écoutant sur le réseau :
   ```powershell
   php artisan serve --host=0.0.0.0 --port=8000
   ```
4. Autorisez le port 8000 dans le pare-feu Windows (une fenêtre le propose au premier
   lancement ; sinon) :
   ```powershell
   New-NetFirewallRule -DisplayName "Cyclo Dakar API" -Direction Inbound -LocalPort 8000 -Protocol TCP -Action Allow
   ```
5. Scannez le QR Code affiché par `npx expo start`.

L'application déduit l'adresse du PC à partir du serveur Metro : aucune IP à saisir.
L'écran d'accueil affiche l'URL réellement utilisée — c'est le premier endroit à
regarder en cas de problème.

### Expo Go ne suffira plus à partir de la phase 6

Le GPS en **arrière-plan** (`expo-task-manager` + service de premier plan Android)
ne fonctionne pas dans Expo Go. Il faudra une **Development Build** :

```powershell
npx expo install expo-dev-client
npx expo run:android
```

Cela nécessite Android Studio et un JDK 17. C'est documenté au moment voulu dans
[mobile.md](mobile.md).

---

## 7. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `Your requirements could not be resolved... php ^8.3` | `php` pointe encore sur XAMPP 8.1 | Rouvrir le terminal ; vérifier `where php` |
| `SQLSTATE[HY000] [2002]` | MySQL n'est pas démarré | XAMPP → Start MySQL |
| `SQLSTATE[HY000] [1049] Unknown database` | Base non créée | Voir §2 |
| `could not find driver` | `pdo_mysql` désactivé | Décommenter dans `C:\php83\php.ini` |
| Le web affiche « Serveur injoignable » | Backend non démarré | `php artisan serve` dans `backend/` |
| Le mobile affiche « Serveur injoignable » | Pare-feu, mauvais Wi-Fi, ou `--host=0.0.0.0` oublié | Voir §6 |
| `EADDRINUSE :::4000` | Le service Node tourne déjà | Fermer l'autre terminal, ou changer `PORT` |
| Composer très lent sous Windows | Antivirus qui scanne chaque fichier | Exclure `C:\CycloDakar` de l'analyse temps réel |
| `unable to get local issuer certificate` | Bundle de certificats absent | Voir §1, `curl.cainfo` |
| Les zones traversées restent vides | File d'attente non traitée | `php artisan queue:work` dans `backend/` |
| `npm ERR! ERESOLVE` | Conflit de dépendances React Native | `npx expo install <paquet>` plutôt que `npm install` |

---

## 8. Rappel : ne jamais modifier la base à la main

Toute évolution de schéma passe par une **migration Laravel** :

```powershell
php artisan make:migration ajoute_colonne_x_a_table_y
php artisan migrate
```

Modifier une table depuis phpMyAdmin rend la base du poste incohérente avec celle
des autres développeurs et avec la production.
