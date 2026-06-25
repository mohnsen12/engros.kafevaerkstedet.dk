@echo off
title Cloudflare Tunnel - Engros Portal
echo ==========================================================
echo           ENGROS BESTILLINGSPORTAL — TUNNEL
echo ==========================================================
echo.
echo Dette script opretter en sikker HTTPS-tunnel direkte til 
echo din lokale server (localhost:8080) via Cloudflare.
echo.
echo Forudsaetning: 'cloudflared' skal vaere installeret.
echo Hvis du ikke har det, kan det downloades her:
echo https://github.com/cloudflare/cloudflared/releases
echo.
echo Naar tunnelen starter, vil Cloudflare vise en URL (fx:
echo https://xxxx-xxxx-xxxx.trycloudflare.com) i loggen nedenfor.
echo Giv denne URL til dine test-forhandlere.
echo.
echo Tryk Ctrl+C for at lukke tunnelen.
echo.
cloudflared tunnel --url http://localhost:8080
if %errorlevel% neq 0 (
    echo.
    echo [INFO] cloudflared er muligvis ikke installeret i din PATH.
    echo Hvis du vil koere tunnelen, skal du downloade 'cloudflared.exe'
    echo og laegge den i samme mappe som dette script.
    echo.
    pause
)
