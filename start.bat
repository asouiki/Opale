@echo off
echo ===================================
echo   Lancement appli OPALE
echo ===================================

cd /d "%~dp0"

set "LOG=%~dp0demarrage.log"

(
echo.
echo ==================================================
echo START.bat
echo Date : %date% %time%
echo ==================================================
echo [%date% %time%] [START-1] Demarrage start.bat
echo [%date% %time%] [START-2] Dossier : %CD%
) >> "%LOG%" 2>&1

:: ==================================================
:: Demarrage appli POC API GCS
:: /!\ A faire avant le lancement de PHP -S
:: ==================================================
(
echo [%date% %time%] [START-3] Lancement API Java
) >> "%LOG%" 2>&1

start "API metriques" /min "%~dp0..\poc-api-gcs\start_api.bat"

(
echo [%date% %time%] [START-4] API Java lancee
echo [%date% %time%] [START-5] Lancement PHP sur 0.0.0.0:8080
) >> "%LOG%" 2>&1

set APP_PUBLIC=%~dp0public

::set PHP_BIN=%~dp0php\php.exe
:: ==================================================
:: Detection du php.exe a utiliser :
::   1. php embarque dans l'appli (php\php.exe)   => version portable (prod / users)
::   2. sinon php a la racine du lecteur C:\      => poste de dev
::   3. sinon aucun php trouve                    => renvoyer vers la procedure d'install
:: ==================================================
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
    echo.
    pause
    exit /b 1
)

:: ==================================================
:: Garde-fou dependances : sans elles, le front controller echoue des sa 1ere ligne sur le require() de vendor\autoload.php, 
:: ce qui donne une fenetre de serveur ouverte et une page blanche cote navigateur - symptome illisible pour l'utilisateur.
:: On teste autoload.php et non le dossier vendor\ : une installation interrompue peut laisser un vendor\ present mais incomplet, qui passerait un simple test de dossier.
:: ==================================================
if not exist "%~dp0vendor\autoload.php" (
    echo.
    echo [ERREUR] Dependances absentes : vendor\autoload.php est introuvable.
    echo.
    echo Lancez d'abord l'installation : install_windows.bat
    echo.
    pause
    exit /b 1
)																															 

:: ==================================================
:: Demarrage appli OPALE
:: ==================================================
:: "%PHP_BIN%" -S 0.0.0.0:8080 -t "%APP_PUBLIC%"
"%PHP_BIN%" -S 0.0.0.0:8080 -t "%APP_PUBLIC%" >> "%LOG%" 2>&1

pause
