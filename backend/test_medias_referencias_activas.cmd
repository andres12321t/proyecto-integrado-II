@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test_medias_referencias_activas.ps1"
exit /b %ERRORLEVEL%
