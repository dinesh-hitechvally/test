@echo off
cd /d "D:\laragon\www\sharemarket\backend"
"D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan schedule:run >> storage\logs\schedule-run.log 2>&1
