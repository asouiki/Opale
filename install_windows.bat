@echo off
:: =====================================================================
::  VSM Jira PHP - Installateur Windows (standalone)
:: ---------------------------------------------------------------------
::  Ce fichier est un simple lanceur : toute la logique se trouve dans
::  install\install_windows.ps1, ou PowerShell offre une vraie gestion
::  d'erreur et journalise l'affichage dans install\install.log.
::
::  Le "pause" final garantit que la fenetre reste ouverte, meme en cas
::  d'erreur, pour que l'utilisateur puisse lire le message.
::
::  Quand l'installation reussit, ce lanceur enchaine sur start_windows.bat
::  dans une fenetre separee : l'utilisateur n'a plus a le lancer lui-meme.
:: =====================================================================
cd /d "%~dp0"

set "PS_SCRIPT=%~dp0install\install_windows.ps1"

if not exist "%PS_SCRIPT%" (
    echo ERREUR : fichier introuvable :
    echo   %PS_SCRIPT%
    echo Le dossier "install" doit se trouver a cote de ce fichier .bat.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SCRIPT%"
set "RC=%ERRORLEVEL%"

echo.
if not "%RC%"=="0" (
    echo Installation en echec. Consultez install\install.log
    pause
    exit /b %RC%
)

:: Installation reussie : on enchaine sur le lancement, dans SA PROPRE fenetre.
:: "start" et non "call" : le serveur PHP tourne au premier plan et ne rend jamais la main,
:: il monopoliserait donc cette fenetre-ci et masquerait le compte-rendu d'installation.
:: Le lancement est inconditionnel, y compris sur un .env fraichement cree depuis le template :
:: l'application affiche alors sa page d'erreur dediee, qui renvoie explicitement vers le .env.
echo Lancement de l application...
start "" "%~dp0start_windows.bat"

pause
exit /b %RC%
