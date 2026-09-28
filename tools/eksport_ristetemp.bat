@echo off
chcp 65001 >nul
title Eksport af ristetemperaturer til bestillingsportalen
echo ==========================================================
echo   EKSPORT AF RISTETEMPERATURER
echo   produktion.local (opskrifter) -^> bestillingsportalen
echo ==========================================================
echo.

REM 1) Eksportér aktive opskrifters sluttemperaturer til app/ristetemperaturer.json
REM    (FVST's php.ini har pdo_sqlite-driveren, som portalens ikke har)
"C:\Users\Claus\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" -c "C:\Users\Claus\Documents\antigravity\FVST_AI_PROJEKT\app\php.ini" "%~dp0eksport_ristetemperaturer.php"
if %errorlevel% neq 0 (
    echo.
    echo [FEJL] Eksport mislykkedes. Intet deployet.
    pause
    exit /b 1
)

echo.
REM 2) Deploy til portalen: commit + push (GitHub Actions deployer automatisk)
cd /d "%~dp0.."
git add app/ristetemperaturer.json
git diff --cached --quiet
if %errorlevel% equ 0 (
    echo Temperaturerne er uendret siden sidst - intet at deploye.
    pause
    exit /b 0
)
git commit -m "Ristetemperaturer opdateret fra produktion.local"
if %errorlevel% neq 0 (
    echo.
    echo [FEJL] Git commit mislykkedes.
    pause
    exit /b 1
)
git push
if %errorlevel% neq 0 (
    echo.
    echo [FEJL] Git push mislykkedes - korriger manuelt og koer: git push
    pause
    exit /b 1
)

echo.
echo [OK] Deploy koert - temperaturerne er online om ca. 30 sekunder.
pause
