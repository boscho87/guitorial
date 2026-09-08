@echo off
REM WSL on this machine shuts down within seconds of the last disconnected
REM client, regardless of vmIdleTimeout in .wslconfig (looks IT-policy
REM enforced) - so an actually-attached session has to stay open, or
REM Docker (and Guitorial) goes down again immediately. This opens that
REM session in its own window (containers auto-restart there via systemd +
REM restart:unless-stopped) and waits for the app to answer before opening
REM the browser. Leave the new "Guitorial (WSL)" window open while you work.

start "Guitorial (WSL)" wsl -d Ubuntu -- bash /mnt/c/Users/simon.mueller/Documents/dev/guitorial/wsl_start.sh

set /a count=0
:waitloop
curl -s -o nul -w "%%{http_code}" http://localhost:8080/ > "%TEMP%\guitorial_status.txt"
set /p status=<"%TEMP%\guitorial_status.txt"
if "%status%"=="200" goto ready
set /a count+=1
if %count% GEQ 30 goto timeout
timeout /t 1 /nobreak >nul
goto waitloop

:ready
start http://localhost:8080/
exit /b 0

:timeout
echo Guitorial ist nach 30s nicht erreichbar. "docker compose up -d" im Projektordner pruefen.
pause
exit /b 1
