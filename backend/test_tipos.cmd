@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test_tipos.ps1"
exit /b %ERRORLEVEL%
