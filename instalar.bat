@echo off
setlocal enabledelayedexpansion
echo === Sentinel - instalacao do projeto (banco local via Laragon) ===

REM ============================================================
REM CONFIGURACAO - edite aqui antes de rodar
REM ============================================================
set DB_HOST_VAL=127.0.0.1
set DB_PORT_VAL=3306
set DB_DATABASE_VAL=sentinel
set DB_USERNAME_VAL=root
set DB_PASSWORD_VAL=1234

REM Preencha com os valores reais do portal do Microsoft Foundry.
REM A API key NAO entra aqui - continua manual no .env depois.
set AZURE_ENDPOINT_VAL=https://sentinel-foundry-tcc26.openai.azure.com
set AZURE_DEPLOYMENT_VAL=gpt-4.1-mini
REM ============================================================

echo.
echo [1/8] Verificando versao do PHP (minimo 8.4.1 - Laravel 13 traz Symfony 8, que exige isso)...
where php >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "php" nao encontrado no PATH.
    echo Instale o PHP 8.4.1 ou superior ^(o Laragon ja traz uma versao - confira em Menu ^> PHP^) e garanta que esteja no PATH.
    goto :erro
)
for /f "delims=" %%v in ('php -r "echo PHP_VERSION;"') do set PHP_VERSION=%%v
php -r "exit(version_compare(PHP_VERSION, '8.4.1', '<') ? 1 : 0);"
if errorlevel 1 (
    echo ERRO: PHP !PHP_VERSION! detectado. Este projeto precisa de PHP 8.4.1 ou superior.
    echo No Laragon, troque a versao em Menu ^> PHP e reinicie o terminal.
    goto :erro
)
echo OK: PHP !PHP_VERSION!

echo.
echo [2/8] composer install...
where composer >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "composer" nao encontrado no PATH. Instale o Composer antes de continuar.
    goto :erro
)
call composer install
if errorlevel 1 goto :erro
echo OK: dependencias PHP instaladas.

if exist package.json (
    echo.
    echo [3/8] npm install...
    where npm >nul 2>nul
    if errorlevel 1 (
        echo ERRO: package.json existe mas "npm" nao foi encontrado no PATH. Instale o Node.js antes de continuar.
        goto :erro
    )
    call npm install
    if errorlevel 1 goto :erro
    echo OK: dependencias JS instaladas.
) else (
    echo.
    echo [3/8] package.json nao encontrado - pulando npm install.
)

echo.
echo [4/8] Configurando .env...
if exist .env (
    echo .env ja existe - mantendo como esta ^(so os campos abaixo serao ajustados^).
) else (
    copy /Y .env.example .env >nul
    if errorlevel 1 goto :erro
    echo .env criado a partir de .env.example.
)

set APP_KEY_VALUE=
for /f "tokens=1,* delims==" %%a in ('findstr /b "APP_KEY=" .env') do set APP_KEY_VALUE=%%b
if "!APP_KEY_VALUE!"=="" (
    echo Gerando APP_KEY...
    call php artisan key:generate
    if errorlevel 1 goto :erro
    echo OK: APP_KEY gerada.
) else (
    echo APP_KEY ja definida - mantendo.
)

echo.
echo [5/8] Gravando configuracao de banco e Azure Foundry no .env...

set PS_SETENV=%TEMP%\sentinel_set_env.ps1
> "%PS_SETENV%" (
    echo param^([string]$EnvFile, [string]$Key, [string]$Value^)
    echo $content = Get-Content $EnvFile
    echo $pattern = '^^' + [regex]::Escape^($Key^) + '='
    echo if ^($content -match $pattern^) {
    echo     $content = $content -replace ^($pattern + '.*'^), ^($Key + '=' + $Value^)
    echo } else {
    echo     $content += ^($Key + '=' + $Value^)
    echo }
    echo Set-Content -Path $EnvFile -Value $content
)

call :setenv DB_CONNECTION mysql
call :setenv DB_HOST %DB_HOST_VAL%
call :setenv DB_PORT %DB_PORT_VAL%
call :setenv DB_DATABASE %DB_DATABASE_VAL%
call :setenv DB_USERNAME %DB_USERNAME_VAL%
call :setenv DB_PASSWORD %DB_PASSWORD_VAL%
call :setenv AZURE_FOUNDRY_ENDPOINT %AZURE_ENDPOINT_VAL%
call :setenv AZURE_FOUNDRY_DEPLOYMENT %AZURE_DEPLOYMENT_VAL%
del "%PS_SETENV%" >nul 2>nul

echo OK: .env atualizado ^(DB_* e AZURE_FOUNDRY_ENDPOINT/DEPLOYMENT^).
echo Lembrete: AZURE_FOUNDRY_API_KEY continua vazia de proposito - preencha manualmente.

echo.
echo [6/8] Verificando/criando o banco de dados '%DB_DATABASE_VAL%'...
where mysql >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "mysql" nao encontrado no PATH.
    echo Abra o terminal do proprio Laragon ^(ele injeta o PATH do MySQL^) ou adicione a pasta bin do MySQL do Laragon ao PATH do Windows.
    goto :erro
)
mysql -h %DB_HOST_VAL% -P %DB_PORT_VAL% -u %DB_USERNAME_VAL% -p%DB_PASSWORD_VAL% -e "CREATE DATABASE IF NOT EXISTS %DB_DATABASE_VAL% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo ERRO: nao foi possivel conectar/criar o banco. Confira se o servico MySQL do Laragon esta rodando
    echo e se usuario/senha configurados no topo deste script batem com o Laragon.
    goto :erro
)
echo OK: banco '%DB_DATABASE_VAL%' pronto.

echo.
echo [7/8] Rodando migrations...
call php artisan migrate --force
if errorlevel 1 goto :erro
echo OK: migrations aplicadas.

echo.
echo [8/8] Rodando db:seed...
call php artisan db:seed --force
if errorlevel 1 goto :erro
echo OK: seeders executados.

echo.
echo === Instalacao concluida com sucesso ===
echo Falta so: preencher AZURE_FOUNDRY_API_KEY no .env antes de usar o agente.
goto :fim

:setenv
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SETENV%" -EnvFile .env -Key %1 -Value %2
exit /b 0

:erro
echo.
echo === Falha durante a instalacao. Verifique a mensagem acima. ===
exit /b 1

:fim
endlocal
