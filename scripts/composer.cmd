@echo off
"%~dp0..\.tools\php\php.exe" "%~dp0..\.tools\composer.phar" %*
exit /b %errorlevel%
