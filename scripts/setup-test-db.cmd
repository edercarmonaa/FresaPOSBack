@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-test-db.ps1" %*
exit /b %errorlevel%
