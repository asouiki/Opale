@echo off
echo ===================================
echo   Lancement appli OPALE
echo ===================================

cd /d %~dp0

set APP_PUBLIC=%~dp0public

set PHP_BIN=%~dp0php\php.exe

"%PHP_BIN%" -S 0.0.0.0:8080 -t "%APP_PUBLIC%"

pause
