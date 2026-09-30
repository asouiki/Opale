@echo off
title OPALE reboot diagnostic

cd /d "%~dp0"

set "DIAG=%~dp0diagnostic_api.txt"
set "API_DIR=%~dp0..\poc-api-gcs"
set "API_BAT=%API_DIR%\start_api.bat"
set "API_LOG=%API_DIR%\api-metriques.log"
set "JAR=%API_DIR%\poc-api-gcs-1.0.0-SNAPSHOT.jar"
set "JAVA=%API_DIR%\java\bin\java.exe"

(
echo ==========================================
echo DIAGNOSTIC AVANT DEMARRAGE API
echo Date / heure : %date% %time%
echo Dossier reboot : %~dp0
echo Dossier API : %API_DIR%
echo ==========================================
echo.

echo ===== EXISTENCE START_API =====
if exist "%API_BAT%" (
    echo OK : %API_BAT%
) else (
    echo ERREUR : START_API INTROUVABLE
)

echo.
echo ===== EXISTENCE JAR =====
if exist "%JAR%" (
    echo OK : %JAR%
) else (
    echo ERREUR : JAR INTROUVABLE
)

echo.
echo ===== EXISTENCE JAVA =====
if exist "%JAVA%" (
    echo OK : %JAVA%
) else (
    echo ERREUR : JAVA INTROUVABLE
)

echo.
echo ===== NETSTAT 8081 AVANT =====
netstat -ano | findstr ":8081"

echo.
echo ===== TASKLIST JAVA AVANT =====
tasklist | findstr /I "java"

echo.
echo ==========================================
echo LANCEMENT START_API
echo ==========================================
) > "%DIAG%" 2>&1

rem Lance le script de demarrage de l'API Java
start "API metriques diagnostic" /min "%API_BAT%"

rem Attend 10 secondes que Java demarre
ping -n 11 127.0.0.1 >nul

(
echo.
echo ==========================================
echo DIAGNOSTIC APRES DEMARRAGE API
echo Date / heure : %date% %time%
echo ==========================================
echo.

echo ===== NETSTAT 8081 APRES =====
netstat -ano | findstr ":8081"

echo.
echo ===== TASKLIST JAVA APRES =====
tasklist | findstr /I "java"

echo.
echo ===== CURL LOCAL =====
curl.exe -v --connect-timeout 5 http://127.0.0.1:8081/metrics

echo.
echo.
echo ===== LOG API =====
if exist "%API_LOG%" (
    type "%API_LOG%"
) else (
    echo ERREUR : api-metriques.log INTROUVABLE
)

echo.
echo ==========================================
echo FIN DIAGNOSTIC
echo ==========================================
) >> "%DIAG%" 2>&1

exit /b