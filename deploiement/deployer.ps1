<#
    CYCLO DAKAR — DÉPLOIEMENT (phase 20)
    =====================================

        .\deploiement\deployer.ps1                déploie et affiche l'adresse
        .\deploiement\deployer.ps1 -Neuf          repart d'une base vierge
        .\deploiement\deployer.ps1 -ImporterDepuis cyclo_dakar
                                                  met en service une base existante
        .\deploiement\deployer.ps1 -Etat          ce qui tourne, et l'adresse
        .\deploiement\deployer.ps1 -Arreter       arrête tout

    METTRE À JOUR, C'EST RELANCER LE SCRIPT. Il réinstalle le code depuis le
    dernier commit, rejoue les migrations et redémarre les processus, en
    CONSERVANT la base. Le service est indisponible une à deux minutes ; pour
    un club, c'est préférable à une bascule compliquée qu'on n'oserait plus
    lancer.

    CE QUI DISTINGUE CECI DE `demarrer-demo.ps1`
    ---------------------------------------------
    La démonstration lance `php artisan serve` sur le dossier de travail. C'est
    un serveur de DÉVELOPPEMENT : il traite une requête à la fois, lit le code
    à chaque appel, et sert la base sur laquelle on développe.

    Ici, l'application est INSTALLÉE AILLEURS — un dossier de production, sa
    propre base, son propre compte MySQL, ses propres caches — et servie par
    Apache devant un banc de processus FastCGI. Toucher au code du dossier de
    travail ne change plus rien à ce qui est en ligne, ce qui est précisément
    ce qu'on attend d'un déploiement.

    CE QUI EST GRATUIT, ET CE QUI NE L'EST PAS
    ------------------------------------------
    Tout ici l'est : Apache et PHP sont déjà sur la machine, le tunnel
    Cloudflare ne demande aucun compte. Le prix est ailleurs — C'EST VOTRE
    MACHINE QUI SERT. L'adresse ne répond que PC allumé, et elle change à
    chaque redémarrage du tunnel.

    Un hébergement permanent suppose un compte chez un hébergeur, donc une
    inscription à votre nom. Voir docs/deployment.md §11.

    POURQUOI LE DÉPLOIEMENT REFUSE PARFOIS DE S'ANNONCER
    -----------------------------------------------------
    La dernière étape exige que la sonde réponde `healthy` À TRAVERS L'ADRESSE
    PUBLIQUE — pas en local. Un déploiement qui s'annonce sans vérifier laisse
    découvrir la panne par le premier membre qui essaie, un dimanche matin.
#>

param(
    [string] $Racine       = 'C:\cyclo-production',
    [int]    $Port         = 8080,
    [switch] $Neuf,
    [string] $ImporterDepuis,
    [switch] $Etat,
    [switch] $Arreter
)

$ErrorActionPreference = 'Stop'

$source      = Split-Path -Parent $PSScriptRoot
$php         = 'C:\php83\php.exe'
$phpCgi      = 'C:\php83\php-cgi.exe'
$composer    = 'C:\ProgramData\ComposerSetup\bin\composer.phar'
$httpd       = 'C:\xampp\apache\bin\httpd.exe'
$apacheRoot  = 'C:\xampp\apache'
$mysql       = 'C:\xampp\mysql\bin\mysql.exe'
$mysqldump   = 'C:\xampp\mysql\bin\mysqldump.exe'
$cloudflared = Join-Path $source 'tools\cloudflared.exe'

$appDir      = Join-Path $Racine 'backend'
$publicDir   = Join-Path $appDir 'public'
$runDir      = Join-Path $Racine 'run'
$logsDir     = Join-Path $Racine 'logs'
$confApache  = Join-Path $Racine 'apache.conf'
$fichierUrl  = Join-Path $Racine 'ADRESSE.txt'
$portBase    = 9100

function Ecrire($texte, $couleur = 'Gray') { Write-Host $texte -ForegroundColor $couleur }
function Titre($texte) { Write-Host ''; Write-Host "  $texte" -ForegroundColor Cyan }
function Bien($texte)  { Write-Host "  [ok] $texte" -ForegroundColor Green }
function Mal($texte)   { Write-Host "  [!!] $texte" -ForegroundColor Red }

# ---------------------------------------------------------------- processus ---

<#
    Tuer un processus ET SA DESCENDANCE.

    Les ouvriers de file et le planificateur tournent dans une boucle PowerShell
    qui les relance s'ils meurent — c'est ce qui tient le service debout la nuit.
    Tuer la boucle sans tuer son enfant laisserait un `php.exe` orphelin
    continuer à consommer la file : à la remise en route, deux ouvriers
    prendraient le même travail.
#>
function Arreter-Arbre([int] $identifiant) {
    if ($identifiant -le 0) { return }

    Get-CimInstance Win32_Process -Filter "ParentProcessId = $identifiant" -ErrorAction SilentlyContinue |
        ForEach-Object { Arreter-Arbre ([int] $_.ProcessId) }

    Stop-Process -Id $identifiant -Force -ErrorAction SilentlyContinue
}

function Noter-Pid([string] $nom, [int] $identifiant) {
    New-Item -ItemType Directory -Force -Path $runDir | Out-Null
    Set-Content -Path (Join-Path $runDir "$nom.pid") -Value $identifiant -Encoding ascii
}

function Lire-Pid([string] $nom) {
    $f = Join-Path $runDir "$nom.pid"
    if (-not (Test-Path $f)) { return 0 }
    return [int] (Get-Content $f -Raw).Trim()
}

function Vivant([int] $identifiant) {
    if ($identifiant -le 0) { return $false }
    return $null -ne (Get-Process -Id $identifiant -ErrorAction SilentlyContinue)
}

<#
    Une boucle qui relance son programme.

    Sans elle, un ouvrier de file tué par une exception fatale ne reviendrait
    jamais : les rappels de cotisation cesseraient de partir sans que rien ne le
    dise. La sonde de santé le signalerait — encore faut-il que quelqu'un la
    regarde un dimanche.
#>
function Lancer-Boucle([string] $nom, [string] $commande) {
    $script = "while (`$true) { $commande ; Start-Sleep -Seconds 2 }"

    $p = Start-Process -FilePath 'powershell.exe' `
        -ArgumentList '-NoProfile', '-WindowStyle', 'Hidden', '-Command', $script `
        -WindowStyle Hidden -PassThru

    Noter-Pid $nom $p.Id
}

# ------------------------------------------------------------------- outils ---

function Php-Prod {
    <# PHP en configuration de production : erreurs jamais affichées, opcache. #>
    return @(
        '-d', 'display_errors=0',
        '-d', 'log_errors=1',
        '-d', "error_log=$logsDir\php-erreurs.log",
        '-d', 'expose_php=0',
        '-d', 'zend_extension=opcache',
        '-d', 'opcache.enable=1',
        '-d', 'opcache.memory_consumption=192',
        '-d', 'opcache.max_accelerated_files=20000',
        # Le code ne change pas entre deux déploiements : inutile de vérifier
        # la date de chaque fichier à chaque requête. C'est le gain principal.
        # Relancer le script redémarre les processus, ce qui vide le cache.
        '-d', 'opcache.validate_timestamps=0',
        '-d', 'memory_limit=256M',
        '-d', 'upload_max_filesize=12M',
        '-d', 'post_max_size=16M',
        '-d', 'max_execution_time=60'
    )
}

function Artisan([string[]] $arguments, [switch] $Silencieux) {
    Push-Location $appDir
    try {
        $env:APP_ENV = 'production'
        $sortie = & $php 'artisan' @arguments 2>&1
        $code = $LASTEXITCODE
        if (-not $Silencieux) { $sortie | ForEach-Object { Ecrire "      $_" DarkGray } }
        return @{ Code = $code; Sortie = ($sortie -join "`n") }
    }
    finally { Pop-Location; Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue }
}

function Interroger([string] $url, [int] $essais = 1) {
    for ($i = 0; $i -lt $essais; $i++) {
        try {
            $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15
            return $r.Content
        } catch {
            $reponse = $_.Exception.Response
            if ($reponse) {
                # Un 503 est une RÉPONSE : la sonde parle, elle dit que ça va
                # mal. La distinguer d'un serveur muet évite de conclure à une
                # panne de réseau quand c'est la base qui est tombée.
                $flux = New-Object System.IO.StreamReader($reponse.GetResponseStream())
                return $flux.ReadToEnd()
            }
            if ($i -lt $essais - 1) { Start-Sleep -Seconds 2 }
        }
    }
    return $null
}

# ==================================================================== ARRÊT ===

function Tout-Arreter {
    Titre 'Arrêt'

    foreach ($nom in @('tunnel', 'planificateur', 'queue-1', 'queue-2', 'apache')) {
        $identifiant = Lire-Pid $nom
        if (Vivant $identifiant) {
            Arreter-Arbre $identifiant
            Bien "$nom arrêté"
        }
    }

    for ($i = 1; $i -le 8; $i++) {
        $identifiant = Lire-Pid "php-cgi-$i"
        if (Vivant $identifiant) { Arreter-Arbre $identifiant }
    }
    Bien 'processus PHP arrêtés'

    if (Test-Path $runDir) { Remove-Item "$runDir\*.pid" -Force -ErrorAction SilentlyContinue }
    if (Test-Path $fichierUrl) { Remove-Item $fichierUrl -Force -ErrorAction SilentlyContinue }
}

if ($Arreter) { Tout-Arreter; Ecrire ''; exit 0 }

# ==================================================================== ÉTAT ====

if ($Etat) {
    Titre 'État du déploiement'

    $rouages = @(
        @{ Nom = 'apache';        Role = 'serveur web' },
        @{ Nom = 'php-cgi-1';     Role = 'PHP (FastCGI)' },
        @{ Nom = 'queue-1';       Role = "file d'attente" },
        @{ Nom = 'planificateur'; Role = 'tâches planifiées' },
        @{ Nom = 'tunnel';        Role = 'exposition HTTPS' }
    )

    foreach ($r in $rouages) {
        $identifiant = Lire-Pid $r.Nom
        if (Vivant $identifiant) { Bien "$($r.Role) — PID $identifiant" }
        else { Mal "$($r.Role) — arrêté" }
    }

    $sante = Interroger "http://127.0.0.1:$Port/api/v1/health"
    if ($sante) {
        # Surtout pas `$etat` : PowerShell ne distingue pas la casse, et la
        # variable ecraserait le commutateur `-Etat` de la ligne de commande.
        $verdict = ($sante | ConvertFrom-Json).data.status
        if ($verdict -eq 'healthy') { Bien "sonde : $verdict" } else { Mal "sonde : $verdict" }
    } else { Mal 'sonde injoignable' }

    if (Test-Path $fichierUrl) {
        Ecrire ''
        Ecrire "  Adresse : $((Get-Content $fichierUrl -Raw).Trim())" Yellow
    }
    Ecrire ''
    exit 0
}

# ============================================================= PRÉREQUIS ======

Titre 'Vérification des prérequis'

foreach ($outil in @(
    @{ Chemin = $php;         Quoi = 'PHP 8.3' },
    @{ Chemin = $phpCgi;      Quoi = 'PHP FastCGI' },
    @{ Chemin = $composer;    Quoi = 'Composer' },
    @{ Chemin = $httpd;       Quoi = 'Apache' },
    @{ Chemin = $mysql;       Quoi = 'MySQL' },
    @{ Chemin = $cloudflared; Quoi = 'cloudflared' }
)) {
    if (-not (Test-Path $outil.Chemin)) {
        Mal "$($outil.Quoi) introuvable : $($outil.Chemin)"
        exit 1
    }
}
Bien 'outils présents'

$version = & $php -r 'echo PHP_MAJOR_VERSION, chr(46), PHP_MINOR_VERSION;'
if ($version -ne '8.3') { Mal "PHP $version — la 8.3 est requise"; exit 1 }
Bien "PHP $version"

& $mysql -u root -e 'select 1' 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) { Mal 'MySQL ne répond pas — démarrez-le depuis XAMPP'; exit 1 }
Bien 'MySQL répond'

Tout-Arreter

# ======================================================= INSTALLATION ========

Titre 'Installation des fichiers'

New-Item -ItemType Directory -Force -Path $Racine, $runDir, $logsDir | Out-Null

<#
    `git archive` plutôt qu'une copie du dossier.

    Il ne livre QUE les fichiers suivis : ni `node_modules`, ni `vendor`, ni le
    `.env` de développement — qui porte un mot de passe et pointe sur la base de
    travail. Une copie brute embarquerait les trois, et la production servirait
    la base de développement sans que rien ne le signale.
#>
Push-Location $source
try {
    $archive = Join-Path $env:TEMP 'cyclo-deploiement.tar'
    & git archive --format=tar -o $archive HEAD 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Mal 'git archive a échoué'; exit 1 }

    # Le code est remplacé, jamais fusionné : un fichier supprimé dans le dépôt
    # doit disparaître de la production, sinon une ancienne route continuerait
    # d'exister en ligne des mois après avoir été retirée du code.
    foreach ($d in @('backend\app', 'backend\config', 'backend\database',
                     'backend\routes', 'backend\resources', 'backend\bootstrap')) {
        $cible = Join-Path $Racine $d
        if (Test-Path $cible) { Remove-Item $cible -Recurse -Force }
    }

    & tar -xf $archive -C $Racine
    if ($LASTEXITCODE -ne 0) { Mal "extraction impossible"; exit 1 }
    Remove-Item $archive -Force
} finally { Pop-Location }

# `git archive` ne livre que des fichiers : un dossier vide dont le seul
# contenu suivi est un `.gitignore` arrive bien, mais les sous-dossiers créés à
# l'exécution, non. Laravel échouerait au premier écrit de journal.
foreach ($d in @('storage\app\public', 'storage\framework\cache\data',
                 'storage\framework\sessions', 'storage\framework\views',
                 'storage\logs', 'bootstrap\cache')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $appDir $d) | Out-Null
}

Bien "code installé dans $Racine"

# Le web construit est COPIÉ, jamais reconstruit sur le serveur : la production
# n'a besoin ni de Node ni des 400 Mo de `node_modules`.
Titre "Construction de l'application web"

Push-Location (Join-Path $source 'backend')
try {
    & $php artisan cyclo:build-web --skip-install 2>&1 | Select-Object -Last 3 |
        ForEach-Object { Ecrire "      $_" DarkGray }
} finally { Pop-Location }

foreach ($d in @('app', 'assets')) {
    $depuis = Join-Path $source "backend\public\$d"
    $vers = Join-Path $publicDir $d
    if (Test-Path $vers) { Remove-Item $vers -Recurse -Force }
    if (Test-Path $depuis) { Copy-Item $depuis $vers -Recurse -Force }
}
Bien 'web construit et copié'

# ---------------------------------------------------------------- dépendances

Titre 'Dépendances PHP'

Push-Location $appDir
try {
    & $php $composer install --no-dev --optimize-autoloader --no-interaction --quiet 2>&1 |
        ForEach-Object { Ecrire "      $_" DarkGray }
    if ($LASTEXITCODE -ne 0) { Mal 'composer install a échoué'; exit 1 }
} finally { Pop-Location }

Bien 'dépendances de production installées (sans les outils de test)'

# ======================================================== BASE ET SECRETS =====

Titre 'Base de données'

$base = 'cyclo_dakar_prod'
$utilisateur = 'cyclo_prod'
$fichierEnv = Join-Path $appDir '.env'
$fichierSecret = Join-Path $Racine 'secrets.txt'

if ($Neuf -and (Test-Path $fichierSecret)) { Remove-Item $fichierSecret -Force }

if (Test-Path $fichierSecret) {
    $motDePasse = ((Get-Content $fichierSecret | Where-Object { $_ -like 'DB_PASSWORD=*' }) -split '=', 2)[1]
    $cle = ((Get-Content $fichierSecret | Where-Object { $_ -like 'APP_KEY=*' }) -split '=', 2)[1]
    Bien 'secrets existants réutilisés'
} else {
    # 32 octets tirés du générateur cryptographique du système, pas de
    # `Get-Random` : ce dernier est prévisible à partir de quelques tirages.
    $octets = [byte[]]::new(24)
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($octets)
    $motDePasse = [Convert]::ToBase64String($octets) -replace '[^A-Za-z0-9]', ''
    $cle = ''
    Bien 'mot de passe de base généré'
}

if ($Neuf) {
    & $mysql -u root -e "drop database if exists ``$base``;" 2>&1 | Out-Null
    Ecrire '      base précédente supprimée' DarkGray
}

<#
    UN COMPTE DÉDIÉ, JAMAIS `root`.

    Le développement utilise `root` sans mot de passe — commode sur une machine
    fermée. Une fois le service exposé à Internet, la moindre injection SQL
    donnerait alors accès à TOUTES les bases de la machine : les huit autres
    projets qui vivent dans le même MySQL, pas seulement le club.

    Ce compte ne peut rien en dehors de la base du club.
#>
& $mysql -u root -e @"
create database if not exists ``$base`` character set utf8mb4 collate utf8mb4_unicode_ci;
create user if not exists '$utilisateur'@'localhost' identified by '$motDePasse';
alter user '$utilisateur'@'localhost' identified by '$motDePasse';
grant select, insert, update, delete, create, alter, index, drop, references on ``$base``.* to '$utilisateur'@'localhost';
flush privileges;
"@ 2>&1 | Out-Null

if ($LASTEXITCODE -ne 0) { Mal 'création de la base impossible'; exit 1 }
Bien "base ``$base`` et compte ``$utilisateur`` prêts"

# ------------------------------------------------------------------- .env ----

$appUrl = "http://127.0.0.1:$Port"

$env:APP_ENV = 'production'
if (-not $cle) {
    Push-Location $appDir
    try {
        Set-Content $fichierEnv "APP_KEY=" -Encoding utf8
        $cle = (& $php artisan key:generate --show).Trim()
    } finally { Pop-Location }
}
Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue

@"
APP_NAME="Cyclo Dakar"
APP_ENV=production
APP_KEY=$cle
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Africa/Dakar
APP_LOCALE=fr

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$base
DB_USERNAME=$utilisateur
DB_PASSWORD=$motDePasse

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=database
CACHE_STORE=database

FILESYSTEM_DISK=local
"@ | Set-Content $fichierEnv -Encoding utf8

@"
Secrets du déploiement Cyclo Dakar — NE PAS PARTAGER, NE PAS VERSIONNER.
APP_KEY=$cle
DB_PASSWORD=$motDePasse
"@ | Set-Content $fichierSecret -Encoding utf8

Bien '.env de production écrit (APP_DEBUG=false)'

# ------------------------------------------------------------- migrations ----

Titre 'Migrations'

$r = Artisan @('migrate', '--force') -Silencieux
if ($r.Code -ne 0) { Mal "migrate a échoué`n$($r.Sortie)"; exit 1 }
Bien 'schéma à jour'

<#
    METTRE EN SERVICE UNE BASE QUI EXISTE DÉJÀ.

    Un club ne part jamais d'une page blanche : les membres, les cotisations et
    les sorties existent avant le déploiement. L'import se fait par
    `mysqldump`, qui produit un fichier rejouable — et non par une copie de
    fichiers, qui recopierait aussi la corruption d'une table sans le dire.

    Les migrations sont rejouées APRÈS l'import : la base d'origine peut être
    en retard d'une migration, et c'est justement le déploiement qui doit la
    rattraper.
#>
if ($ImporterDepuis) {
    Titre "Import de la base ``$ImporterDepuis``"

    $existe = (& $mysql -u root -N -e "show databases like '$ImporterDepuis';" 2>$null)
    if (-not $existe) { Mal "la base ``$ImporterDepuis`` n'existe pas"; exit 1 }

    $vidage = Join-Path $env:TEMP 'cyclo-import.sql'
    & $mysqldump -u root --single-transaction --routines --default-character-set=utf8mb4 `
        $ImporterDepuis --result-file=$vidage 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Mal 'mysqldump a échoué'; exit 1 }

    # On rejoue dans une base VIDÉE : sans cela, les lignes de l'ancienne
    # installation survivraient à côté des nouvelles, et les rapports
    # financiers compteraient deux fois les mêmes encaissements.
    & $mysql -u root -e "drop database if exists ``$base``; create database ``$base`` character set utf8mb4 collate utf8mb4_unicode_ci;" 2>&1 | Out-Null
    & $mysql -u root --default-character-set=utf8mb4 $base -e "source $($vidage -replace '\', '/')" 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Mal "l'import a échoué"; exit 1 }
    Remove-Item $vidage -Force -ErrorAction SilentlyContinue

    $lignes = (& $mysql -u root -N -e "select count(*) from ``$base``.members;" 2>$null)
    Bien "base importée ($lignes membres)"

    $r = Artisan @('migrate', '--force') -Silencieux
    if ($r.Code -ne 0) { Mal "migrate après import a échoué`n$($r.Sortie)"; exit 1 }
    Bien 'migrations rattrapées'
}

$aDesUtilisateurs = (& $mysql -u root -N -e "select count(*) from ``$base``.users;" 2>$null)
if ([int] $aDesUtilisateurs -eq 0) {
    Ecrire '      base vide : installation des comptes et des données de démonstration' DarkGray
    Artisan @('db:seed', '--force') -Silencieux | Out-Null
    Artisan @('cyclo:demo', '--force') -Silencieux | Out-Null
    Bien 'données initiales installées'
} else {
    Bien "base conservée ($aDesUtilisateurs comptes)"
}

Artisan @('storage:link') -Silencieux | Out-Null

# ------------------------------------------------------------------ caches ---

Titre 'Optimisation'

<#
    LES CACHES SONT SÛRS ICI, ET NE L'AURAIENT PAS ÉTÉ DANS LE DOSSIER DE TRAVAIL.

    `config:cache` fige les valeurs du `.env` dans un fichier PHP. Fait dans le
    dossier de développement, il ferait tourner la suite de tests sur la base de
    PRODUCTION : phpunit surcharge des variables d'environnement que la
    configuration mise en cache ne consulte plus.

    C'est la raison d'être du dossier séparé — pas seulement la propreté.
#>
foreach ($c in @('config:cache', 'route:cache', 'view:cache', 'event:cache')) {
    $r = Artisan @($c) -Silencieux
    if ($r.Code -ne 0) { Mal "$c a échoué`n$($r.Sortie)"; exit 1 }
}
Bien 'configuration, routes, vues et événements mis en cache'

# ==================================================== APACHE ET PHP-FCGI ======

Titre 'Serveur web'

$modele = Get-Content (Join-Path $PSScriptRoot 'apache\cyclo.conf') -Raw
$modele = $modele.Replace('@APACHE@',      $apacheRoot.Replace('\', '/'))
$modele = $modele.Replace('@PORT@',        "$Port")
$modele = $modele.Replace('@LOGS@',        $logsDir.Replace('\', '/'))
$modele = $modele.Replace('@PUBLIC@',      $publicDir.Replace('\', '/'))
$modele = $modele.Replace('@PUBLIC_FCGI@', $publicDir.Replace('\', '/'))
$modele = $modele.Replace('@PORT_PHP@',    "$($portBase + 1)")
Set-Content $confApache $modele -Encoding utf8

<#
    Apache ecrit « Syntax OK » sur la SORTIE D'ERREUR, comme beaucoup de
    programmes en ligne de commande. Avec `$ErrorActionPreference = 'Stop'`,
    PowerShell prend toute ligne d'erreur d'un programme natif pour une panne
    et interrompt le script — alors que la configuration est valide.

    On ne juge donc que le code de sortie, qui est le seul verdict fiable.
#>
$verdict = & {
    $ErrorActionPreference = 'Continue'
    & $httpd -f $confApache -t 2>&1
}
$codeApache = $LASTEXITCODE
$verdict | ForEach-Object { Ecrire "      $_" DarkGray }
if ($codeApache -ne 0) { Mal 'configuration Apache invalide'; exit 1 }

<#
    UN SEUL PROCESSUS PHP, ET C'EST UNE LIMITE ASSUMÉE.

    Sous Windows, `php-cgi` ne sait pas se dupliquer, et le répartiteur d'Apache
    refuse de relayer du FastCGI vers un chemin Windows. Lancer quatre processus
    ne servirait donc à rien : mesuré, huit requêtes en parallèle prennent
    exactement le même temps qu'en série.

    Une tentative de répartition par `<If>` a même servi le CODE SOURCE de
    `index.php` en clair lorsqu'aucune condition ne correspondait. Un aiguillage
    conditionnel vers PHP a pour cas de repli « fichier statique » : la règle
    reste donc inconditionnelle.

    Cela tient pour un club — Apache sert seul les fichiers statiques, qui font
    l'essentiel du trafic. La vraie réponse est PHP-FPM sur un hébergement
    Linux, où le problème n'existe pas.
#>
# PHP_FCGI_MAX_REQUESTS=0 : sans cela `php-cgi` s'arrête de lui-même au bout de
# 500 requêtes et Apache renverrait des 503 le temps qu'on le relance.
$env:PHP_FCGI_MAX_REQUESTS = '0'
$p = Start-Process -FilePath $phpCgi `
    -ArgumentList (@('-b', "127.0.0.1:$($portBase + 1)") + (Php-Prod)) `
    -WindowStyle Hidden -PassThru
Noter-Pid 'php-cgi-1' $p.Id
Remove-Item Env:PHP_FCGI_MAX_REQUESTS -ErrorAction SilentlyContinue
Bien "PHP en écoute sur 127.0.0.1:$($portBase + 1)"

$apache = Start-Process -FilePath $httpd -ArgumentList '-f', $confApache `
    -WindowStyle Hidden -PassThru
Noter-Pid 'apache' $apache.Id
Start-Sleep -Seconds 2

$local = Interroger "http://127.0.0.1:$Port/api/v1/health" 6
if (-not $local) {
    Mal 'Apache ne répond pas.'
    Ecrire "      Voir $logsDir\apache-erreurs.log" DarkGray
    exit 1
}
Bien "Apache écoute sur 127.0.0.1:$Port"

# ============================================== OUVRIERS ET PLANIFICATEUR =====

Titre "File d'attente et tâches planifiées"

$avecEnv = "`$env:APP_ENV='production'; Set-Location '$appDir';"

for ($i = 1; $i -le 2; $i++) {
    Lancer-Boucle "queue-$i" "$avecEnv & '$php' artisan queue:work --sleep=3 --tries=3 --max-time=3600"
}
Bien "2 ouvriers de file"

# `schedule:work` remplace cron, absent de Windows. Il déclenche les tâches à
# chaque minute — dont le battement que la sonde surveille.
Lancer-Boucle 'planificateur' "$avecEnv & '$php' artisan schedule:work"
Bien 'planificateur lancé'

# ==================================================================== TUNNEL ==

Titre 'Exposition HTTPS'

$journal = Join-Path $logsDir 'tunnel.log'
if (Test-Path $journal) { Remove-Item $journal -Force }

$t = Start-Process -FilePath $cloudflared `
    -ArgumentList 'tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$Port", `
                  '--logfile', $journal `
    -WindowStyle Hidden -PassThru
Noter-Pid 'tunnel' $t.Id

$adresse = $null
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Seconds 1
    if (-not (Test-Path $journal)) { continue }
    $trouve = Select-String -Path $journal -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches |
        Select-Object -First 1
    if ($trouve) { $adresse = $trouve.Matches[0].Value; break }
}

if (-not $adresse) { Mal "le tunnel n'a pas fourni d'adresse — voir $journal"; exit 1 }
Bien "adresse obtenue"

# ======================================================== VÉRIFICATION ========

Titre 'Vérification par l''adresse publique'

<#
    LA VÉRIFICATION PASSE PAR L'EXTÉRIEUR, PAS PAR 127.0.0.1.

    Tout peut aller bien en local et rester injoignable : un tunnel qui annonce
    son adresse avant d'avoir fini de s'enregistrer, un nom qui met quelques
    secondes à se résoudre. Vérifier en local puis annoncer l'adresse publique,
    c'est affirmer ce qu'on n'a pas testé.

    On réessaie plutôt que de conclure trop vite : les premiers appels échouent
    presque toujours, et un faux « injoignable » ferait croire à une panne.
#>
# Un tunnel annonce son adresse AVANT d'avoir fini de s'enregistrer : le nom
# met une trentaine de secondes à se résoudre partout. Conclure au bout de
# vingt secondes ferait déclarer en panne un déploiement parfaitement sain —
# et c'est ce qui est arrivé au premier essai.
$reponse = $null
for ($i = 0; $i -lt 30; $i++) {
    $reponse = Interroger "$adresse/api/v1/health" 1
    if ($reponse) { break }
    Start-Sleep -Seconds 3
}
if (-not $reponse) { Mal "l'adresse publique ne répond pas après 90 s"; exit 1 }

$sante = $reponse | ConvertFrom-Json
Bien "l'API répond publiquement"

# Le planificateur bat toutes les minutes : au premier démarrage, la sonde est
# légitimement « degraded » le temps du premier battement. On attend ce
# battement plutôt que d'annoncer un service dont un rouage n'a pas fait ses
# preuves.
if ($sante.data.status -ne 'healthy') {
    Ecrire '      attente du premier battement du planificateur…' DarkGray
    for ($i = 0; $i -lt 24; $i++) {
        Start-Sleep -Seconds 5
        $reponse = Interroger "$adresse/api/v1/health" 2
        if ($reponse) {
            $sante = $reponse | ConvertFrom-Json
            if ($sante.data.status -eq 'healthy') { break }
        }
    }
}

foreach ($nom in $sante.data.checks.PSObject.Properties.Name) {
    $c = $sante.data.checks.$nom
    if ($c.ok) { Bien "$nom : $($c.message)" } else { Mal "$nom : $($c.message)" }
}

if ($sante.data.status -ne 'healthy') {
    Mal "Déploiement en service mais DÉGRADÉ ($($sante.data.status))."
    Ecrire '      L''adresse fonctionne ; un rouage ne fait pas son travail.' DarkGray
    Ecrire "      $adresse" Yellow
    exit 2
}

<#
    LA VÉRIFICATION LA PLUS IMPORTANTE : UN APPEL AUTHENTIFIÉ.

    La sonde de santé est une route PUBLIQUE. Elle répondait parfaitement
    pendant que PLUS AUCUN MEMBRE ne pouvait utiliser l'application : Apache ne
    transmettait pas l'en-tête `Authorization` au FastCGI, si bien que la
    connexion réussissait, rendait un jeton… et que chaque appel suivant
    répondait 401.

    Le symptôme est trompeur — on soupçonne le jeton, le mobile, l'horloge —
    alors que le serveur ne l'a jamais reçu. Un déploiement qui ne teste que des
    routes publiques ne teste pas l'application.

    Le jeton est fabriqué côté serveur puis révoqué aussitôt : il ne sert qu'à
    cette vérification et ne lui survit pas.
#>
Titre 'Vérification d''un appel authentifié'

$sonde = Join-Path $appDir 'verification-deploiement.php'
@'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$membre = App\Models\User::query()->orderBy('id')->first();
echo $membre ? $membre->createToken('verification-deploiement')->plainTextToken : '';
'@ | Set-Content $sonde -Encoding utf8

Push-Location $appDir
try {
    $env:APP_ENV = 'production'
    $jeton = (& $php 'verification-deploiement.php' 2>&1 | Select-Object -Last 1).ToString().Trim()
} finally {
    Remove-Item $sonde -Force -ErrorAction SilentlyContinue
    Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue
    Pop-Location
}

if ($jeton -notmatch '^\d+\|') { Mal 'impossible de fabriquer un jeton de vérification'; exit 1 }

$protegee = "$adresse/api/v1/members"

$avecJeton = 0
try {
    $avecJeton = (Invoke-WebRequest -Uri $protegee -UseBasicParsing -TimeoutSec 25 `
        -Headers @{ Authorization = "Bearer $jeton"; Accept = 'application/json' }).StatusCode
} catch { $avecJeton = [int] $_.Exception.Response.StatusCode }

# Et l'inverse. Une route protégée qui répondrait 200 sans jeton serait ouverte
# à tout internet — la vérifier coûte une requête.
$sansJeton = 0
try {
    $sansJeton = (Invoke-WebRequest -Uri $protegee -UseBasicParsing -TimeoutSec 25 `
        -Headers @{ Accept = 'application/json' }).StatusCode
} catch { $sansJeton = [int] $_.Exception.Response.StatusCode }

if ($avecJeton -ne 200) {
    Mal "un appel authentifié répond $avecJeton au lieu de 200."
    Ecrire "      L'en-tête Authorization n'atteint pas PHP : vérifiez CGIPassAuth." DarkGray
    exit 1
}
Bien 'un membre connecté est bien servi (200)'

if ($sansJeton -ne 401) {
    Mal "une route protégée répond $sansJeton sans jeton — elle est OUVERTE."
    exit 1
}
Bien 'une route protégée refuse sans jeton (401)'

if ($sante.data.environment -ne 'production') {
    Mal "L'application se croit en « $($sante.data.environment) » — arrêt."
    exit 1
}

Set-Content $fichierUrl $adresse -Encoding ascii

Write-Host ''
Write-Host '  ============================================================' -ForegroundColor Green
Write-Host '   DEPLOIEMENT EN SERVICE' -ForegroundColor Green
Write-Host '  ============================================================' -ForegroundColor Green
Write-Host ''
Write-Host "   $adresse" -ForegroundColor Yellow
Write-Host ''
Ecrire "   Environnement  production, APP_DEBUG=false"
Ecrire "   Base           $base (compte $utilisateur, pas root)"
Ecrire "   Serveur        Apache + PHP FastCGI (une requete PHP a la fois)"
Ecrire "   Arriere-plan   2 ouvriers de file, 1 planificateur"
Ecrire "   Installe dans  $Racine"
Write-Host ''
Ecrire "   L'adresse ne repond que PC allume, et change a chaque"
Ecrire "   redemarrage du tunnel. Pour un hebergement permanent," DarkGray
Ecrire "   voir docs/deployment.md section 11." DarkGray
Write-Host ''
Ecrire "   Etat    .\deploiement\deployer.ps1 -Etat"
Ecrire "   Arret   .\deploiement\deployer.ps1 -Arreter"
Write-Host ''
