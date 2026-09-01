<#
    CYCLO DAKAR - Demonstration accessible depuis un telephone
    ==========================================================

        .\demarrer-demo.ps1              demarre tout et affiche l'adresse
        .\demarrer-demo.ps1 -Rebuild     reconstruit le web au passage
        .\demarrer-demo.ps1 -Stable      adresse FIXE via Serveo (cle a enregistrer une fois)
        .\demarrer-demo.ps1 -Installer   demarrage automatique a l'ouverture de session
        .\demarrer-demo.ps1 -Adresse     affiche l'adresse en cours
        .\demarrer-demo.ps1 -Arreter     arrete tout

    Deux facons d'exposer l'application
    -----------------------------------
    Cloudflare (defaut)  aucun compte, mais l'adresse CHANGE a chaque demarrage.
    Serveo (-Stable)     adresse fixe https://cyclodakar.serveo.net, apres avoir
                         enregistre une cle SSH une seule fois (connexion Google
                         ou GitHub). Le script donne le lien exact.

    Dans les deux cas c'est VOTRE machine qui sert : l'adresse ne repond que
    lorsque le PC est allume.

    Pourquoi une seule adresse pour le web et l'API
    -----------------------------------------------
    Laravel sert le web construit ET l'API. Avec deux origines il faudrait
    configurer CORS, ouvrir deux tunnels et tenir deux URL a jour dans trois
    fichiers ; avec une seule, VITE_API_URL=/api/v1 fonctionne tel quel.

    Securite
    --------
    L'adresse est publique. Le script force APP_DEBUG=false, sinon la moindre
    erreur afficherait une trace contenant vos identifiants MySQL. La valeur
    est passee au PROCESSUS : votre fichier .env n'est pas modifie.
#>

param(
    [switch] $Rebuild,
    [switch] $Stable,
    [switch] $Installer,
    [switch] $Adresse,
    [switch] $Arreter
)

$ErrorActionPreference = 'Stop'

$racine       = $PSScriptRoot
$backend      = Join-Path $racine 'backend'
$cloudflared  = Join-Path $racine 'tools\cloudflared.exe'
$journal      = Join-Path $env:TEMP 'cyclo-tunnel.log'
$fichierUrl   = Join-Path $racine 'ADRESSE-DEMO.txt'
$sousDomaine  = 'cyclodakar'

function Ecrire($texte, $couleur = 'Gray') { Write-Host $texte -ForegroundColor $couleur }

<#
    L'adresse sert-elle vraiment l'API ?

    Un tunnel annonce son adresse avant d'avoir fini de s'enregistrer : le
    premier appel echoue presque toujours, et le nom met quelques secondes a se
    resoudre. On reessaie donc au lieu de conclure trop vite — un faux
    « injoignable » ferait croire a une panne alors que tout va bien.
#>
function Tester-Adresse($url) {
    foreach ($essai in 1..8) {
        try {
            $reponse = Invoke-RestMethod -Uri "$url/api/v1/health" -TimeoutSec 15

            if ($reponse.data.status) { return $true }
        } catch {
            Start-Sleep -Seconds 4
        }
    }

    return $false
}

# ------------------------------------------------------------ adresse ------
if ($Adresse) {
    if (Test-Path $fichierUrl) {
        Ecrire "`n  $(Get-Content $fichierUrl -Raw)`n" 'Green'
    } else {
        Ecrire "`n  Aucune demonstration en cours.`n" 'Yellow'
    }
    return
}

# -------------------------------------------------------------- arret ------
if ($Arreter) {
    Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
    Get-CimInstance Win32_Process -Filter "Name = 'ssh.exe'" |
        Where-Object { $_.CommandLine -like '*serveo.net*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
        Where-Object { $_.CommandLine -like '*artisan serve*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    Remove-Item $fichierUrl -ErrorAction SilentlyContinue
    Ecrire "`n  Demonstration arretee.`n" 'Yellow'
    return
}

# ---------------------------------------------------------- installation ---
if ($Installer) {
    $tache = 'CycloDakar-Demo'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' `
        -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$PSCommandPath`""
    $declencheur = New-ScheduledTaskTrigger -AtLogOn
    $reglages = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

    Register-ScheduledTask -TaskName $tache -Action $action -Trigger $declencheur `
        -Settings $reglages -Force | Out-Null

    Ecrire "`n  Demarrage automatique installe (tache « $tache »)." 'Green'
    Ecrire "  L'application repartira seule a chaque ouverture de session."
    Ecrire "  Pour l'enlever : Unregister-ScheduledTask -TaskName $tache`n" 'DarkGray'
    return
}

Ecrire "`n  CYCLO DAKAR - demonstration publique`n" 'Yellow'

# -------------------------------------------------------------- MySQL ------
if (-not (Get-Process -Name mysqld -ErrorAction SilentlyContinue)) {
    $mysqld = 'C:\xampp\mysql\bin\mysqld.exe'

    if (-not (Test-Path $mysqld)) {
        Ecrire "  MySQL introuvable : $mysqld" 'Red'
        Ecrire '  Demarrez-le depuis le panneau XAMPP, puis relancez.' 'Red'
        return
    }

    Ecrire '  Demarrage de MySQL...'
    Start-Process -FilePath $mysqld -ArgumentList '--standalone' -WindowStyle Hidden
    Start-Sleep -Seconds 6
}
Ecrire '  MySQL : en marche' 'Green'

# --------------------------------------------------- construction du web ---
$index = Join-Path $backend 'public\app\index.html'

if ($Rebuild -or -not (Test-Path $index)) {
    Ecrire '  Construction de l''application web (1 a 2 minutes)...'
    Push-Location $backend
    php artisan cyclo:build-web
    Pop-Location
}
Ecrire '  Web : construit' 'Green'

# ------------------------------------------------------------ Laravel ------
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
    Where-Object { $_.CommandLine -like '*artisan serve*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }

# Variables passees au PROCESSUS, pas ecrites dans .env : la configuration de
# developpement reste intacte.
$env:APP_DEBUG = 'false'

Push-Location $backend
Start-Process -FilePath 'php' -ArgumentList 'artisan','serve','--port=8000' -WindowStyle Hidden
Pop-Location
Start-Sleep -Seconds 4
Ecrire '  Laravel : en ecoute sur 8000' 'Green'

# ------------------------------------------------------------- tunnel ------
Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
Get-CimInstance Win32_Process -Filter "Name = 'ssh.exe'" |
    Where-Object { $_.CommandLine -like '*serveo.net*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }

Remove-Item $journal,"$journal.err" -ErrorAction SilentlyContinue
$urlPublique = $null

if ($Stable) {
    # ---- Serveo : adresse fixe, cle SSH a enregistrer une seule fois -------
    $cle = "$env:USERPROFILE\.ssh\id_ed25519"

    if (-not (Test-Path $cle)) {
        New-Item -ItemType Directory "$env:USERPROFILE\.ssh" -Force | Out-Null
        ssh-keygen -t ed25519 -N '""' -C 'cyclodakar' -f $cle | Out-Null
    }

    Ecrire '  Ouverture du tunnel Serveo...'
    Start-Process ssh -WindowStyle Hidden -RedirectStandardOutput $journal `
        -RedirectStandardError "$journal.err" `
        -ArgumentList '-o','StrictHostKeyChecking=no','-o','ServerAliveInterval=30',
                      '-R',"${sousDomaine}:80:127.0.0.1:8000",'serveo.net'

    foreach ($essai in 1..30) {
        Start-Sleep -Milliseconds 700

        if (Test-Path $journal) {
            $contenu = Get-Content $journal -Raw

            if ($contenu -match 'register your SSH public key') {
                $lien = ([regex]'https://console\.serveo\.net/ssh/keys\?add=\S+').Match($contenu).Value

                Ecrire ''
                Ecrire '  Une seule etape, a faire une fois :' 'Yellow'
                Ecrire "  1. Ouvrez  $lien" 'Cyan'
                Ecrire '  2. Connectez-vous avec Google ou GitHub (la cle s''enregistre seule).'
                Ecrire '  3. Relancez :  .\demarrer-demo.ps1 -Stable'
                Ecrire ''
                Ecrire "  Vous aurez alors l'adresse fixe  https://$sousDomaine.serveo.net" 'Green'
                Ecrire ''
                return
            }

            $trouve = ([regex]'https://\S+\.serveo(?:usercontent)?\.(?:net|com)').Match($contenu)
            if ($trouve.Success) { $urlPublique = $trouve.Value; break }
        }
    }
} else {
    # ---- Cloudflare : aucun compte, adresse changeante ---------------------
    if (-not (Test-Path $cloudflared)) {
        Ecrire '  Telechargement de cloudflared...'
        New-Item -ItemType Directory (Split-Path $cloudflared) -Force | Out-Null
        Invoke-WebRequest -UseBasicParsing `
            -Uri 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' `
            -OutFile $cloudflared
    }

    <#
        Cloudflare annonce une adresse des le demarrage, mais le tunnel peut
        rester bloque apres son auto-diagnostic — cela arrive quand plusieurs
        tunnels rapides sont crees coup sur coup. On obtient alors une adresse
        qui ne repond a rien.

        On VERIFIE donc que l'adresse sert reellement avant de l'annoncer, et
        on relance le tunnel si ce n'est pas le cas. Annoncer une adresse morte
        ferait perdre bien plus de temps que ces quelques secondes d'attente.
    #>
    foreach ($tentative in 1..3) {
        Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
        Remove-Item $journal -ErrorAction SilentlyContinue

        if ($tentative -eq 1) {
            Ecrire '  Ouverture du tunnel Cloudflare...'
        } else {
            Ecrire "  Le tunnel n'a pas repondu, nouvelle tentative ($tentative/3)..." 'Yellow'
            # Cloudflare limite les creations rapprochees : on laisse passer.
            Start-Sleep -Seconds 10
        }

        Start-Process -FilePath $cloudflared -WindowStyle Hidden `
            -ArgumentList 'tunnel','--url','http://127.0.0.1:8000','--no-autoupdate','--logfile',$journal

        $candidate = $null

        foreach ($essai in 1..40) {
            Start-Sleep -Milliseconds 700

            if (Test-Path $journal) {
                $trouve = Select-String -Path $journal -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' |
                    Select-Object -Last 1

                if ($trouve) { $candidate = $trouve.Matches[0].Value; break }
            }
        }

        if ($candidate -and (Tester-Adresse $candidate)) {
            $urlPublique = $candidate
            break
        }
    }
}

if (-not $urlPublique) {
    Ecrire "  Le tunnel n'a pas repondu. Journal : $journal" 'Red'
    return
}

# Sans BOM : ce fichier est relu par des scripts, et un BOM se retrouverait
# colle devant le « h » de « https ».
[IO.File]::WriteAllText($fichierUrl, $urlPublique, [Text.UTF8Encoding]::new($false))

# ------------------------------------------------------------ controle -----
$etat = if (Tester-Adresse $urlPublique) { 'en ligne' } else { 'injoignable' }

Ecrire ''
Ecrire '  ============================================================' 'Yellow'
Ecrire "   $urlPublique" 'Green'
Ecrire '  ============================================================' 'Yellow'
Ecrire ''
Ecrire "  API : $etat"
Ecrire '  Ouvrez cette adresse sur vos telephones, meme en 4G.'
Ecrire ''
Ecrire '  Comptes (mot de passe : CycloDakar2026!)' 'Cyan'
Ecrire '    membre@cyclodakar.sn     anneaux remplis, bouton Demarrer'
Ecrire '    tresorier@cyclodakar.sn  le bouton « Gestion du club » apparait'
Ecrire '    admin@cyclodakar.sn      tout'
Ecrire ''
Ecrire "  Adresse egalement notee dans  ADRESSE-DEMO.txt" 'DarkGray'
Ecrire '  La retrouver :   .\demarrer-demo.ps1 -Adresse' 'DarkGray'
Ecrire '  Adresse fixe :   .\demarrer-demo.ps1 -Stable' 'DarkGray'
Ecrire '  Au demarrage :   .\demarrer-demo.ps1 -Installer' 'DarkGray'
Ecrire '  Arreter :        .\demarrer-demo.ps1 -Arreter' 'DarkGray'
Ecrire ''
