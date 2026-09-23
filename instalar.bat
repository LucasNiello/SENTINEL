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
echo [1/9] Verificando ambiente (PHP, Composer, MySQL via Laragon)...
where php >nul 2>nul
set NEED_ENV=0
if errorlevel 1 set NEED_ENV=1
if "%NEED_ENV%"=="0" (
    php -r "exit(version_compare(PHP_VERSION, '8.4.1', '<') ? 1 : 0);" >nul 2>nul
    if errorlevel 1 set NEED_ENV=1
)
where composer >nul 2>nul
if errorlevel 1 set NEED_ENV=1
where mysql >nul 2>nul
if errorlevel 1 set NEED_ENV=1
if "%NEED_ENV%"=="0" goto :ambiente_ok

if exist "C:\laragon\laragon.exe" goto :laragon_instalado

echo Laragon nao encontrado. Baixando instalador oficial ^(~230MB, pode demorar^)...
set "LARAGON_EXE=%TEMP%\laragon-wamp.exe"
powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://github.com/leokhoa/laragon/releases/download/8.7.0/laragon-wamp.exe' -OutFile '%LARAGON_EXE%'"
if errorlevel 1 (
    echo ERRO: falha ao baixar o instalador do Laragon.
    echo Baixe manualmente em https://laragon.org/download/, instale em C:\laragon e rode este script de novo.
    goto :erro
)

echo Instalando Laragon em C:\laragon - o Windows vai pedir login e senha de administrador ^(UAC^)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%LARAGON_EXE%' -ArgumentList '/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /DIR=C:\laragon' -Verb RunAs -Wait"
if exist "C:\laragon\laragon.exe" goto :laragon_instalado

REM O instalador do Laragon nao documenta flags de instalacao silenciosa - se nao forem aceitas,
REM ele fecha sem instalar nada. Nesse caso abrimos a janela normal para ser concluida manualmente
REM (clicar Next algumas vezes - so acontece nesta primeira execucao, nas maquinas seguintes o
REM instalador silencioso tende a funcionar).
echo Instalacao silenciosa nao foi aceita pelo instalador - abrindo a janela normal para concluir manualmente ^(so na 1a vez^)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%LARAGON_EXE%' -Verb RunAs -Wait"
if not exist "C:\laragon\laragon.exe" (
    echo ERRO: Laragon nao foi instalado em C:\laragon.
    goto :erro
)

:laragon_instalado
echo OK: Laragon disponivel em C:\laragon.

REM O Laragon Full nem sempre vem com todas as versoes de PHP anunciadas na pagina de
REM download - se nenhuma versao instalada bate com o minimo exigido pelo projeto, baixamos
REM o build oficial do PHP 8.4 direto do windows.php.net.
set "PHP_MIN_OK=0"
set "PS_PHP_CHECK=%TEMP%\sentinel_php_check.ps1"
> "%PS_PHP_CHECK%" (
    echo $best = $null
    echo Get-ChildItem 'C:\laragon\bin\php' -Directory -ErrorAction SilentlyContinue ^| ForEach-Object {
    echo     $exe = Join-Path $_.FullName 'php.exe'
    echo     if ^(Test-Path $exe^) {
    echo         try {
    echo             $raw = ^(^& $exe -r 'echo PHP_VERSION;' 2^>$null ^| Select-Object -Last 1^)
    echo             $ver = [version]^($raw -replace '-.*',''^)
    echo             if ^(-not $best -or $ver -gt $best^) { $best = $ver }
    echo         } catch {}
    echo     }
    echo }
    echo if ^($best -and $best -ge [version]'8.4.1'^) { 'ok' } else { 'old' }
)
for /f "usebackq delims=" %%v in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_PHP_CHECK%"`) do set "PHP_MIN_OK=%%v"
del "%PS_PHP_CHECK%" >nul 2>nul

if not "%PHP_MIN_OK%"=="ok" (
    echo PHP 8.4.1+ nao encontrado entre as versoes do Laragon - baixando PHP 8.4 oficial...
    set "PHP_ZIP=%TEMP%\php-8.4-x64.zip"
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://downloads.php.net/~windows/releases/php-8.4.25-Win32-vs17-x64.zip' -OutFile '!PHP_ZIP!'"
    if errorlevel 1 (
        echo ERRO: falha ao baixar o PHP 8.4.
        echo Baixe manualmente em https://windows.php.net/download/ ^(Thread Safe, x64^), extraia para uma pasta dentro de C:\laragon\bin\php e rode o script de novo.
        goto :erro
    )

    set "PHP_NEW_DIR=C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64"

    REM Se o Laragon ja estiver aberto de uma execucao anterior, ele pode manter DLLs de
    REM dentro de bin\php travadas - encerramos os processos dele antes de extrair/sobrescrever.
    taskkill /IM laragon.exe /F >nul 2>nul
    taskkill /IM httpd.exe /F >nul 2>nul
    taskkill /IM php-cgi.exe /F >nul 2>nul
    taskkill /IM mysqld.exe /F >nul 2>nul
    REM Extracao via .NET com algumas tentativas: logo apos escrever os arquivos e comum o
    REM antivirus prender um lock breve neles, o que faz uma unica tentativa de extracao falhar.
    set "PS_PHP_EXTRACT=%TEMP%\sentinel_php_extract.ps1"
    > "!PS_PHP_EXTRACT!" (
        echo param^([string]$ZipPath, [string]$Dest^)
        echo Add-Type -AssemblyName System.IO.Compression.FileSystem
        echo $attempts = 0
        echo while ^($attempts -lt 5^) {
        echo     try {
        echo         if ^(Test-Path $Dest^) { Remove-Item $Dest -Recurse -Force -ErrorAction SilentlyContinue }
        echo         [System.IO.Compression.ZipFile]::ExtractToDirectory^($ZipPath, $Dest^)
        echo         exit 0
        echo     } catch {
        echo         $attempts++
        echo         Start-Sleep -Seconds 2
        echo     }
        echo }
        echo exit 1
    )
    powershell -NoProfile -ExecutionPolicy Bypass -File "!PS_PHP_EXTRACT!" -ZipPath "!PHP_ZIP!" -Dest "!PHP_NEW_DIR!"
    del "!PS_PHP_EXTRACT!" >nul 2>nul
    if not exist "!PHP_NEW_DIR!\php.exe" (
        echo ERRO: falha ao extrair o PHP 8.4 apos varias tentativas ^(possivel bloqueio de antivirus^).
        goto :erro
    )

    REM Reaproveita o php.ini ja configurado pelo Laragon ^(extensoes, timezone, limites^) de
    REM outra versao existente, se houver; senao parte do template de desenvolvimento do PHP.
    REM O extension_dir do Laragon costuma ser um caminho ABSOLUTO para a pasta antiga - se nao
    REM for corrigido para a pasta da nova versao, o PHP tenta carregar DLLs de extensao
    REM compiladas para outra module API e todo extension= do ini falha ao carregar.
    set "PS_PHP_INI=%TEMP%\sentinel_php_ini.ps1"
    > "!PS_PHP_INI!" (
        echo param^([string]$NewDir^)
        echo $old = Get-ChildItem 'C:\laragon\bin\php' -Directory ^| Where-Object { $_.FullName -ne $NewDir -and ^(Test-Path ^(Join-Path $_.FullName 'php.ini'^)^) } ^| Select-Object -First 1
        echo $dst = Join-Path $NewDir 'php.ini'
        echo if ^($old^) { Copy-Item ^(Join-Path $old.FullName 'php.ini'^) $dst -Force } else { Copy-Item ^(Join-Path $NewDir 'php.ini-development'^) $dst -Force }
        echo $extDir = ^(Join-Path $NewDir 'ext'^) -replace '\\','/'
        echo $content = Get-Content $dst
        echo $content = $content -replace '^^;?\s*extension_dir\s*=.*', ^('extension_dir = "' + $extDir + '"'^)
        echo # Composer e Laravel precisam destas extensoes ^(zip, openssl, mbstring, curl, PDO MySQL, fileinfo^) - o template do PHP as traz comentadas.
        echo foreach ^($ext in 'zip','openssl','mbstring','curl','pdo_mysql','mysqli','fileinfo'^) { $content = $content -replace ^('^^;extension=' + $ext + '\s*$'^), ^('extension=' + $ext^) }
        echo Set-Content -Path $dst -Value $content
    )
    powershell -NoProfile -ExecutionPolicy Bypass -File "!PS_PHP_INI!" -NewDir "!PHP_NEW_DIR!"
    del "!PS_PHP_INI!" >nul 2>nul

    del "!PHP_ZIP!" >nul 2>nul
    echo OK: PHP 8.4 instalado em !PHP_NEW_DIR!.
)

echo Iniciando o Laragon ^(Apache/MySQL^)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath 'C:\laragon\laragon.exe'"
REM "timeout" exige um console interativo e falha em execucoes automatizadas - usamos ping como
REM espera portatil (~10s) enquanto o Laragon sobe o Apache/MySQL.
ping -n 11 127.0.0.1 >nul

REM Laragon nao registra seus binarios (PHP, MySQL, Composer, Node) no PATH do Windows - este
REM bloco localiza os executaveis dentro de C:\laragon\bin e adiciona as pastas ao PATH desta
REM sessao e, de forma permanente, ao PATH do usuario, sem depender de nomes de versao fixos.
set "PS_LARAGON_PATH=%TEMP%\sentinel_laragon_path.ps1"
> "%PS_LARAGON_PATH%" (
    echo $root = 'C:\laragon\bin'
    echo $exeNames = @^('mysql.exe','composer.bat','composer.exe','npm.cmd'^)
    echo $dirs = @^(^)
    echo if ^(Test-Path $root^) {
    echo     foreach ^($name in $exeNames^) {
    echo         $found = Get-ChildItem -Path $root -Recurse -Filter $name -ErrorAction SilentlyContinue ^| Select-Object -First 1
    echo         if ^($found^) { $dirs += $found.DirectoryName }
    echo     }
    echo     # O Laragon Full traz varias versoes de PHP lado a lado - escolhemos a mais
    echo     # recente entre elas em vez da primeira encontrada, para bater com o minimo
    echo     # exigido pelo projeto ^(8.4.1^).
    echo     $phpBestDir = $null
    echo     $phpBestVersion = $null
    echo     foreach ^($c in ^(Get-ChildItem -Path $root -Recurse -Filter 'php.exe' -ErrorAction SilentlyContinue^)^) {
    echo         try {
    echo             $v = ^(^& $c.FullName -r "echo PHP_VERSION;" 2^>$null ^| Select-Object -Last 1^)
    echo             if ^($v^) {
    echo                 $ver = [version]^($v -replace '-.*',''^)
    echo                 if ^(-not $phpBestVersion -or $ver -gt $phpBestVersion^) {
    echo                     $phpBestVersion = $ver
    echo                     $phpBestDir = $c.DirectoryName
    echo                 }
    echo             }
    echo         } catch {}
    echo     }
    echo     if ^($phpBestDir^) { $dirs += $phpBestDir }
    echo }
    echo $dirs = $dirs ^| Select-Object -Unique
    echo if ^($dirs^) {
    echo     $userPath = [Environment]::GetEnvironmentVariable^('Path','User'^)
    echo     $missing = $dirs ^| Where-Object { $userPath -notlike "*$_*" }
    echo     if ^($missing^) { [Environment]::SetEnvironmentVariable^('Path', ^($userPath.TrimEnd^(';'^) + ';' + ^($missing -join ';'^)^), 'User'^) }
    echo }
    echo Write-Output ^($dirs -join ';'^)
)
for /f "usebackq delims=" %%p in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_LARAGON_PATH%"`) do set "LARAGON_DIRS=%%p"
del "%PS_LARAGON_PATH%" >nul 2>nul
if not "!LARAGON_DIRS!"=="" set "PATH=!LARAGON_DIRS!;%PATH%"

where php >nul 2>nul
if errorlevel 1 (
    echo ERRO: PHP ainda nao encontrado apos instalar o Laragon.
    echo Abra o Laragon manualmente uma vez ^(icone na bandeja^) e rode este script de novo.
    goto :erro
)
where mysql >nul 2>nul
if errorlevel 1 (
    echo AVISO: mysql nao encontrado no PATH - o passo de banco de dados pode falhar.
    echo Verifique se o MySQL esta ativo no Laragon ^(icone na bandeja, "Start All"^).
)

:ambiente_ok
echo Ambiente verificado.

echo.
echo [2/9] Verificando versao do PHP (minimo 8.4.1 - Laravel 13 traz Symfony 8, que exige isso)...
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
echo [3/9] composer install...
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
    echo [4/9] npm install...
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
    echo [4/9] package.json nao encontrado - pulando npm install.
)

echo.
echo [5/9] Configurando .env...
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
echo [6/9] Gravando configuracao de banco e Azure Foundry no .env...

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

call :setenv DB_CONNECTION mysql
if errorlevel 1 goto :erro
call :setenv DB_HOST %DB_HOST_VAL%
if errorlevel 1 goto :erro
call :setenv DB_PORT %DB_PORT_VAL%
if errorlevel 1 goto :erro
call :setenv DB_DATABASE %DB_DATABASE_VAL%
if errorlevel 1 goto :erro
call :setenv DB_USERNAME %DB_USERNAME_VAL%
if errorlevel 1 goto :erro
call :setenv DB_PASSWORD %DB_PASSWORD_VAL%
if errorlevel 1 goto :erro
call :setenv AZURE_FOUNDRY_ENDPOINT %AZURE_ENDPOINT_VAL%
if errorlevel 1 goto :erro
call :setenv AZURE_FOUNDRY_DEPLOYMENT %AZURE_DEPLOYMENT_VAL%
if errorlevel 1 goto :erro
del "%PS_SETENV%" >nul 2>nul

echo OK: .env atualizado ^(DB_* e AZURE_FOUNDRY_ENDPOINT/DEPLOYMENT^).
echo Lembrete: AZURE_FOUNDRY_API_KEY continua vazia de proposito - preencha manualmente.

echo.
echo [7/9] Verificando/criando o banco de dados '%DB_DATABASE_VAL%'...
where mysql >nul 2>nul
if errorlevel 1 (
    echo ERRO: comando "mysql" nao encontrado no PATH.
    echo Abra o terminal do proprio Laragon ^(ele injeta o PATH do MySQL^) ou adicione a pasta bin do MySQL do Laragon ao PATH do Windows.
    goto :erro
)
mysql -h %DB_HOST_VAL% -P %DB_PORT_VAL% -u %DB_USERNAME_VAL% -p%DB_PASSWORD_VAL% -e "CREATE DATABASE IF NOT EXISTS %DB_DATABASE_VAL% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul
if not errorlevel 1 goto :banco_ok

echo Senha configurada nao funcionou - tentando com senha em branco ^(padrao de instalacao nova do Laragon^)...
mysql -h %DB_HOST_VAL% -P %DB_PORT_VAL% -u %DB_USERNAME_VAL% -e "CREATE DATABASE IF NOT EXISTS %DB_DATABASE_VAL% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; ALTER USER '%DB_USERNAME_VAL%'@'localhost' IDENTIFIED BY '%DB_PASSWORD_VAL%'; FLUSH PRIVILEGES;"
if errorlevel 1 (
    echo ERRO: nao foi possivel conectar/criar o banco nem com a senha configurada nem com senha em branco.
    echo Confira se o servico MySQL do Laragon esta rodando ^(icone na bandeja^) e ajuste DB_PASSWORD_VAL no topo deste script se necessario.
    goto :erro
)
echo OK: senha do root do MySQL definida como '%DB_PASSWORD_VAL%' para as proximas execucoes.

:banco_ok
echo OK: banco '%DB_DATABASE_VAL%' pronto.

echo.
echo [8/9] Rodando migrations...
call php artisan migrate --force
if errorlevel 1 goto :erro
echo OK: migrations aplicadas.

echo.
echo [9/9] Rodando db:seed...
call php artisan db:seed --force
if errorlevel 1 (
    REM Rodar o instalador de novo sobre um banco que ja foi semeado antes esbarra em
    REM violacoes de unique constraint ^(ex: usuario de teste duplicado^) - isso nao significa
    REM que o ambiente ficou mal configurado ^(migrations ja rodaram com sucesso^), entao so
    REM avisamos em vez de interromper o script.
    echo AVISO: db:seed falhou - provavelmente o banco ja foi semeado em uma execucao anterior.
    echo Se quiser repopular do zero, apague o banco '%DB_DATABASE_VAL%' e rode o script de novo.
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
