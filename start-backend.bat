@echo off
rem Starts ONLY the monitoring API on http://127.0.0.1:8081 (for use with a tunnel). MySQL must be running.
cd /d "%~dp0"
set PHPEXE=php
where php >nul 2>nul || set PHPEXE=C:\xampp\php\php.exe
echo Backend running on http://127.0.0.1:8081  - leave this window open.
"%PHPEXE%" -S 127.0.0.1:8081 -t backend backend\router.php
pause
