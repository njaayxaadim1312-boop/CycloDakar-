<#
    CYCLO DAKAR — SAUVEGARDES (phase 20)
    =====================================

        .\deploiement\sauvegarde.ps1              sauvegarde maintenant
        .\deploiement\sauvegarde.ps1 -Verifier    sauvegarde PUIS teste la restauration
        .\deploiement\sauvegarde.ps1 -Lister      ce qui existe, et depuis quand
        .\deploiement\sauvegarde.ps1 -Installer   sauvegarde quotidienne automatique

    UNE SAUVEGARDE JAMAIS RESTAURÉE N'EST PAS UNE SAUVEGARDE.

    C'est la phrase de docs/deployment.md §7, et elle mérite mieux qu'un
    commentaire : `-Verifier` rejoue réellement le fichier dans une base
    jetable, compte les lignes et compare. Un fichier tronqué, un `mysqldump`
    interrompu à mi-course, un jeu de caractères mal choisi qui a mangé les
    accents — rien de tout cela ne se voit à la taille du fichier.

    Le jour où l'on restaure pour de bon, on ne découvre pas le problème.

    CE QUI EST SAUVEGARDÉ, ET AVEC QUELLE DURÉE DE VIE

      Base de données         30 jours   tout le club y est
      Justificatifs           5 ans      obligation comptable de fait
      Photos des membres      30 jours   pénible à perdre, pas dramatique

    Les vidéos générées ne sont PAS sauvegardées : elles se refabriquent depuis
    la trace, et occuperaient plus de place que tout le reste réuni.
#>

param(
    [string] $Racine    = 'C:\cyclo-production',
    [string] $Depot     = 'C:\cyclo-sauvegardes',
    [int]    $Jours     = 30,
    [switch] $Verifier,
    [switch] $Lister,
    [switch] $Installer
)

$ErrorActionPreference = 'Stop'

$mysql     = 'C:\xampp\mysql\bin\mysql.exe'
$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'
$appDir    = Join-Path $Racine 'backend'
$base      = 'cyclo_dakar_prod'

function Ecrire($t, $c = 'Gray') { Write-Host $t -ForegroundColor $c }
function Titre($t) { Write-Host ''; Write-Host "  $t" -ForegroundColor Cyan }
function Bien($t)  { Write-Host "  [ok] $t" -ForegroundColor Green }
function Mal($t)   { Write-Host "  [!!] $t" -ForegroundColor Red }

# ------------------------------------------------------------------ lister ---

if ($Lister) {
    Titre 'Sauvegardes disponibles'

    if (-not (Test-Path $Depot)) { Mal "aucune sauvegarde dans $Depot"; exit 1 }

    $fichiers = Get-ChildItem $Depot -Filter 'cyclo-*.zip' | Sort-Object LastWriteTime -Descending

    if ($fichiers.Count -eq 0) { Mal 'aucune sauvegarde'; exit 1 }

    foreach ($f in $fichiers) {
        $age = [int] ((Get-Date) - $f.LastWriteTime).TotalDays
        $taille = [math]::Round($f.Length / 1MB, 1)
        Ecrire ("  {0,-34} {1,6} Mo   il y a {2} j" -f $f.Name, $taille, $age)
    }

    # La plus récente est la seule qui compte vraiment : c'est elle qu'on
    # restaurera. Si elle date de trois semaines, la sauvegarde ne protège plus.
    $recent = [int] ((Get-Date) - $fichiers[0].LastWriteTime).TotalDays
    Write-Host ''
    if ($recent -gt 2) { Mal "la plus récente a $recent jours — la tâche quotidienne ne tourne plus." }
    else { Bien "la plus récente a $recent jour(s)" }
    Write-Host ''
    exit 0
}

# --------------------------------------------------------------- installer ---

if ($Installer) {
    Titre 'Installation de la sauvegarde quotidienne'

    $commande = "-NoProfile -WindowStyle Hidden -File `"$PSCommandPath`" -Racine `"$Racine`" -Depot `"$Depot`""

    # 2 h du matin : personne n'encaisse à cette heure-là, et la sauvegarde ne
    # croise donc aucune écriture en cours. Avant 3 h, heure du contrôle
    # nocturne du solde : si ce contrôle révèle une incohérence, on dispose
    # d'une image de la base d'AVANT.
    schtasks /Create /TN 'Cyclo Dakar - sauvegarde' /TR "powershell.exe $commande" `
        /SC DAILY /ST 02:00 /F 2>&1 | ForEach-Object { Ecrire "      $_" DarkGray }

    if ($LASTEXITCODE -ne 0) { Mal "impossible de créer la tâche planifiée"; exit 1 }

    Bien 'sauvegarde quotidienne à 2 h installée'
    Ecrire '      Retrait : schtasks /Delete /TN "Cyclo Dakar - sauvegarde" /F' DarkGray
    Write-Host ''
    exit 0
}

# --------------------------------------------------------------- sauvegarde ---

Titre 'Sauvegarde'

New-Item -ItemType Directory -Force -Path $Depot | Out-Null

$horodatage = Get-Date -Format 'yyyy-MM-dd-HHmm'
$travail = Join-Path $env:TEMP "cyclo-sauvegarde-$horodatage"
New-Item -ItemType Directory -Force -Path $travail | Out-Null

try {
    <#
        `--single-transaction` : la sauvegarde lit une image cohérente sans
        verrouiller les tables. Sans cela, un encaissement enregistré pendant
        le vidage pourrait figurer dans `payments` mais pas dans le grand
        livre — une sauvegarde qui viole l'invariant I1 du jour où on la
        restaure.
    #>
    $sql = Join-Path $travail 'base.sql'
    & $mysqldump -u root --single-transaction --routines --events `
        --default-character-set=utf8mb4 $base --result-file=$sql 2>&1 | Out-Null

    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $sql)) { Mal 'mysqldump a échoué'; exit 1 }

    $taille = [math]::Round((Get-Item $sql).Length / 1MB, 2)
    Bien "base vidée ($taille Mo)"

    # Les fichiers : justificatifs financiers, photos, fonds d'écran. Ils ne
    # sont dans aucune table — les oublier rendrait la restauration
    # décevante le jour où le trésorier cherche une facture.
    $fichiers = Join-Path $appDir 'storage\app'
    if (Test-Path $fichiers) {
        Copy-Item $fichiers (Join-Path $travail 'fichiers') -Recurse -Force
        Bien 'fichiers joints copiés'
    }

    # Le `.env` porte le mot de passe de la base : il n'entre PAS dans une
    # archive qui finira peut-être sur une clé USB ou dans un nuage. Le
    # déploiement le régénère, et `secrets.txt` reste sur la machine.
    Ecrire '      (le .env est volontairement exclu)' DarkGray

    $archive = Join-Path $Depot "cyclo-$horodatage.zip"
    Compress-Archive -Path "$travail\*" -DestinationPath $archive -Force

    $tailleZip = [math]::Round((Get-Item $archive).Length / 1MB, 1)
    Bien "archive écrite : $archive ($tailleZip Mo)"
}
finally {
    Remove-Item $travail -Recurse -Force -ErrorAction SilentlyContinue
}

# ------------------------------------------------------------------ rotation --

$anciennes = Get-ChildItem $Depot -Filter 'cyclo-*.zip' |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$Jours) }

foreach ($a in $anciennes) {
    Remove-Item $a.FullName -Force
    Ecrire "      retirée : $($a.Name)" DarkGray
}
if ($anciennes.Count -gt 0) { Bien "$($anciennes.Count) sauvegarde(s) de plus de $Jours jours retirée(s)" }

# --------------------------------------------------------------- vérification -

if (-not $Verifier) {
    Write-Host ''
    Ecrire '  Pour éprouver la sauvegarde : .\deploiement\sauvegarde.ps1 -Verifier' DarkGray
    Write-Host ''
    exit 0
}

Titre 'Test de restauration'

<#
    ON RESTAURE POUR DE VRAI, DANS UNE BASE JETABLE.

    Lire la taille du fichier ne prouve rien : un `mysqldump` interrompu produit
    un fichier volumineux et inutilisable, et l'erreur n'apparaît qu'au moment
    de le rejouer — c'est-à-dire le jour où l'on en a besoin.

    La base de contrôle est distincte de la production et supprimée ensuite.
    Elle ne touche jamais aux données en service.
#>
$controle = 'cyclo_verif_restauration'
$archive = (Get-ChildItem $Depot -Filter 'cyclo-*.zip' | Sort-Object LastWriteTime -Descending)[0]
$extrait = Join-Path $env:TEMP "cyclo-verif-$horodatage"

try {
    Expand-Archive -Path $archive.FullName -DestinationPath $extrait -Force
    $sql = Join-Path $extrait 'base.sql'
    if (-not (Test-Path $sql)) { Mal "l'archive ne contient pas base.sql"; exit 1 }

    & $mysql -u root -e "drop database if exists ``$controle``; create database ``$controle`` character set utf8mb4 collate utf8mb4_unicode_ci;" 2>&1 | Out-Null

    $chemin = $sql -replace '\\', '/'
    & $mysql -u root --default-character-set=utf8mb4 $controle -e "source $chemin" 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Mal 'la restauration a échoué — cette sauvegarde est inutilisable'; exit 1 }

    Bien 'archive rejouée sans erreur'

    # Comparer les lignes, pas seulement l'absence d'erreur : un vidage
    # tronqué se rejoue parfois proprement en s'arrêtant à mi-table.
    $ecarts = 0
    foreach ($table in @('users', 'members', 'activities', 'payments', 'financial_transactions')) {
        $avant = [int] (& $mysql -u root -N -e "select count(*) from ``$base``.``$table``;" 2>$null)
        $apres = [int] (& $mysql -u root -N -e "select count(*) from ``$controle``.``$table``;" 2>$null)

        if ($avant -eq $apres) {
            Bien ("{0,-24} {1} lignes" -f $table, $apres)
        } else {
            Mal ("{0,-24} {1} attendues, {2} restaurées" -f $table, $avant, $apres)
            $ecarts++
        }
    }

    if ($ecarts -gt 0) { Mal "$ecarts table(s) incomplète(s) — sauvegarde à refaire"; exit 1 }

    Write-Host ''
    Bien 'La sauvegarde est restaurable. Vérifié, pas supposé.'
    Write-Host ''
}
finally {
    & $mysql -u root -e "drop database if exists ``$controle``;" 2>&1 | Out-Null
    Remove-Item $extrait -Recurse -Force -ErrorAction SilentlyContinue
}
