@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test_generos.ps1"
exit /b %ERRORLEVEL%
