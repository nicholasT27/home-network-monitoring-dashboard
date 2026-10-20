@echo off
rem Double-click to start the Home Network Monitor (needs PHP: https://www.php.net/downloads or XAMPP).
cd /d "%~dp0"
set PHPEXE=php
where php >nul 2>nul || set PHPEXE=C:\xampp\php\php.exe
"%PHPEXE%" start.php
pause
