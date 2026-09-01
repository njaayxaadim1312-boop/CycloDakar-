<#
    CYCLO DAKAR — Démonstration accessible depuis un téléphone
    ==========================================================

    Publie l'application sur une adresse HTTPS publique, gratuitement et sans
    compte, via un tunnel rapide Cloudflare.

        .\demarrer-demo.ps1              # démarre tout
        .\demarrer-demo.ps1 -Rebuild     # reconstruit le web au passage
        .\demarrer-demo.ps1 -Arreter     # arrête tout

    Pourquoi une seule adresse ?
    ----------------------------
    Laravel sert le web construit ET l'API. Avec deux origines il faudrait
    configurer CORS, ouvrir deux tunnels et tenir deux URL à jour dans trois
    fichiers ; avec une seule, `VITE_API_URL=/api/v1` fonctionne tel quel.

    Sécurité
    --------
    L'adresse est publique : quiconque la connaît atteint votre machine.
    Le script force donc APP_DEBUG=false — sinon la moindre erreur afficherait
    une trace contenant vos identifiants MySQL. Il ne modifie PAS votre `.env` :
    la valeur est passée en variable d'environnement, uniquement pour ce
    processus.

    L'adresse change à chaque démarrage (c'est le principe d'un tunnel rapide)
    et cesse d'exister dès que vous arrêtez le script.
#>

param(
    [switch] $Rebuild,
    [switch] $Arreter
)

$ErrorActionPreference = 'Stop'

$racine = $PSScriptRoot
$backend = Join-Path $racine 'backend'
$cloudflared = Join-Path $racine 'tools\cloudflared.exe'
$journal = Join-Path $env:TEMP 'cyclo-tunnel.log'

function Ecrire($texte, $couleur = 'Gray') { Write-Host $texte -ForegroundColor $couleur }

# ---------------------------------------------------------------- arrêt -----
if ($Arreter) {
    Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
        Where-Object { $_.CommandLine -like '*artisan serve*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    Ecrire "`n  Demonstration arretee.`n" 'Yellow'
    return
}

Ecrire "`n  CYCLO DAKAR — demonstration publique`n" 'Yellow'

# ------------------------------------------------------------- MySQL --------
if (-not (Get-Process -Name mysqld -ErrorAction SilentlyContinue)) {
    $mysqld = 'C:\xampp\mysql\bin\mysqld.exe'

    if (-not (Test-Path $mysqld)) {
        Ecrire "  MySQL introuvable ($mysqld). Demarrez-le depuis le panneau XAMPP." 'Red'
        return
    }

    Ecrire '  Demarrage de MySQL...'
    Start-Process -FilePath $mysqld -ArgumentList '--standalone' -WindowStyle Hidden
    Start-Sleep -Seconds 6
}
Ecrire '  MySQL : en marche' 'Green'

# --------------------------------------------------------- cloudflared ------
if (-not (Test-Path $cloudflared)) {
    Ecrire '  Telechargement de cloudflared...'
    New-Item -ItemType Directory (Split-Path $cloudflared) -Force | Out-Null
    Invoke-WebRequest -UseBasicParsing `
        -Uri 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' `
        -OutFile $cloudflared
}

# ------------------------------------------------------ construction web ----
$index = Join-Path $backend 'public\app\index.html'

if ($Rebuild -or -not (Test-Path $index)) {
    Ecrire '  Construction de l''application web (1 a 2 minutes)...'
    Push-Location $backend
    php artisan cyclo:build-web
    Pop-Location
}
Ecrire '  Web : construit' 'Green'

# ------------------------------------------------------------ tunnel --------
Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
if (Test-Path $journal) { Remove-Item $journal }

Ecrire '  Ouverture du tunnel...'
Start-Process -FilePath $cloudflared -WindowStyle Hidden `
    -ArgumentList 'tunnel','--url','http://127.0.0.1:8000','--no-autoupdate','--logfile',$journal

# L'adresse n'apparait dans le journal qu'apres la poignee de main : on
# attend qu'elle arrive plutot que de dormir un temps arbitraire.
$adresse = $null
foreach ($essai in 1..40) {
    Start-Sleep -Milliseconds 700

    if (Test-Path $journal) {
        $trouve = Select-String -Path $journal -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' |
            Select-Object -Last 1

        if ($trouve) {
            $adresse = $trouve.Matches[0].Value
            break
        }
    }
}

if (-not $adresse) {
    Ecrire "  Le tunnel n'a pas repondu. Journal : $journal" 'Red'
    return
}

# ------------------------------------------------------------ Laravel -------
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
    Where-Object { $_.CommandLine -like '*artisan serve*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }

# Variables passees au PROCESSUS, pas ecrites dans .env : le developpement
# local garde sa configuration intacte.
$env:APP_DEBUG = 'false'
$env:APP_URL = $adresse
$env:FRONTEND_URL = $adresse

Push-Location $backend
Start-Process -FilePath 'php' -ArgumentList 'artisan','serve','--port=8000' -WindowStyle Hidden
Pop-Location

Start-Sleep -Seconds 4

# ------------------------------------------------------------ controle ------
try {
    $sante = Invoke-RestMethod -Uri "$adresse/api/v1/health" -TimeoutSec 25
    $etat = $sante.data.status
} catch {
    $etat = 'injoignable'
}

Ecrire ''
Ecrire '  ============================================================' 'Yellow'
Ecrire "   $adresse" 'Green'
Ecrire '  ============================================================' 'Yellow'
Ecrire ''
Ecrire "  API : $etat"
Ecrire '  Ouvrez cette adresse sur vos telephones, meme en 4G.'
Ecrire ''
Ecrire '  Comptes de demonstration (mot de passe : CycloDakar2026!)' 'Cyan'
Ecrire '    membre@cyclodakar.sn     anneaux remplis, aucun outil de gestion'
Ecrire '    tresorier@cyclodakar.sn  le bouton « Gestion du club » apparait'
Ecrire '    admin@cyclodakar.sn      tout'
Ecrire ''
Ecrire '  L''adresse CHANGE a chaque demarrage et disparait a l''arret.' 'DarkGray'
Ecrire '  Pour arreter :  .\demarrer-demo.ps1 -Arreter' 'DarkGray'
Ecrire ''
