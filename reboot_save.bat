@echo off
title OPALE reboot
:: ==========================================================================
::  Reboot du serveur PHP integre (php -S) - declenche par la route /reboot.
::
::  Lance par RebootController via PowerShell Start-Process : ce process
::  n'herite PAS des handles de php.exe (notamment le socket d'ecoute du
::  serveur). Sans ca, le port 8080 resterait LISTENING apres le kill et le
::  nouveau serveur ne pourrait pas le reprendre.
:: ==========================================================================

cd /d "%~dp0"

set PORT=8080

:: 1. Laisse la reponse HTTP de /reboot se flusher avant de tuer le serveur.
ping -n 2 127.0.0.1 >nul

:: 2. Tue UNIQUEMENT le serveur OPALE : le process en ecoute sur le port 8080.
::    Les autres php.exe (CLI, composer, autre appli) ne sont pas touches.
::    findstr LISTENING exclut les connexions clientes (ESTABLISHED/TIME_WAIT).
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%PORT%" ^| findstr LISTENING') do (
    taskkill /F /PID %%p >nul 2>&1
)


:: Nettoyage tests
:: kill appli POC API GCS
::set PORT_POC2=8081
::for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%PORT_POC2%" ^| findstr LISTENING') do (
::    taskkill /F /PID %%p >nul 2>&1
::)
:: kill appli POC API GCS
::set PORT_POC=8082
::for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%PORT_POC%" ^| findstr LISTENING') do (
::    taskkill /F /PID %%p >nul 2>&1
::)


:: 3. Laisse l'OS liberer le port avant le rebind.
ping -n 3 127.0.0.1 >nul

:: 4. Relance le serveur (nouvelle fenetre), sans rouvrir le navigateur : la page /reboot se recharge d'elle-meme dans son onglet. 
::    start_windows.bat detecte lui-meme le php.exe a utiliser (appli portable, sinon C:\php).
start "" "%~dp0start_windows.bat" noweb
