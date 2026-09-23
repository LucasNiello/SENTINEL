@echo off
setlocal enabledelayedexpansion
echo === Sentinel - instalacao do projeto (banco: Supabase Postgres) ===

REM ============================================================
REM CONFIGURACAO - edite aqui antes de rodar
REM ============================================================
REM Preencha com os valores reais do portal do Microsoft Foundry.
REM A API key NAO entra aqui - continua manual no .env depois.
set AZURE_ENDPOINT_VAL=https://sentinel-foundry-tcc26.openai.azure.com
set AZURE_DEPLOYMENT_VAL=gpt-4.1-mini
REM A connection string do Supabase (DB_URL) tambem NAO entra aqui: ela tem senha.
REM Se o .env ainda nao tiver DB_URL, o passo 6 pede no terminal.
REM ============================================================

echo.
echo [1/8] Verificando ambiente (PHP 8.4.1+, extensoes, Composer, npm)...
where php >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "php" nao encontrado no PATH.
    echo Instale o PHP 8.4.1 ou superior ^(https://windows.php.net/download/ - Thread Safe, x64^) e adicione a pasta dele ao PATH.
    goto :erro
)
for /f "delims=" %%v in ('php -r "echo PHP_VERSION;"') do set PHP_VERSION=%%v
php -r "exit(version_compare(PHP_VERSION, '8.4.1', '<') ? 1 : 0);"
if errorlevel 1 (
    echo ERRO: PHP !PHP_VERSION! detectado. Este projeto precisa de PHP 8.4.1 ou superior ^(Laravel 13 traz Symfony 8, que exige isso^).
    goto :erro
)
echo OK: PHP !PHP_VERSION!

set "MISSING_EXT="
for /f "usebackq delims=" %%e in (`php -r "foreach(['pdo_pgsql','pgsql','openssl','mbstring','curl','zip','fileinfo'] as $e){if(extension_loaded($e)===false){echo $e.' ';}}"`) do set "MISSING_EXT=%%e"
if not "!MISSING_EXT!"=="" (
    echo ERRO: extensoes PHP ausentes: !MISSING_EXT!
    echo Rode "php --ini" para achar o php.ini em uso e descomente as linhas extension=... correspondentes ^(ex.: extension=pdo_pgsql^).
    goto :erro
)
echo OK: extensoes PHP necessarias ativas ^(inclui pdo_pgsql e pgsql^).

where composer >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "composer" nao encontrado no PATH. Instale o Composer ^(https://getcomposer.org/download/^) antes de continuar.
    goto :erro
)
if exist package.json (
    where npm >nul 2>nul
    if errorlevel 1 (
        echo ERRO: package.json existe mas "npm" nao foi encontrado no PATH. Instale o Node.js antes de continuar.
        goto :erro
    )
)
echo Ambiente verificado.

echo.
echo [2/8] composer install...
call composer install
if errorlevel 1 goto :erro
echo OK: dependencias PHP instaladas.

if exist package.json (
    echo.
    echo [3/8] npm install...
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
echo [5/8] Gravando DB_CONNECTION e Azure Foundry no .env...

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
    echo # .env pode estar momentaneamente travado ^(antivirus, editor, indexador^) -
    echo # tenta algumas vezes antes de desistir.
    echo $attempts = 0
    echo while ^($attempts -lt 5^) {
    echo     try { Set-Content -Path $EnvFile -Value $content; exit 0 } catch { $attempts++; Start-Sleep -Milliseconds 500 }
    echo }
    echo exit 1
)

call :setenv DB_CONNECTION pgsql
if errorlevel 1 goto :erro
call :setenv AZURE_FOUNDRY_ENDPOINT %AZURE_ENDPOINT_VAL%
if errorlevel 1 goto :erro
call :setenv AZURE_FOUNDRY_DEPLOYMENT %AZURE_DEPLOYMENT_VAL%
if errorlevel 1 goto :erro
del "%PS_SETENV%" >nul 2>nul

echo OK: .env atualizado ^(DB_CONNECTION=pgsql e AZURE_FOUNDRY_ENDPOINT/DEPLOYMENT^).
echo Lembrete: AZURE_FOUNDRY_API_KEY continua vazia de proposito - preencha manualmente.

echo.
echo [6/8] Verificando DB_URL (connection string do Supabase) no .env...

REM A DB_URL tem senha, que pode conter caracteres que o batch e o -replace do PowerShell
REM interpretam ^(& %% ! ^^ $^). Por isso ela nao passa pelo :setenv: este script le a string
REM em entrada oculta e grava a linha inteira sem tratar o valor como regex.
set PS_DBURL=%TEMP%\sentinel_set_dburl.ps1
> "%PS_DBURL%" (
    echo param^([string]$EnvFile^)
    echo $lines = @^(Get-Content $EnvFile^)
    echo $idx = -1
    echo for ^($i = 0; $i -lt $lines.Count; $i++^) { if ^($lines[$i] -match '^^DB_URL='^) { $idx = $i; break } }
    echo if ^($idx -ge 0 -and $lines[$idx].Substring^(7^).Trim^(^).Length -gt 0^) { Write-Host 'DB_URL ja definida no .env - mantendo.'; exit 0 }
    echo Write-Host 'Cole a connection string do Supabase ^(Settings ^> Database ^> Connection string ^> URI^).'
    echo Write-Host 'Formato: postgresql://USUARIO:SENHA@HOST:5432/postgres - senha com caracteres especiais deve estar URL-encoded.'
    echo $sec = Read-Host 'DB_URL ^(entrada oculta^)' -AsSecureString
    echo $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR^($sec^)
    echo try { $url = [Runtime.InteropServices.Marshal]::PtrToStringBSTR^($bstr^).Trim^(^) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR^($bstr^) }
    echo if ^($url -notmatch '^^postgres^(ql^)?://'^) { Write-Host 'ERRO: a string deve comecar com postgresql:// ou postgres://'; exit 2 }
    echo $line = 'DB_URL=' + $url
    echo if ^($idx -ge 0^) { $lines[$idx] = $line } else { $lines += $line }
    echo # .env pode estar momentaneamente travado - tenta algumas vezes antes de desistir.
    echo $attempts = 0
    echo while ^($attempts -lt 5^) {
    echo     try { Set-Content -Path $EnvFile -Value $lines; exit 0 } catch { $attempts++; Start-Sleep -Milliseconds 500 }
    echo }
    echo exit 1
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_DBURL%" -EnvFile .env
set DBURL_RC=!errorlevel!
del "%PS_DBURL%" >nul 2>nul
if not "!DBURL_RC!"=="0" (
    echo ERRO: nao foi possivel definir DB_URL no .env ^(codigo !DBURL_RC!^). Preencha DB_URL manualmente no .env e rode este script de novo.
    goto :erro
)
echo OK: DB_URL disponivel no .env.

echo.
echo [7/8] Rodando migrations no Supabase...
call php artisan migrate --force
if errorlevel 1 (
    echo ERRO: migrations falharam. Confira: DB_URL e senha corretas, rede/firewall liberando a porta do Supabase,
    echo e o modo de conexao ^(direct 5432, session pooler ou transaction pooler 6543^).
    goto :erro
)
echo OK: migrations aplicadas.

echo.
echo [8/8] Rodando db:seed...
call php artisan db:seed --force
if errorlevel 1 (
    REM Rodar o instalador de novo sobre um banco que ja foi semeado antes esbarra em
    REM violacoes de unique constraint ^(ex: usuario de teste duplicado^) - isso nao significa
    REM que o ambiente ficou mal configurado ^(migrations ja rodaram com sucesso^), entao so
    REM avisamos em vez de interromper o script.
    echo AVISO: db:seed falhou - provavelmente o banco ja foi semeado em uma execucao anterior.
    echo O banco do Supabase e remoto e compartilhado: para repopular do zero, use "php artisan migrate:fresh --seed" ^(apaga TODOS os dados^) so se tiver certeza.
) else (
    echo OK: seeders executados.
)

echo.
echo === Instalacao concluida com sucesso ===
echo Falta so: preencher AZURE_FOUNDRY_API_KEY no .env antes de usar o agente.
goto :fim

:setenv
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SETENV%" -EnvFile .env -Key %1 -Value %2
if errorlevel 1 (
    echo ERRO: nao foi possivel gravar %1 no .env ^(arquivo ficou travado por outro processo^).
    exit /b 1
)
exit /b 0

:erro
echo.
echo === Falha durante a instalacao. Verifique a mensagem acima. ===
exit /b 1

:fim
endlocal
