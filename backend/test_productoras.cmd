@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test_productoras.ps1"
exit /b %ERRORLEVEL%
