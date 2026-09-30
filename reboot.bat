@echo off
title OPALE reboot

:: ==========================================================================
::  Reboot du serveur PHP integre (php -S) - declenche par la route OPALE /reboot.
::
::  Lance par RebootController via PowerShell Start-Process : 
::  ce process n'herite PAS des handles de php.exe (notamment le socket d'ecoute du serveur). 
::  Sans ca, le port 8080 resterait LISTENING apres le kill et le nouveau serveur ne pourrait pas le reprendre.
:: ==========================================================================
cd /d "%~dp0"

set "LOG=%~dp0demarrage.log"
set PORT=8080

(
echo.
echo ==================================================
echo REBOOT.bat
echo Date : %date% %time%
echo ==================================================
echo [%date% %time%] [REBOOT-1] Debut du reboot
echo [%date% %time%] [REBOOT-2] Dossier : %CD%
echo.
) >> "%LOG%" 2>&1

:: ==================================================
:: 1. Laisse la reponse HTTP de /reboot se flusher avant de tuer le serveur.
:: ==================================================
(
echo [%date% %time%] [REBOOT-3] Attente avant arret PHP
) >> "%LOG%" 2>&1
ping -n 2 127.0.0.1 >nul

:: ==================================================
:: 2. Tue UNIQUEMENT le serveur OPALE sur le port 8080.
:: ==================================================
(
echo [%date% %time%] [REBOOT-4] Recherche du processus PHP sur le port %PORT%
netstat -ano | findstr ":%PORT%" | findstr LISTENING
) >> "%LOG%" 2>&1

for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%PORT%" ^| findstr LISTENING') do (
    (
    echo [%date% %time%] [REBOOT-5] Arret du PID %%p
    taskkill /F /PID %%p
    ) >> "%LOG%" 2>&1
)

:: ==================================================
:: 3. Demarrage de l'API Java
:: /!\ A faire avant le lancement de PHP -S
:: EDIT: reboot_java_api_gcs.bat COMMENTE CAR start_api.bat EST DEJA LANCE VIA start_windows.bat
:: 		A GARDER CAR PERMET UN DIAGNOSTIC
:: ==================================================
:: (
:: echo [%date% %time%] [REBOOT-6] Lancement reboot_java_api_gcs.bat
:: ) >> "%LOG%" 2>&1
:: start "" "%~dp0reboot_java_api_gcs.bat"

:: ==================================================
:: 4. Laisse l'OS liberer le port 8080 avant le rebind.
:: ==================================================					
ping -n 3 127.0.0.1 >nul

:: ==================================================
:: 5. Relance serveur PHP (nouvelle fenetre), sans rouvrir le navigateur : la page /reboot se recharge d'elle-meme
:: ==================================================
(
echo [%date% %time%] [REBOOT-7] Lancement start_windows.bat noweb
) >> "%LOG%" 2>&1

start "" "%~dp0start_windows.bat" noweb

(
echo [%date% %time%] [REBOOT-8] Fin du script reboot.bat, main passee a start_windows.bat
echo.
) >> "%LOG%" 2>&1