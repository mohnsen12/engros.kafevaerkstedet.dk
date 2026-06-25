@echo off
title Engros Bestillingsportal Server
echo ==========================================================
echo           ENGROS BESTILLINGSPORTAL — SERVER
echo ==========================================================
echo.
echo Starter PHP webserver...
echo Root-mappe: app/
echo URL:        http://localhost:8080
echo Config:     app/php.ini
echo.
echo Logfiler og databasen gemmes i app/ mappen.
echo.
echo Tryk Ctrl+C for at stoppe serveren.
echo.
"C:\Program Files (x86)\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe" -c app/php.ini -S localhost:8080 -t app
if %errorlevel% neq 0 (
    echo.
    echo [FEJL] Serveren kunne ikke starte. Tjek stien til PHP.
    pause
)
