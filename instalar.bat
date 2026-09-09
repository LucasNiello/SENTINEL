@echo off
setlocal enabledelayedexpansion
echo === Sentinel - instalacao do projeto ===

echo.
echo [1/6] Verificando versao do PHP (minimo 8.4.1 - Laravel 13 traz Symfony 8, que exige isso)...
where php >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "php" nao encontrado no PATH.
    echo Instale o PHP 8.4.1 ou superior e garanta que ele esteja no PATH desta maquina.
    goto :erro
)
for /f "delims=" %%v in ('php -r "echo PHP_VERSION;"') do set PHP_VERSION=%%v
php -r "exit(version_compare(PHP_VERSION, '8.4.1', '<') ? 1 : 0);"
if errorlevel 1 (
    echo ERRO: PHP !PHP_VERSION! detectado. Este projeto precisa de PHP 8.4.1 ou superior.
    echo Instale uma versao compativel e garanta que ela seja a resolvida pelo PATH.
    goto :erro
)
echo OK: PHP !PHP_VERSION!

echo.
echo [2/6] composer install...
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
    echo [3/6] npm install...
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
    echo [3/6] package.json nao encontrado - pulando npm install.
)

echo.
echo [4/6] Configurando .env...
if exist .env (
    echo .env ja existe - mantendo como esta, nao sobrescrevo.
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
echo Lembrete: AZURE_FOUNDRY_ENDPOINT / AZURE_FOUNDRY_API_KEY / AZURE_FOUNDRY_DEPLOYMENT
echo ficam vazias de proposito no .env. Preencha manualmente - este script nao pede
echo nem aceita essas credenciais como entrada.

echo.
echo [5/6] Rodando migrations...
call php artisan migrate --force
if errorlevel 1 (
    echo.
    echo ERRO: as migrations falharam - provavelmente a conexao com o banco nao esta configurada
    echo para esta maquina. Abra o .env e ajuste DB_CONNECTION / DB_HOST / DB_PORT / DB_DATABASE /
    echo DB_USERNAME / DB_PASSWORD manualmente, depois rode este script de novo.
    echo Este script nao cria banco nem assume Laragon/MySQL/porta especifica - cada maquina e diferente.
    goto :erro
)
echo OK: migrations aplicadas.

echo.
echo [6/6] Rodando db:seed...
call php artisan db:seed --force
if errorlevel 1 goto :erro
echo OK: seeders executados.

echo.
echo === Instalacao concluida com sucesso ===
goto :fim

:erro
echo.
echo === Falha durante a instalacao. Verifique a mensagem acima. ===
exit /b 1

:fim
endlocal
