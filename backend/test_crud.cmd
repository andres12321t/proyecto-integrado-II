@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test_crud.ps1"
exit /b %ERRORLEVEL%
