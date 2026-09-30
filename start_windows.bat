@echo off
echo ===================================
echo   Lancement appli OPALE
echo ===================================

cd /d "%~dp0"

set APP_PUBLIC=%~dp0public
set LOG=%~dp0demarrage.log

:: Detection du php.exe a utiliser :
::   1. php embarque dans l'appli (php\php.exe)   => version portable (prod / users)
::   2. sinon php a la racine du lecteur C:\      => poste de dev
::   3. sinon aucun php trouve                    => renvoyer vers la procedure d'install
if exist "%~dp0php\php.exe" (
    set "PHP_BIN=%~dp0php\php.exe"
) else if exist "C:\php\php.exe" (
    set "PHP_BIN=C:\php\php.exe"
) else (
    echo.
    echo [ERREUR] Aucun php.exe trouve :
    echo   - ni dans l'appli : "%~dp0php\php.exe"
    echo   - ni a la racine  : "C:\php\php.exe"
    echo.
    echo Merci de suivre la procedure d'installation : README_INSTALL_APP.md
    echo + Lancez d'abord l'installation : install_windows.bat
    echo.
    pause
    exit /b 1
)

:: ==================================================													 
:: Garde-fou dependances
:: ==================================================
if not exist "%~dp0vendor\autoload.php" (
    (
    echo [%date% %time%] [PHP-ERROR] vendor\autoload.php introuvable
    ) >> "%LOG%" 2>&1
    echo.
    echo [ERREUR] Dependances absentes : vendor\autoload.php est introuvable.
    echo.
    echo Lancez d'abord l'installation : install_windows.bat
    echo.
    pause
    exit /b 1
)
(
echo [%date% %time%] [PHP-5] vendor\autoload.php trouve
) >> "%LOG%" 2>&1

:: ==================================================
:: Garde-fou performance : sans OPcache, chaque requete (page ET appel AJAX) recompile tout le code, soit ~26 ms perdues par requete sur ce projet contre ~3 ms avec.
:: On teste opcache.enable et non enable_cli : c'est elle qui gouverne le SAPI "cli-server" du serveur integre (enable_cli ne vise que le SAPI "cli").
:: Cas nominal : OPcache est integre au core PHP 8.5 et actif par defaut, donc ce test passe. Il n'alerte que sur un php.ini qui l'a explicitement desactive.
:: Simple avertissement : l'appli reste fonctionnelle, elle est juste bien plus lente.
:: ==================================================
"%PHP_BIN%" -r "exit(extension_loaded('Zend OPcache') && ini_get('opcache.enable') ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
    echo.
    echo [ATTENTION] OPcache inactif : l'application sera nettement plus lente.
    echo             Relancez install_windows.bat pour corriger la configuration PHP.
    echo.
)

:: ==================================================
:: Demarrage appli POC API GCS
:: /!\ A faire avant le lancement de PHP -S
:: ==================================================
(
echo [%date% %time%] [JAVA-1] Lancement start_api.bat
) >> "%LOG%" 2>&1

if exist "%~dp0..\poc-api-gcs\start_api.bat" start "API metriques" /min "%~dp0..\poc-api-gcs\start_api.bat"

(
echo [%date% %time%] [JAVA-2] Etape POC terminee
) >> "%LOG%" 2>&1

:: ==================================================
:: Demarrage appli PHP OPALE
:: ==================================================				
:: N'ouvre un nouvel onglet navigateur qu'en lancement manuel.
:: Lors d'un reboot (argument "noweb"), la page /reboot se recharge seule dans son onglet.
if /i not "%~1"=="noweb" start http://localhost:8080

(
echo [%date% %time%] [PHP-6] Lancement PHP sur 0.0.0.0:8080
) >> "%LOG%" 2>&1

:: Démarre PHP sur le port 8080.
:: Sortie laissée à la CONSOLE, non redirigée vers %LOG% : le serveur integre y journalise chaque
:: requete ("Accepted", "[200]: GET /css/...", "Closing"), ce qui noierait les marqueurs d'etapes sous
:: des milliers de lignes d'acces. %LOG% ne trace que le (re)demarrage ; le detail des requetes se
:: regarde en direct. Idem dans start_serveur.bat, qui ne redirige pas non plus.
"%PHP_BIN%" -S 0.0.0.0:8080 -t "%APP_PUBLIC%"

set "PHP_RC=%ERRORLEVEL%"

(
echo [%date% %time%] [PHP-7] PHP s'est ARRETE
echo [%date% %time%] [PHP-8] ERRORLEVEL=%PHP_RC%
) >> "%LOG%" 2>&1

pause