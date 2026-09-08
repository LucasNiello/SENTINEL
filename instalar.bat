@echo off
echo === Sentinel - instalacao do projeto ===

echo.
echo [1/5] composer install
call composer install
if errorlevel 1 goto :erro

echo.
echo [2/5] copiando .env.example para .env
copy /Y .env.example .env
if errorlevel 1 goto :erro

echo.
echo [3/5] php artisan key:generate
call php artisan key:generate
if errorlevel 1 goto :erro

echo.
echo [4/5] npm install
call npm install
if errorlevel 1 goto :erro

echo.
echo [5/5] php artisan migrate
call php artisan migrate
if errorlevel 1 goto :erro

echo.
echo === Instalacao concluida ===
goto :fim

:erro
echo.
echo === Falha durante a instalacao. Verifique a mensagem acima. ===
exit /b 1

:fim
