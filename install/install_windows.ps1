# =====================================================================
#  VSM Jira PHP - Installateur Windows (standalone)
# ---------------------------------------------------------------------
#  Lance par ..\install_windows.bat (double-clic). Ne pas executer
#  directement : le .bat garantit que la fenetre reste ouverte.
#
#  Tout l'affichage est capture dans install\install.log via
#  Start-Transcript.
#
#  Note : pas d'accents dans ce fichier. PowerShell 5.1 lit les .ps1
#  sans BOM en encodage ANSI, ce qui transformerait les accents en
#  caracteres illisibles dans la console de l'utilisateur.
# =====================================================================

# ---- Parametres ------------------------------------------------------
#  La version exacte de PHP n'est PAS codee en dur : elle est resolue a
#  la volee via releases.json (index officiel de windows.php.net).
#  $PhpFallbackZip ne sert que si cet index est injoignable (proxy...).
$PhpBranch      = '8.5'
$PhpFlavor      = 'nts-vs17-x64'
$PhpFallbackZip = 'php-8.5.8-nts-Win32-vs17-x64.zip'
# Extensions REELLEMENT utilisees par l'application : curl (API Jira), mbstring, zip (import de
# capacite .xlsx) et openssl (TLS). L'installation echoue si l'une d'elles ne se charge pas.
$PhpExtensions = @('curl', 'openssl', 'mbstring', 'zip')

# Extensions autrefois activees par cet installateur, mais qu'aucun code ni aucune dependance
# n'utilise. On les RE-COMMENTE : un php.ini deja genere continuerait sinon a les charger, ce qui
# provoque un avertissement au demarrage de PHP - et cet avertissement pollue les reponses HTTP tant
# que display_errors est actif (php.ini-development), au risque de casser le JSON des endpoints.
# Cas reel : fileinfo, dont la DLL est bloquee par les politiques de controle d'application (WDAC /
# AppLocker) de certains postes d'entreprise, faisait echouer toute l'installation pour rien.
$PhpExtensionsToDisable = @('fileinfo')
$ReleasesBase   = 'https://windows.php.net/downloads/releases/'
$ComposerUrl    = 'https://getcomposer.org/download/latest-stable/composer.phar'

# ---- Reglages de performance (ajoutes en fin de php.ini, cf. etape 2) ------
#  Enjeu : sans cache d'opcode, le serveur integre recompile le code source a
#  CHAQUE requete HTTP, appels AJAX compris. Mesure sur ce projet : 26 ms par
#  requete sans OPcache contre 3 ms avec (x9), avant tout traitement metier.
#
#  En PHP 8.5 (build Windows), OPcache est compile STATIQUEMENT dans php.exe :
#  aucun zend_extension a declarer - la ligne echouerait d'ailleurs, faute de
#  php_opcache.dll dans ext\. Et opcache.enable vaut deja 1 par defaut, donc
#  l'essentiel du bloc ci-dessous ne fait que REAFFIRMER ces defauts.
#  On les ecrit malgre tout, comme filet : un php.ini derive de
#  php.ini-production, un defaut modifie par une version future ou un fichier
#  retouche a la main ne doivent pas degrader silencieusement l'application.
#  Seuls enable_cli, memory_limit et max_execution_time s'ecartent reellement
#  des defauts.
$PerfMarker = '; ---- OPALE : reglages de performance (bloc gere par l installateur) ----'
$PerfBlock  = @"
$PerfMarker
; OPcache est integre au core en PHP 8.5 : ne PAS ajouter de zend_extension=opcache,
; le chargement echouerait (aucun php_opcache.dll livre dans ext\).
; opcache.enable pilote le serveur integre, lequel tourne sous le SAPI "cli-server" :
; opcache.enable_cli ne le gouverne PAS (elle ne vise que le SAPI "cli").
opcache.enable=1
; enable_cli : sans effet sur le serveur, mais accelere les commandes PHP en ligne (composer, phpunit...).
opcache.enable_cli=1
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.memory_consumption=128
; Classes de l application + vendor (Slim, PHP-DI...).
opcache.max_accelerated_files=10000
; php.ini-development impose 128M, insuffisant pour les caches JSON volumineux
memory_limit=256M
; Avec 30 s par défaut, PHP tue la requete en cours de route.
; le cache n'etant ecrit qu'en fin de calcul, tout le travail accompli etait perdu et le rechargement repartait de zero.
; Le serveur integre etant mono-processus, une requete longue met les autres en file d'attente
max_execution_time=120
"@

# Ce script vit dans install\ : la racine du projet est le dossier parent.
# Les artefacts d'installation (journal, composer.phar) restent dans
# install\, la ou l'utilisateur ira les chercher. Tout le reste (php\,
# vendor\, .env) se cree bien a la racine du projet.
$InstallDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root       = Split-Path -Parent $InstallDir
$PhpDir     = Join-Path $Root 'php'
$PhpExe     = Join-Path $PhpDir 'php.exe'
$PharBin    = Join-Path $InstallDir 'composer.phar'
$LogFile    = Join-Path $InstallDir 'install.log'

# Composer doit s'executer depuis la racine : c'est la qu'est composer.json.
Set-Location $Root
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# Relaie la sortie d'un executable natif vers l'hote (donc vers le transcript). 
# Sous PowerShell 5.1, chaque ligne de stderr arrive emballee dans un ErrorRecord : 
#   on la deballe pour afficher le texte et non le nom de la classe d'exception.
filter Write-NativeOutput {
    if ($_ -is [System.Management.Automation.ErrorRecord]) { Write-Host $_.Exception.Message }
    else { Write-Host $_ }
}

function Write-Step($n, $label) { Write-Host ''; Write-Host "[$n/5] $label" -ForegroundColor Cyan }
function Write-Ok($m)           { Write-Host "   - $m" -ForegroundColor Green }
function Write-Info($m)         { Write-Host "   - $m" }
function Write-Warn($m)         { Write-Host "   - $m" -ForegroundColor Yellow }

# Detail technique : visible dans install.log, discret a l'ecran.
function Write-Detail($m)       { Write-Host "      [detail] $m" -ForegroundColor DarkGray }

Start-Transcript -Path $LogFile -Force | Out-Null
$exitCode = 0

try {
    Write-Host '============================================================'
    Write-Host '  Installation application OPALE (Windows)'
    Write-Host '============================================================'
    Write-Host '  Un compte-rendu detaille est ecrit dans install\install.log'

    # =================================================================
    # 1/5 - PHP portable
    # =================================================================
    Write-Step 1 'Verification de PHP'

    if (Test-Path $PhpExe) {
        Write-Ok 'PHP deja present dans .\php\'
    }
    else {
        Write-Info "PHP absent. Recherche de la derniere version $PhpBranch ..."

        # Resolution de la version courante via l'index officiel.
        $zipName = $null
        $sha256  = $null
        try {
            $index  = (Invoke-WebRequest -UseBasicParsing -Uri ($ReleasesBase + 'releases.json')).Content | ConvertFrom-Json
            $flavor = $index.$PhpBranch.$PhpFlavor
            $zipName = $flavor.zip.path
            $sha256  = $flavor.zip.sha256
            Write-Info "Version detectee : PHP $($index.$PhpBranch.version)"
        }
        catch {
            Write-Warn 'Index des versions injoignable, repli sur la version epinglee.'
            Write-Detail $_.Exception.Message
            $zipName = $PhpFallbackZip
        }

        # Une version qui n'est plus la derniere de sa branche bascule de releases/ vers releases/archives/ : on tente les deux.
        $target    = Join-Path $env:TEMP $zipName
        $candidates = @(($ReleasesBase + $zipName), ($ReleasesBase + 'archives/' + $zipName))
        $downloaded = $false

        foreach ($url in $candidates) {
            try {
                Write-Info "Telechargement : $url"
                Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $target
                $downloaded = $true
                break
            }
            catch {
                Write-Info 'indisponible ici, essai suivant...'
                Write-Detail $_.Exception.Message
            }
        }

        if (-not $downloaded) {
            throw "Impossible de telecharger $zipName depuis windows.php.net."
        }

        if ($sha256) {
            $actual = (Get-FileHash $target -Algorithm SHA256).Hash
            if ($actual -ne $sha256.ToUpper()) {
                Remove-Item $target -Force
                throw "Empreinte SHA-256 invalide (attendu $($sha256.ToUpper()), obtenu $actual). Fichier corrompu."
            }
            Write-Ok 'Empreinte SHA-256 verifiee'
        }

        Expand-Archive -Path $target -DestinationPath $PhpDir -Force
        Remove-Item $target -Force

        if (-not (Test-Path $PhpExe)) {
            throw "L'archive a ete extraite mais php.exe est introuvable dans $PhpDir."
        }
        Write-Ok 'PHP telecharge et extrait dans .\php\'
    }

    # =================================================================
    # 2/5 - Configuration de php.ini
    # =================================================================
    Write-Step 2 'Configuration de php.ini'

    $ini    = Join-Path $PhpDir 'php.ini'
    $iniDev = Join-Path $PhpDir 'php.ini-development'
    if ((-not (Test-Path $ini)) -and (Test-Path $iniDev)) {
        Copy-Item $iniDev $ini -Force
    }

    if (Test-Path $ini) {
        # -Encoding Ascii : evite un BOM en tete de php.ini, que le parseur d'ini de PHP interpreterait comme du contenu.
        $content = Get-Content $ini -Raw
        $content = $content -replace '(?m)^;?\s*extension_dir\s*=.*', 'extension_dir = "ext"'
        foreach ($ext in $PhpExtensions) {
            $content = $content -replace ('(?m)^;\s*extension\s*=\s*' + $ext + '\s*$'), "extension=$ext"
        }

        # Symetrique du bloc ci-dessus : rend inoffensif un php.ini genere par une version anterieure
        # de cet installateur, qui avait active des extensions devenues inutiles.
        foreach ($ext in $PhpExtensionsToDisable) {
            $content = $content -replace ('(?m)^extension\s*=\s*' + $ext + '\s*$'), ";extension=$ext"
        }

        # Bloc de performance ajoute EN FIN de fichier : PHP retient la DERNIERE definition d'une directive, 
        # ce bloc l'emporte donc sur les valeurs livrees par php.ini-development. 
        # Plus robuste qu'un remplacement des lignes commentees, dont la mise en forme varie d'une version de PHP a l'autre.
        # Relance de l'installateur : l'ancien bloc est retire avant reecriture, pour rester idempotent (le php.ini existant, lui, est conserve).
        $markerIndex = $content.IndexOf($PerfMarker)
        if ($markerIndex -ge 0) { $content = $content.Substring(0, $markerIndex) }
        $content = $content.TrimEnd() + "`r`n`r`n" + $PerfBlock + "`r`n"

        Set-Content -Path $ini -Value $content -Encoding Ascii
        Write-Ok ('Extensions activees : ' + ($PhpExtensions -join ', '))
        Write-Ok 'Reglages de performance ecrits (OPcache, memory_limit)'
    }
    else {
        Write-Warn 'php.ini introuvable, etape ignoree.'
    }

    # Une extension declaree n'est pas forcement chargee : on verifie.
    $missing = @()
    foreach ($ext in $PhpExtensions) {
        & $PhpExe -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>$null
        if ($LASTEXITCODE -ne 0) { $missing += $ext }
    }
    if ($missing.Count -gt 0) {
        throw ('Extensions PHP non chargees : ' + ($missing -join ', ') + '. Verifiez ' + $ini)
    }
    Write-Ok 'Extensions verifiees comme chargees'

    # extension_loaded() attend le nom d'affichage d'OPcache, pas "opcache".
    # On controle opcache.enable (et non enable_cli) : c'est elle qui gouverne le SAPI "cli-server" du serveur integre.
    # Ce controle doit passer dans la configuration nominale (OPcache est integre au core et actif par defaut) :
    #   il n'echoue que sur un php.ini qui l'a explicitement desactive.
    # Simple avertissement et non une erreur : sans OPcache l'application fonctionne, elle est seulement nettement plus lente.
    & $PhpExe -r "exit(extension_loaded('Zend OPcache') && ini_get('opcache.enable') ? 0 : 1);" 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Ok 'OPcache actif : le code n est compile qu une fois, pas a chaque requete'
    }
    else {
        Write-Warn 'OPcache inactif : l application fonctionnera, mais sera nettement plus lente.'
        Write-Detail "Verifiez le bloc de performance en fin de $ini"
    }

    # =================================================================
    # 3/5 - Composer + dependances
    # =================================================================
    Write-Step 3 'Installation des dependances (Composer)'

    $composerInPath = [bool](Get-Command composer -ErrorAction SilentlyContinue)

    if ($composerInPath) {
        Write-Info 'Composer detecte dans le PATH.'
    }
    else {
        Write-Info 'Composer absent du PATH : utilisation de composer.phar local.'
        if (-not (Test-Path $PharBin)) {
            Write-Info 'Telechargement de composer.phar ...'
            try {
                Invoke-WebRequest -UseBasicParsing -Uri $ComposerUrl -OutFile $PharBin
            }
            catch {
                throw "Telechargement de composer.phar impossible : $($_.Exception.Message)"
            }
        }
    }

    # Composer ecrit sa progression sur stderr : sous PowerShell 5.1, avec ErrorActionPreference='Stop', chaque ligne deviendrait une exception.
    # On repasse en 'Continue' et on se fie au seul code de sortie.
    #
    # Le relais par Write-Host n'est pas cosmetique : la sortie d'un executable natif court-circuite Start-Transcript et n'atterrirait
    # pas dans install.log. La faire transiter par l'hote la capture.
    #
    # --no-dev : PHPUnit, PHPStan et PHP-CS-Fixer sont des outils de developpement (require-dev),
    # inutiles sur un poste utilisateur. Le poste n'installe ainsi que 20 paquets au lieu de 80.
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($composerInPath) {
            & composer install --no-interaction --no-dev --optimize-autoloader 2>&1 | Write-NativeOutput
        }
        else {
            & $PhpExe $PharBin install --no-interaction --no-dev --optimize-autoloader 2>&1 | Write-NativeOutput
        }
        $composerExit = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }

    if ($composerExit -ne 0) {
        throw "Composer a echoue (code $composerExit). Voir le detail ci-dessus."
    }
    Write-Ok 'Dependances installees dans .\vendor\'

    # =================================================================
    # 4/5 - Fichier .env
    # =================================================================
    Write-Step 4 'Configuration (.env)'

    $envFile     = Join-Path $Root '.env'
    $envTemplate = Join-Path $Root '.env.template'

    if (Test-Path $envFile) {
        Write-Ok '.env deja present, conserve tel quel'
    }
    elseif (Test-Path $envTemplate) {
        Copy-Item $envTemplate $envFile
        Write-Ok '.env cree a partir de .env.template'
        Write-Host ''
        Write-Host '   #####################################################' -ForegroundColor Yellow
        Write-Host '   #  A FAIRE : ouvrez .env et renseignez vos acces    #' -ForegroundColor Yellow
        Write-Host '   #  Jira : JIRA_BASE_URL, JIRA_EMAIL, JIRA_API_TOKEN #' -ForegroundColor Yellow
        Write-Host '   #  Sans cela l application ne peut pas interroger   #' -ForegroundColor Yellow
        Write-Host '   #  Jira.                                            #' -ForegroundColor Yellow
        Write-Host '   #####################################################' -ForegroundColor Yellow
        Write-Host ''
        $answer = Read-Host '    Ouvrir .env dans le Bloc-notes maintenant ? [O/N]'
        if ($answer -match '^[OoYy]') {
            Start-Process notepad.exe -ArgumentList $envFile
        }
    }
    else {
        Write-Warn '.env.template introuvable, .env non cree.'
    }

    # =================================================================
    # 5/5 - Dossiers runtime
    # =================================================================
    Write-Step 5 'Preparation des dossiers runtime'

    $cacheDir = Join-Path $Root 'cache'
    if (-not (Test-Path $cacheDir)) { New-Item -ItemType Directory -Path $cacheDir | Out-Null }
    Write-Ok 'Dossier cache\ pret'

    # =================================================================
    Write-Host ''
    Write-Host '============================================================' -ForegroundColor Green
    Write-Host '  Installation terminee.' -ForegroundColor Green
    Write-Host '------------------------------------------------------------'
    Write-Host '  1. L application demarre automatiquement (http://localhost:8080/)'
    Write-Host '     Pour la relancer plus tard : start_windows.bat'
    Write-Host '  2. Verifiez vos acces Jira dans le fichier .env'
    Write-Host '     (sans eux, l application affiche une page d erreur qui vous y renvoie)'
    Write-Host '  3. Compte-rendu complet : install\install.log'
    Write-Host '  4. Optionnel : installer un LLM Local pour les analyses des metriques : lire README_INSTALL_LOCAL_LLM.md'
    Write-Host '============================================================' -ForegroundColor Green
}
catch {
    $exitCode = 1
    Write-Host ''
    Write-Host '------------------------------------------------------------' -ForegroundColor Red
    Write-Host '  INSTALLATION INTERROMPUE' -ForegroundColor Red
    Write-Host '------------------------------------------------------------' -ForegroundColor Red
    Write-Host "  Cause : $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ''
    Write-Host '  Causes frequentes :'
    Write-Host '    - pas de connexion Internet, ou proxy / VPN d entreprise'
    Write-Host '    - antivirus bloquant l ecriture dans le dossier php\'
    Write-Host ''
    Write-Host '  Transmettez le fichier install\install.log pour diagnostic :'
    Write-Host "    $LogFile"
    Write-Host '------------------------------------------------------------' -ForegroundColor Red

    # Trace complete : reservee au journal, inutile a l ecran.
    Write-Detail $_.ScriptStackTrace
}
finally {
    Stop-Transcript | Out-Null
}

exit $exitCode
