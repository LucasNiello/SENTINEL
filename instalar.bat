@echo off
setlocal DisableDelayedExpansion
pushd "%~dp0"
if errorlevel 1 exit /b 1
set "SENTINEL_BOOTSTRAP_FILE=%~f0"
REM PowerShell stays embedded; secrets never pass through cmd.exe.
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -Command "$s=[IO.File]::ReadAllText($env:SENTINEL_BOOTSTRAP_FILE); & ([scriptblock]::Create(($s -split '(?m)^# POWERSHELL_PAYLOAD\r?$',2)[1]))"
set "SENTINEL_EXIT=%ERRORLEVEL%"
if "%SENTINEL_EXIT%"=="0" goto :done
echo.
echo ERRO: consulte a etapa e a sugestao acima.
if not defined SENTINEL_NONINTERACTIVE pause
:done
popd
exit /b %SENTINEL_EXIT%
# POWERSHELL_PAYLOAD
# Windows PowerShell 5.1. Only this BAT is needed; helpers are temporary.
# Current lock: PHP >=8.4.1 <8.6 (Symfony/Nette), Vite 8 Node engines.
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
$root=Split-Path -Parent $env:SENTINEL_BOOTSTRAP_FILE
Set-Location -LiteralPath $root
$utf8=New-Object Text.UTF8Encoding($false)
$OutputEncoding=$utf8
[Console]::OutputEncoding=$utf8
$local=Join-Path $root '.sentinel'
$log=Join-Path $root 'storage\logs\sentinel-bootstrap.log'
$script:stage='Detectando ambiente'
$secrets=New-Object 'Collections.Generic.List[string]'
$tmp=$null; $lockHandle=$null; $exitCode=1; $state=@{}
function Write-Utf8($path,$value) { [IO.File]::WriteAllText($path,$value,$utf8) }
function Protect-Directory($path) {
    $acl=New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    foreach ($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User,(New-Object Security.Principal.SecurityIdentifier('S-1-5-18')))) {
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')))
    }
    Set-Acl -LiteralPath $path -AclObject $acl
}
function Redact([string]$text) {
    foreach ($secret in $secrets) { if ($secret) { $text=$text.Replace($secret,'[REDACTED]') } }
    $text=$text -replace '(?i)((?:password|api[_-]?key|app_key|token|secret)\s*[=:]\s*)[^\r\n]+','$1[REDACTED]'
    return ($text -replace '(?i)(://)[^\s/@]+:[^\s/@]+@','$1[REDACTED]@')
}
function Log([string]$text) { [IO.File]::AppendAllText($log,('{0:s} [{1}] {2}' -f (Get-Date),$stage,(Redact $text))+"`r`n",$utf8) }
function Step($name) { $script:stage=$name; Write-Host "`n[...] $name"; Log 'Inicio' }
function Ok($text) { Write-Host "[OK] $text" -ForegroundColor Green; Log $text }
function Run($exe,[string[]]$arguments,[switch]$AllowFailure) {
    if ([IO.Path]::GetFileName($exe) -eq 'winget.exe') {
        # Some machines have a stalled App Installer/source. Bound our own child only.
        $stdout=Join-Path $tmp 'winget-out.txt'; $stderr=Join-Path $tmp 'winget-err.txt'
        $child=Start-Process $exe -ArgumentList $arguments -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
        $limit=600000; if ($arguments[0] -eq 'show') { $limit=45000 }
        if (!$child.WaitForExit($limit)) { $child.Kill(); $child.WaitForExit(); Log 'winget excedeu o prazo; usando alternativa oficial.'; return @{Code=1;Text='winget timeout'} }
        $child.WaitForExit(); $text=[IO.File]::ReadAllText($stdout)+[IO.File]::ReadAllText($stderr); Log $text
        return @{Code=$child.ExitCode;Text=$text}
    }
    $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
    try { $out=@(& $exe @arguments 2>&1); $code=$LASTEXITCODE } finally { $ErrorActionPreference=$old }
    $text=($out | ForEach-Object { $_.ToString() }) -join "`n"
    Log $text
    if ($code -ne 0 -and !$AllowFailure) {
        Write-Host (Redact $text) -ForegroundColor Red
        throw "Comando falhou (codigo $code): $([IO.Path]::GetFileName($exe)) $($arguments -join ' '). Consulte o log."
    }
    return @{Code=$code; Text=$text}
}
function Secret-Input($label) {
    if ($env:SENTINEL_NONINTERACTIVE) { throw "$label ausente. Execute instalar.bat interativamente para informar a credencial." }
    $value=[Net.NetworkCredential]::new('',(Read-Host $label -AsSecureString)).Password
    if ($value) { $secrets.Add($value) }; return $value
}
function New-Password {
    $bytes=New-Object byte[] 32; $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $value=[Convert]::ToBase64String($bytes).TrimEnd('='); $secrets.Add($value); return $value
}
function Download($url,$destination,$hash='',$algorithm='SHA256') {
    $uri=[uri]$url
    if ($uri.Scheme -ne 'https' -or $uri.Host -notmatch '(^|\.)(php\.net|getcomposer\.org|nodejs\.org|mysql\.com|microsoft\.com|aka\.ms)$') { throw 'Origem de download nao autorizada.' }
    for ($attempt=1; $attempt -le 3; $attempt++) {
        try {
            Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $destination -TimeoutSec 300
            if ($hash -and (Get-FileHash -LiteralPath $destination -Algorithm $algorithm).Hash -ne $hash) { throw 'Checksum divergente.' }
            return
        } catch { if ($attempt -eq 3) { throw }; Start-Sleep -Seconds 2 }
    }
}
function Candidates($name,[string[]]$patterns) {
    $paths=@(Get-Command $name -All -ErrorAction SilentlyContinue | ForEach-Object Source)
    foreach ($pattern in $patterns) { $paths+=@(Get-Item -Path $pattern -ErrorAction SilentlyContinue | ForEach-Object FullName) }
    return @($paths | Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Leaf) } | Select-Object -Unique)
}
function Save-State { Write-Utf8 (Join-Path $local 'state.json') ($state | ConvertTo-Json -Depth 5) }
function Fingerprint([string[]]$paths) {
    $items=foreach ($path in $paths) {
        if (Test-Path -LiteralPath $path) {
            Get-ChildItem -LiteralPath $path -File -Recurse | Sort-Object FullName | ForEach-Object { $_.FullName.Substring($root.Length)+':'+(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }
        }
    }
    $sha=[Security.Cryptography.SHA256]::Create()
    try { return [BitConverter]::ToString($sha.ComputeHash($utf8.GetBytes(($items -join "`n")))) } finally { $sha.Dispose() }
}
function Bridge($action,$data=@{}) {
    # Credentials travel through stdin, never through command-line arguments/logs.
    $json=$data | ConvertTo-Json -Depth 10 -Compress
    $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
    try { $output=$json | & $script:php $script:helper $action $root 2>&1; $code=$LASTEXITCODE } finally { $ErrorActionPreference=$old }
    if ($code -ne 0) { throw "Helper PHP ($action) falhou. Verifique sintaxe do .env, permissoes e dependencias." }
    $result=($output -join "`n") | ConvertFrom-Json
    return $result
}
function Load-Env {
    $script:cfg=@{}; $values=Bridge 'env-read'
    if ($values.ok -eq $false) { throw '.env invalido. Corrija sua sintaxe antes de continuar.' }
    foreach ($p in $values.PSObject.Properties) {
        $script:cfg[$p.Name]=[string]$p.Value
        if ($p.Name -match '(?i)KEY|PASSWORD|TOKEN|SECRET|URL' -and $p.Value) { $secrets.Add([string]$p.Value) }
    }
}
function Set-Env($values) {
    foreach ($key in $values.Keys) { if ($key -match '(?i)KEY|PASSWORD|TOKEN|SECRET' -and $values[$key]) { $secrets.Add([string]$values[$key]) } }
    if (!(Bridge 'env-set' $values).ok) { throw 'Nao foi possivel atualizar .env com seguranca.' }; Load-Env
}
function Db($action='probe',$overrides=@{}) {
    $data=@{host=$cfg.DB_HOST; port=$cfg.DB_PORT; database=$cfg.DB_DATABASE; user=$cfg.DB_USERNAME; password=$cfg.DB_PASSWORD}
    foreach ($key in $overrides.Keys) { $data[$key]=$overrides[$key] }; return Bridge $action $data
}
function Port-Open($hostname,[int]$port) {
    $client=New-Object Net.Sockets.TcpClient
    try { return ($client.ConnectAsync($hostname,$port).Wait(600) -and $client.Connected) } catch { return $false } finally { $client.Dispose() }
}
function Wait-MySql {
    $deadline=(Get-Date).AddSeconds(60)
    do { $result=Db; if ($result.ok -or $result.code -eq 1045) { return $result }; Start-Sleep -Milliseconds 750 } while ((Get-Date) -lt $deadline)
    return $result
}
function Ensure-VC {
    $vc=Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
    if ($vc -and $vc.Installed -eq 1) { return }
    Write-Host 'Instalando runtime Microsoft Visual C++ (o Windows pode solicitar UAC)...'
    $file=Join-Path $tmp 'vc_redist.x64.exe'; Download 'https://aka.ms/vs/17/release/vc_redist.x64.exe' $file
    if ((Get-AuthenticodeSignature $file).Status -ne 'Valid') { throw 'Assinatura Microsoft invalida.' }
    $p=Start-Process $file -ArgumentList '/install /quiet /norestart' -Verb RunAs -WindowStyle Hidden -Wait -PassThru
    if ($p.ExitCode -notin 0,1638,3010) { throw 'Falha no runtime Visual C++. Verifique o UAC.' }
}
function Set-TempoMaximo([string]$content) {
    # N3: on Windows the limit is wall-clock; php.ini-development's 30 s ties with the Azure timeout(30).
    # Ensures 60 s and never lowers: 0 (no limit) or >= 60 stay; a commented line counts as absent.
    $pattern='(?m)^[ \t]*max_execution_time[ \t]*=[ \t]*"?(\d+)"?[ \t]*(?=\r?$)'
    $active=[regex]::Matches($content,$pattern)
    if (!$active.Count) { return $content+"`r`nmax_execution_time = 60`r`n" }
    $current=[int]$active[$active.Count-1].Groups[1].Value
    if ($current -eq 0 -or $current -ge 60) { return $content }
    return [regex]::Replace($content,$pattern,'max_execution_time = 60')
}
function Ensure-Php {
    Step 'PHP e extensoes'; $available=@()
    foreach ($candidate in (Candidates 'php.exe' @('C:\php\php.exe','C:\laragon\bin\php\*\php.exe',"$local\php-*\php.exe",'C:\Program Files\PHP\*\php.exe'))) {
        $probe=Run $candidate @('-n','-r','echo PHP_VERSION;') -AllowFailure
        if ($probe.Code -eq 0 -and $probe.Text.Trim() -match '^8\.[45]\.\d+$' -and [version]$probe.Text.Trim() -ge [version]'8.4.1') { $available+=@{Path=$candidate; Version=[version]$probe.Text.Trim()} }
    }
    # Bootstrap range derived from composer.lock; Composer validates all exact constraints below.
    if (!$available.Count) {
        Ensure-VC
        $releases=Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
        $release=$releases.'8.5'; if (!$release) { $release=$releases.'8.4' }
        $zip=($release.PSObject.Properties | Where-Object Name -match '^nts-.*-x64$' | Select-Object -First 1).Value.zip
        if (!$zip.path -or !$zip.sha256) { throw 'Metadados oficiais PHP incompletos.' }
        $archive=Join-Path $tmp 'php.zip'; Download ('https://windows.php.net/downloads/releases/'+$zip.path) $archive $zip.sha256
        $dest=Join-Path $local ('php-'+$release.version); Expand-Archive -LiteralPath $archive -DestinationPath $dest -Force
        $available+=@{Path=(Join-Path $dest 'php.exe'); Version=[version]$release.version}
    }
    $script:php=($available | Sort-Object Version -Descending | Select-Object -First 1).Path
    $phpDir=Split-Path $php; $env:Path="$phpDir;$env:Path"
    $ini=(Run $php @('-r','echo php_ini_loaded_file();')).Text.Trim()
    if (!$ini) { $ini=Join-Path $phpDir 'php.ini'; Copy-Item -LiteralPath (Join-Path $phpDir 'php.ini-development') -Destination $ini }
    $required=@('fileinfo','zip','openssl','mbstring','curl','pdo','pdo_mysql','mysqli','pdo_sqlite')
    $lock=Get-Content composer.lock -Raw | ConvertFrom-Json
    foreach ($package in @($lock.packages)+@($lock.'packages-dev')+@((Get-Content composer.json -Raw | ConvertFrom-Json))) {
        $required+=@($package.require.PSObject.Properties | Where-Object Name -like 'ext-*' | ForEach-Object { $_.Name.Substring(4) })
    }
    $modules=(Run $php @('-m')).Text -split '\r?\n'; $content=[IO.File]::ReadAllText($ini); $original=$content
    foreach ($ext in ($required | Select-Object -Unique)) {
        if ($modules -icontains $ext) { continue }
        $dll=Join-Path $phpDir "ext\php_$ext.dll"
        if (!(Test-Path -LiteralPath $dll)) { throw "Extensao $ext ausente em $phpDir. Instale um build oficial completo de PHP." }
        $pattern='(?m)^\s*;?\s*extension\s*=\s*"?(?:php_)?'+[regex]::Escape($ext)+'(?:\.dll)?"?\s*$'
        $content=[regex]::Replace($content,$pattern,'; configured by SENTINEL below')
        $content+="`r`nextension=`"$($dll.Replace('\','/'))`"`r`n"
    }
    $content=Set-TempoMaximo $content
    if ($content -ne $original) { Write-Utf8 $ini $content }
    $modules=(Run $php @('-m')).Text -split '\r?\n'
    foreach ($ext in ($required | Select-Object -Unique)) { if ($modules -inotcontains $ext) { throw "PHP nao carregou $ext. Confira $ini e suas DLLs." } }
    $null=Run $php @('--ini'); Ok ((Run $php @('-v')).Text.Split("`n")[0])
}
function Ensure-Composer {
    Step 'Composer'; $script:composer=$null
    $paths=@('C:\ProgramData\ComposerSetup\bin\composer.phar','C:\laragon\bin\composer\composer.phar',"$local\composer.phar")
    foreach ($cmd in (Candidates 'composer.bat' @())) { $paths+=(Join-Path (Split-Path $cmd) 'composer.phar') }
    foreach ($candidate in (Candidates 'composer.phar' $paths)) {
        if ((Run $php @($candidate,'--version','--no-ansi') -AllowFailure).Code -eq 0) { $script:composer=$candidate; break }
    }
    if (!$composer) {
        $setup=Join-Path $tmp 'composer-setup.php'; $sig=(Invoke-RestMethod 'https://composer.github.io/installer.sig').Trim()
        Download 'https://getcomposer.org/installer' $setup $sig 'SHA384'
        $null=Run $php @($setup,'--2',"--install-dir=$local",'--filename=composer.phar','--quiet'); $script:composer=Join-Path $local 'composer.phar'
    }
    Ok ((Run $php @($composer,'--version','--no-ansi')).Text.Split("`n")[0])
    $null=Run $php @($composer,'check-platform-reqs','--lock','--no-ansi')
}
function Ensure-Node {
    if (!(Test-Path package.json)) { return }; Step 'Node e NPM'
    $checker=Join-Path $tmp 'node-check.cjs'
    Write-Utf8 $checker @'
const fs=require('fs'),path=require('path');
try {
 const dir=process.argv[2],root=process.argv[3],v=process.version;
 const semver=require(path.join(dir,'node_modules/npm/node_modules/semver'));
 const pkg=JSON.parse(fs.readFileSync(path.join(root,'package.json')));
 const file=path.join(root,'package-lock.json');
 const packages=fs.existsSync(file)?JSON.parse(fs.readFileSync(file)).packages||{}:{};
 const ranges=[pkg.engines?.node,...Object.values(packages).map(p=>p.engines?.node)].filter(Boolean);
 if(!ranges.every(r=>semver.satisfies(v,r)))process.exit(1);
 const npm=JSON.parse(fs.readFileSync(path.join(dir,'node_modules/npm/package.json')));
 if(pkg.engines?.npm&&!semver.satisfies(npm.version,pkg.engines.npm))process.exit(1);
 console.log(v);
}catch(e){process.exit(2);}
'@
    $script:node=$null
    foreach ($candidate in (Candidates 'node.exe' @('C:\Program Files\nodejs\node.exe','C:\laragon\bin\nodejs\*\node.exe',"$local\node-*\node.exe"))) {
        $dir=Split-Path $candidate
        if ((Test-Path -LiteralPath (Join-Path $dir 'npm.cmd')) -and (Run $candidate @($checker,$dir,$root) -AllowFailure).Code -eq 0) { $script:node=$candidate; break }
    }
    if (!$node) {
        $releases=Invoke-RestMethod 'https://nodejs.org/dist/index.json'
        $tried=@{}
        foreach ($release in ($releases | Where-Object { $_.lts })) {
            $major=($release.version -split '\.')[0]; if ($tried[$major]) { continue }; $tried[$major]=$true
            if ([int]$major.TrimStart('v') -lt 22) { continue }
            $name="node-$($release.version)-win-x64"; $base="https://nodejs.org/dist/$($release.version)"
            $sums=Invoke-RestMethod "$base/SHASUMS256.txt"
            $match=[regex]::Match($sums,'(?m)^([a-f0-9]{64})\s+'+[regex]::Escape("$name.zip")+'\s*$')
            if (!$match.Success) { throw 'Checksum Node nao encontrado.' }
            $archive=Join-Path $tmp 'node.zip'; Download "$base/$name.zip" $archive $match.Groups[1].Value
            Expand-Archive -LiteralPath $archive -DestinationPath $local -Force
            $dir=Join-Path $local $name; $candidate=Join-Path $dir 'node.exe'
            if ((Run $candidate @($checker,$dir,$root) -AllowFailure).Code -eq 0) { $script:node=$candidate; break }
        }
    }
    if (!$node) { throw 'Nenhum Node LTS compativel com engines encontrado.' }
    $dir=Split-Path $node; $env:Path="$dir;$env:Path"; $script:npm=Join-Path $dir 'npm.cmd'
    Ok ('Node '+(Run $node @('--version')).Text.Trim()); Ok ('NPM '+(Run $npm @('--version')).Text.Trim())
}
function Start-ManagedMySql {
    $managed=Join-Path $local 'mysql'; $metadata=Join-Path $managed 'server.json'
    if (Test-Path -LiteralPath $metadata) { $server=Get-Content -LiteralPath $metadata -Raw | ConvertFrom-Json }
    else {
        New-Item -ItemType Directory -Force -Path $managed | Out-Null; Protect-Directory $managed
        $binaries=@(Candidates 'mysqld.exe' @('C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe',"$local\mysql-*\bin\mysqld.exe"))
        if (!$binaries.Count) {
            # ID verified in the Microsoft winget repository; validate availability at runtime.
            $winget=Get-Command winget.exe -ErrorAction SilentlyContinue
            if ($winget) {
                $shown=Run $winget.Source @('show','--id','Oracle.MySQL','--exact','--source','winget','--accept-source-agreements','--disable-interactivity') -AllowFailure
                if ($shown.Code -eq 0) {
                    $null=Run $winget.Source @('install','--id','Oracle.MySQL','--exact','--source','winget','--silent','--accept-package-agreements','--accept-source-agreements','--disable-interactivity') -AllowFailure
                    $binaries=@(Candidates 'mysqld.exe' @('C:\Program Files\MySQL\MySQL Server *\bin\mysqld.exe'))
                }
            }
        }
        if (!$binaries.Count) {
            # Portable fallback: Microsoft release metadata, Oracle binary, no fixed patch URL.
            $versions=Invoke-RestMethod 'https://api.github.com/repos/microsoft/winget-pkgs/contents/manifests/o/Oracle/MySQL'
            $version=$versions.name | Where-Object { $_ -match '^8\.4\.\d+$' } | Sort-Object { [version]$_ } -Descending | Select-Object -First 1
            if (!$version) { throw 'Nao foi possivel resolver MySQL 8.4 LTS.' }; Ensure-VC
            $archive=Join-Path $tmp 'mysql.zip'; Download "https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-$version-winx64.zip" $archive
            Expand-Archive -LiteralPath $archive -DestinationPath $local -Force
            $binaries=@(Join-Path $local "mysql-$version-winx64\bin\mysqld.exe")
            if ((Get-AuthenticodeSignature $binaries[0]).Status -ne 'Valid') { throw 'Assinatura do mysqld oficial invalida.' }
        }
        $port=3306; while (Port-Open '127.0.0.1' $port) { $port++; if ($port -gt 3399) { throw 'Nenhuma porta MySQL disponivel.' } }
        $server=@{exe=$binaries[0]; port=$port}; Write-Utf8 $metadata ($server | ConvertTo-Json)
    }
    $adminFile=Join-Path $managed 'root.dpapi.xml'
    if (!(Test-Path -LiteralPath $adminFile)) { ConvertTo-SecureString (New-Password) -AsPlainText -Force | Export-Clixml -LiteralPath $adminFile }
    $admin=[Net.NetworkCredential]::new('',(Import-Clixml -LiteralPath $adminFile)).Password; $secrets.Add($admin)
    $data=Join-Path $managed 'data'; $ini=Join-Path $managed 'my.ini'
    Write-Utf8 $ini ("[mysqld]`r`nbasedir=`"$((Split-Path (Split-Path $server.exe)).Replace('\','/'))`"`r`ndatadir=`"$($data.Replace('\','/'))`"`r`nport=$($server.port)`r`nbind-address=127.0.0.1`r`nmysqlx=0`r`nlocal-infile=0`r`nlog-error=`"$((Join-Path $managed 'mysql.log').Replace('\','/'))`"`r`n")
    if (!(Test-Path -LiteralPath (Join-Path $data 'mysql'))) {
        if ((Test-Path -LiteralPath $data) -and @(Get-ChildItem -LiteralPath $data -Force).Count) { throw 'Inicializacao MySQL incompleta: preserve .sentinel/mysql/data e consulte mysql.log. Nenhum dado foi apagado.' }
        $null=Run $server.exe @("--defaults-file=$ini",'--initialize-insecure','--console')
    }
    Set-Env @{DB_HOST='127.0.0.1'; DB_PORT=[string]$server.port}
    if (!(Port-Open '127.0.0.1' $server.port)) {
        $init=Join-Path $tmp 'mysql-init.sql'
        # Protected temporary directory; base64 has no SQL quotes/backslashes.
        Write-Utf8 $init "ALTER USER 'root'@'localhost' IDENTIFIED BY '$admin';"
        $null=Start-Process $server.exe -ArgumentList @("--defaults-file=`"$ini`"","--init-file=`"$init`"") -WindowStyle Hidden -PassThru
        $deadline=(Get-Date).AddSeconds(60)
        do { $probe=Db 'probe' @{user='root'; password=$admin}; if ($probe.ok) { break }; Start-Sleep -Milliseconds 750 } while ((Get-Date) -lt $deadline)
        if (!$probe.ok) { throw 'MySQL local nao iniciou em 60 segundos. Consulte .sentinel/mysql/mysql.log.' }
        Remove-Item -LiteralPath $init -Force
    }
    if (!(Db).ok) {
        $password=$cfg.DB_PASSWORD
        if ($cfg.DB_USERNAME -ne 'sentinel_app' -or !$password) { $password=New-Password }
        # Persist before CREATE USER, so interruption cannot orphan a generated password.
        Set-Env @{DB_USERNAME='sentinel_app'; DB_PASSWORD=$password}
        $created=Db 'provision' @{user='root'; password=$admin; appPassword=$password}
        if (!$created.ok) { throw "Falha criando usuario dedicado (MySQL $($created.code))." }
        if (!(Db).ok) { throw 'Usuario dedicado existente tem outra senha. A senha do servidor nao foi alterada.' }
    }
}
function Ensure-MySql {
    Step 'MySQL'; $probe=Db
    if (!$probe.ok -and $cfg.DB_HOST -in @('127.0.0.1','localhost') -and !(Port-Open $cfg.DB_HOST ([int]$cfg.DB_PORT))) {
        # Reuse an already running local MySQL even when its configured port differs.
        $mysqlPids=@(Get-CimInstance Win32_Process -Filter "name='mysqld.exe'" | ForEach-Object ProcessId)
        if ($mysqlPids.Count) {
            foreach ($listener in @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.OwningProcess -in $mysqlPids })) {
                $test=Db 'probe' @{host='127.0.0.1';port=[string]$listener.LocalPort}
                if ($test.ok -and $test.version -notmatch 'MariaDB') { Set-Env @{DB_HOST='127.0.0.1';DB_PORT=[string]$listener.LocalPort}; $probe=$test; break }
            }
        }
    }
    if (!$probe.ok -and $cfg.DB_HOST -in @('127.0.0.1','localhost','::1') -and !(Port-Open $cfg.DB_HOST ([int]$cfg.DB_PORT))) {
        if (Test-Path -LiteralPath (Join-Path $local 'mysql\server.json')) { Start-ManagedMySql }
        else {
            $services=@(Get-CimInstance Win32_Service | Where-Object { $_.PathName -match 'mysqld\.exe' })
            foreach ($svc in $services) {
                if ($svc.State -eq 'Running') { continue }
                try { Start-Service -Name $svc.Name } catch {
                    $command="Start-Service -Name '$($svc.Name.Replace("'","''"))'"
                    $encoded=[Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
                    $p=Start-Process powershell.exe -Verb RunAs -WindowStyle Hidden -ArgumentList "-NoProfile -EncodedCommand $encoded" -Wait -PassThru
                    if ($p.ExitCode -ne 0) { throw 'Nao foi possivel iniciar o servico MySQL. Verifique o UAC.' }
                }; break
            }
            if ($services.Count) { $probe=Wait-MySql }
            else {
                $laragon=@(Candidates 'mysqld.exe' @('C:\laragon\bin\mysql\*\bin\mysqld.exe')); $started=$false
                foreach ($exe in $laragon) {
                    $ini=Join-Path (Split-Path (Split-Path $exe)) 'my.ini'
                    if (!(Test-Path -LiteralPath $ini)) { continue }
                    if ((Run $exe @('--version')).Text -match 'MariaDB') { continue }
                    $section=[regex]::Match([IO.File]::ReadAllText($ini),'(?ms)^\[mysqld\](.*?)(?=^\[|\z)').Groups[1].Value
                    $configuredPort=[regex]::Match($section,'(?m)^\s*port\s*=\s*(\d+)')
                    if ($configuredPort.Success) { Set-Env @{DB_PORT=$configuredPort.Groups[1].Value} }
                    if (Port-Open $cfg.DB_HOST ([int]$cfg.DB_PORT)) { $started=$true; $probe=Db; break }
                    $null=Start-Process $exe -ArgumentList "--defaults-file=`"$ini`"" -WindowStyle Hidden -PassThru
                    $started=$true; $probe=Wait-MySql; break
                }
                if (!$started) { Start-ManagedMySql }
            }
        }
    }
    $probe=Db
    if (!$probe.ok) {
        if ($probe.code -eq 1045) {
            $fallback=Db 'probe' @{user='root'; password=''}
            if ($fallback.ok -and $cfg.DB_HOST -in @('127.0.0.1','localhost')) { Set-Env @{DB_USERNAME='root'; DB_PASSWORD=''} }
            else {
                if ($env:SENTINEL_NONINTERACTIVE) { throw 'MySQL recusou as credenciais. Execute interativamente para informa-las.' }
                $username=Read-Host 'Usuario do MySQL existente'; $password=Secret-Input 'Senha do MySQL existente'
                $test=Db 'probe' @{user=$username; password=$password}
                if (!$test.ok) { throw "Credenciais MySQL recusadas (codigo $($test.code))." }; Set-Env @{DB_USERNAME=$username; DB_PASSWORD=$password}
            }
        } elseif ($cfg.DB_HOST -in @('127.0.0.1','localhost') -and (Port-Open $cfg.DB_HOST ([int]$cfg.DB_PORT)) -and $probe.code -in @(2002,2006,2013)) {
            Start-ManagedMySql
        } else { throw "MySQL indisponivel (codigo $($probe.code)). Confira host, porta e servico; nenhum processo foi encerrado." }
    }
    $probe=Db; if (!$probe.ok) { throw "Falha na conexao real MySQL (codigo $($probe.code))." }
    if ($probe.version -match 'MariaDB') { throw 'MariaDB detectado. Este instalador exige MySQL; nenhum servidor foi substituido.' }
    Ok ('MySQL '+$probe.version)
    $database=Db 'database'; if (!$database.ok) { throw "CREATE DATABASE/conexao recusada (codigo $($database.code)). Conceda acesso ao banco configurado." }
    if ($database.empty) { $state.seedPending=$true; $state.seedDatabase="$($cfg.DB_HOST):$($cfg.DB_PORT)/$($cfg.DB_DATABASE)"; Save-State }
    Ok ('Banco '+$cfg.DB_DATABASE)
}
function Http-Ok($url) { try { return (Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 3).StatusCode -eq 200 } catch { return $false } }
function Own-Server([int]$port) {
    foreach ($listener in @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)) {
        $process=Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)" -ErrorAction SilentlyContinue
        if ($process.Name -eq 'php.exe' -and $process.CommandLine -match [regex]::Escape($root.TrimEnd('\')+'\') -and $process.CommandLine -match '(server\.php|artisan.+serve)') { return $true }
    }; return $false
}

try {
    Write-Host '====================================================='
    Write-Host ' SENTINEL - Ambiente de Desenvolvimento'
    Write-Host '====================================================='
    if (![Environment]::Is64BitOperatingSystem) { throw 'Os runtimes oficiais deste instalador exigem Windows de 64 bits.' }
    foreach ($dir in @($local,(Split-Path $log),'storage\framework\cache\data','storage\framework\sessions','storage\framework\views','bootstrap\cache')) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $lockHandle=[IO.File]::Open((Join-Path $local 'bootstrap.lock'),'OpenOrCreate','ReadWrite','None')
    $tmp=Join-Path ([IO.Path]::GetTempPath()) ('sentinel-bootstrap-'+[guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp | Out-Null; Protect-Directory $tmp
    if (Test-Path -LiteralPath (Join-Path $local 'state.json')) {
        try { $saved=Get-Content -LiteralPath (Join-Path $local 'state.json') -Raw | ConvertFrom-Json; foreach ($p in $saved.PSObject.Properties) { $state[$p.Name]=$p.Value } } catch { Log 'Estado ilegivel; verificacoes completas serao repetidas.' }
    }
    if (!(Test-Path composer.lock)) { throw 'composer.lock ausente. Restaure o arquivo versionado.' }
    if (!(Test-Path .env)) { Copy-Item -LiteralPath .env.example -Destination .env }
    foreach ($line in [IO.File]::ReadAllLines((Join-Path $root '.env'))) {
        if ($line -match '^\s*[^#=]*(?:KEY|PASSWORD|TOKEN|SECRET)[^=]*=(.+)$') { $secrets.Add($Matches[1].Trim().Trim('"',"'")) }
    }
    Ensure-Php; Ensure-Composer
    Step 'Dependencias PHP'; $composerHash=Fingerprint @('composer.json','composer.lock')
    $installed=(Test-Path vendor/autoload.php) -and (Test-Path vendor/composer/installed.json)
    if ($installed) {
        $locked=Get-Content composer.lock -Raw | ConvertFrom-Json; $actual=Get-Content vendor/composer/installed.json -Raw | ConvertFrom-Json
        foreach ($pkg in @($locked.packages)+@($locked.'packages-dev')) {
            $found=@($actual.packages | Where-Object { $_.name -eq $pkg.name -and $_.version -eq $pkg.version })
            if (!$found.Count -or !(Test-Path -LiteralPath (Join-Path 'vendor' $pkg.name))) { $installed=$false; break }
        }
    }
    if (!$installed -or ($state.composer -and $state.composer -ne $composerHash)) { $null=Run $php @($composer,'install','--no-interaction','--prefer-dist','--no-ansi') }
    $null=Run $php @($composer,'check-platform-reqs','--no-ansi'); $state.composer=$composerHash; Save-State; Ok 'Dependencias PHP'
    $script:helper=Join-Path $tmp 'bridge.php'; $self=[IO.File]::ReadAllText($env:SENTINEL_BOOTSTRAP_FILE)
    Write-Utf8 $helper (($self -split '(?m)^# PHP_PAYLOAD\r?$',2)[1]); Load-Env
    Step '.env e Azure Foundry'
    if ($cfg.APP_ENV -and $cfg.APP_ENV -ne 'local') { throw 'Inicializador exclusivo do ambiente local. APP_ENV existente foi preservado.' }
    $defaults=@{APP_NAME='Sentinel'; APP_ENV='local'; APP_DEBUG='true'; APP_URL='http://127.0.0.1:8000'; DB_HOST='127.0.0.1'; DB_PORT='3306'; DB_DATABASE='sentinel'; DB_USERNAME='root'; SESSION_DRIVER='database'; SESSION_LIFETIME='120'; SESSION_ENCRYPT='false'; SESSION_PATH='/'; SESSION_DOMAIN='null'; BROADCAST_CONNECTION='log'; FILESYSTEM_DISK='local'; QUEUE_CONNECTION='database'; CACHE_STORE='database'; AZURE_FOUNDRY_ENDPOINT='https://sentinel-foundry-tcc26.openai.azure.com'; AZURE_FOUNDRY_DEPLOYMENT='gpt-4.1-mini'}
    $updates=@{}; foreach ($key in $defaults.Keys) { if (!$cfg[$key]) { $updates[$key]=$defaults[$key] } }
    $updates.DB_CONNECTION='mysql'; if ($cfg.DB_URL) { $updates.DB_URL='' }; Set-Env $updates
    if (!$cfg.AZURE_FOUNDRY_API_KEY -or $cfg.AZURE_FOUNDRY_API_KEY -match '^(your_|sua_|<|changeme)') {
        $key=$env:SENTINEL_AZURE_FOUNDRY_API_KEY; if (!$key) { $key=Secret-Input 'Azure Foundry API key (entrada oculta, salva somente no .env)' }
        if (!$key) { throw 'Azure Foundry API key nao informada.' }; Set-Env @{AZURE_FOUNDRY_API_KEY=$key}
    }
    # Inherited application variables must not redirect migrations to another database.
    foreach ($key in $cfg.Keys) { [Environment]::SetEnvironmentVariable($key,$null,'Process') }
    if (!$cfg.APP_KEY) { $null=Run $php @('artisan','config:clear','--no-ansi'); $null=Run $php @('artisan','key:generate','--force','--no-ansi'); Load-Env }
    if (!(Bridge 'key-check').ok) { throw 'APP_KEY existente invalida. Restaure a chave original; ela nao foi substituida.' }; Ok '.env e APP_KEY'
    Ensure-Node; Ensure-MySql
    # Choose APP_URL before hashing/building assets, so a port change is handled once.
    $port=8000
    if ($cfg.APP_URL -match '^http://127\.0\.0\.1:(8\d{3})/?$') { $port=[int]$Matches[1] }
    while (Port-Open '127.0.0.1' $port) { if (Own-Server $port) { break }; $port++; if ($port -gt 8999) { throw 'Nenhuma porta HTTP local disponivel.' } }
    $url="http://127.0.0.1:$port"
    if ($cfg.APP_URL -ne $url) { Set-Env @{APP_URL=$url} }
    Step 'Migrations e seed inicial'
    $null=Run $php @('artisan','config:clear','--no-ansi'); $null=Run $php @('artisan','migrate','--force','--no-interaction','--no-ansi')
    if ($state.seedPending -and $state.seedDatabase -eq "$($cfg.DB_HOST):$($cfg.DB_PORT)/$($cfg.DB_DATABASE)") {
        $seed=Bridge 'seed'; if (!$seed.ok) { throw "Seed inicial falhou e foi revertido em transacao: $($seed.message). Verifique database/seeders." }
        $state.seedPending=$false; Save-State; Ok 'Seed inicial (transacao unica)'
    }
    $null=Run $php @('artisan','migrate:status','--no-ansi')
    if (!(Bridge 'laravel-db').ok) { throw 'Laravel nao conseguiu consultar MySQL/tabelas de infraestrutura.' }; Ok 'Migrations e consulta real pelo Laravel'
    Step 'Front-end'
    if (Test-Path package.json) {
        $npmHash=Fingerprint @('package.json','package-lock.json'); $deps=Run $npm @('ls','--depth=0','--json') -AllowFailure
        if (!(Test-Path node_modules) -or $deps.Code -ne 0 -or ($state.npm -and $state.npm -ne $npmHash)) {
            if (Test-Path package-lock.json) { $null=Run $npm @('ci','--no-audit','--no-fund') } else { $null=Run $npm @('install','--no-audit','--no-fund') }
        }
        $state.npm=Fingerprint @('package.json','package-lock.json'); Save-State
        $assets=Fingerprint @('resources','vite.config.js','package.json','package-lock.json','.env')
        $manifest=Join-Path $root 'public\build\manifest.json'; $buildOk=$false
        if (Test-Path -LiteralPath $manifest) {
            try { $entries=Get-Content -LiteralPath $manifest -Raw | ConvertFrom-Json; $buildOk=$true
                foreach ($p in $entries.PSObject.Properties) { if (!(Test-Path -LiteralPath (Join-Path 'public\build' $p.Value.file))) { $buildOk=$false } }
            } catch { $buildOk=$false }
        }
        if (!$buildOk -or $state.assets -ne $assets) { $null=Run $npm @('run','build') }
        if (!(Test-Path -LiteralPath $manifest)) { throw 'Build terminou sem public/build/manifest.json.' }
        if (Test-Path public/hot) { Remove-Item -LiteralPath (Join-Path $root 'public\hot') }
        $state.assets=$assets; Save-State; Ok 'Dependencias front-end e build'
    }
    Step 'Validacao'
    $null=Run $php @('artisan','optimize:clear','--no-ansi'); $null=Run $php @('artisan','--version'); $null=Run $php @('artisan','about','--no-ansi')
    $testHash=Fingerprint @('app','bootstrap/app.php','bootstrap/providers.php','config','database/migrations','database/seeders','database/factories','routes','resources','tests','phpunit.xml','composer.lock','instalar.bat')
    if ($state.tests -ne $testHash -or $env:SENTINEL_FORCE_TESTS -eq '1') {
        $tests=Run $php @('artisan','test','--no-ansi'); Write-Host (Redact (($tests.Text -split '\r?\n' | Select-String 'Tests:|Duration:|"assertions"') -join "`n"))
        $state.tests=$testHash; Save-State
    } else { Ok 'Testes ja aprovados para estes arquivos' }
    Step 'Iniciando SENTINEL'
    if (!(Own-Server $port)) {
        $launch=@{FilePath=$php; WorkingDirectory=$root; ArgumentList="`"$root\artisan`" serve --host=127.0.0.1 --port=$port --tries=1"; WindowStyle='Normal'; PassThru=$true}
        if ($env:SENTINEL_NONINTERACTIVE) {
            $launch.WindowStyle='Hidden'
            $launch.RedirectStandardOutput=Join-Path $local 'server-output.log'
            $launch.RedirectStandardError=Join-Path $local 'server-error.log'
        }
        $serverProcess=Start-Process @launch
        Log ('Laravel PID '+$serverProcess.Id)
    }
    $deadline=(Get-Date).AddSeconds(30)
    while (!(Http-Ok "$url/up")) { if ((Get-Date) -ge $deadline) { throw 'HTTP /up nao retornou 200 em 30 segundos. Confira a janela Laravel e storage/logs/laravel.log.' }; Start-Sleep -Milliseconds 500 }
    foreach ($path in @('/','/login','/agente')) { if (!(Http-Ok ($url+$path))) { throw "HTTP $path falhou. Consulte o log Laravel." }; Ok "GET $path" }
    Ok 'Health check /up: HTTP 200'; Write-Host "`nServidor: $url"
    if ($env:SENTINEL_NO_BROWSER -ne '1') { Write-Host 'Abrindo navegador...'; Start-Process $url }; Ok 'SENTINEL pronto'; $exitCode=0
} catch {
    $message=Redact $_.Exception.Message
    Write-Host "`nERRO na etapa: $stage`nCausa: $message`nSugestao: consulte $log e execute instalar.bat novamente." -ForegroundColor Red
    if (Test-Path -LiteralPath (Split-Path $log)) { Log ($message+' (linha '+$_.InvocationInfo.ScriptLineNumber+')') }
} finally {
    if ($lockHandle) { $lockHandle.Dispose() }
    if ($tmp) {
        $resolved=[IO.Path]::GetFullPath($tmp); $tempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')+'\'
        if ($resolved.StartsWith($tempRoot,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolved) -match '^sentinel-bootstrap-[a-f0-9]{32}$') { Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue }
    }
}
exit $exitCode
<#
# PHP_PAYLOAD
<?php
// Only explicit safe results are emitted. No raw SQL/connection exception output.
$action=$argv[1]; $root=$argv[2]; chdir($root);
require $root.'/vendor/autoload.php';
$input=json_decode(preg_replace('/^\xEF\xBB\xBF/','',stream_get_contents(STDIN)),true,512,JSON_THROW_ON_ERROR);
function result(array $value): never { echo json_encode($value,JSON_THROW_ON_ERROR); exit(0); }
function appBoot(): void { global $root; $app=require $root.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); }
try {
    if ($action==='env-read') { result(Dotenv\Dotenv::parse(file_get_contents('.env'))); }
    if ($action==='env-set') {
        $text=file_get_contents('.env'); $existing=Dotenv\Dotenv::parse($text);
        foreach ($input as $key=>$value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/',$key)) { throw new RuntimeException('Invalid key'); }
            $value=(string)$value;
            if (array_key_exists($key,$existing)&&$existing[$key]===$value) { continue; }
            if (!str_contains($value,"'")) { $encoded="'".$value."'"; }
            else { $encoded='"'.str_replace(['\\','"','$',"\n","\r"],['\\\\','\\"','\\$','\\n','\\r'],$value).'"'; }
            $line=$key.'='.$encoded; $pattern='/^\h*'.preg_quote($key,'/').'\h*=[^\r\n]*/m';
            if (preg_match($pattern,$text)) { $text=preg_replace_callback($pattern,fn()=>$line,$text); }
            else { $text=rtrim($text)."\r\n".$line."\r\n"; }
        }
        if (preg_match('/^host\s*=.*(?:supabase|postgres)/mi',$text)) { $text=preg_replace('/^(host|port|database|user)\s*=.*$/m','# legacy PostgreSQL: $0',$text); }
        Dotenv\Dotenv::parse($text);
        if ($text!==file_get_contents('.env')) {
            // Same-volume atomic replacement: an interrupted write cannot truncate .env.
            $temporary=tempnam(getcwd(),'.env.sentinel-');
            try {
                if (file_put_contents($temporary,$text,LOCK_EX)!==strlen($text)) { throw new RuntimeException('env write'); }
                $replaced=false;
                for ($attempt=0;$attempt<5;$attempt++) { if (@rename($temporary,'.env')) { $replaced=true; break; }; usleep(300000); }
                if (!$replaced) { throw new RuntimeException('env replace'); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
        }; result(['ok'=>true]);
    }
    if ($action==='key-check') {
        $env=Dotenv\Dotenv::parse(file_get_contents('.env')); $key=$env['APP_KEY']??'';
        $key=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):$key;
        result(['ok'=>is_string($key)&&Illuminate\Encryption\Encrypter::supported($key,$env['APP_CIPHER']??'AES-256-CBC')]);
    }
    if ($action==='laravel-db') {
        appBoot(); $db=Illuminate\Support\Facades\DB::connection(); if ($db->getDriverName()!=='mysql') { throw new RuntimeException('Not MySQL'); }
        $db->select('SELECT 1'); foreach (['sessions','cache','cache_locks','jobs','job_batches','failed_jobs'] as $table) { $db->table($table)->limit(1)->get(); }; result(['ok'=>true]);
    }
    if ($action==='seed') {
        appBoot(); Illuminate\Support\Facades\DB::transaction(function() {
            // Covers a crash after COMMIT but before the local success marker was saved.
            // Never repeat non-idempotent seeders over existing application records.
            foreach (['users','lancamentos','categorias_lancamento','clientes','fornecedores','funcionarios','notas_fiscais'] as $table) {
                if (Illuminate\Support\Facades\DB::table($table)->exists()) { return; }
            }
            $output=new Symfony\Component\Console\Output\BufferedOutput();
            $code=Illuminate\Support\Facades\Artisan::call('db:seed',['--force'=>true,'--no-interaction'=>true],$output);
            if ($code!==0) { throw new RuntimeException('Seeder failed'); }
        }); result(['ok'=>true]);
    }
    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/',$input['host'])||!ctype_digit((string)$input['port'])) { throw new RuntimeException('Invalid host/port'); }
    $pdo=new PDO('mysql:host='.$input['host'].';port='.$input['port'].';charset=utf8mb4',$input['user'],$input['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
    if ($action==='probe') { result(['ok'=>true,'version'=>$pdo->query('SELECT VERSION()')->fetchColumn()]); }
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$input['database'])) { throw new RuntimeException('Invalid database name'); }; $name=$input['database'];
    if ($action==='database'||$action==='provision') {
        $exists=$pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?'); $exists->execute([$name]);
        if (!$exists->fetchColumn()) { $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
        $pdo->exec("USE `$name`");
        if ($action==='provision') {
            $password=$pdo->quote($input['appPassword']);
            $pdo->exec("CREATE USER IF NOT EXISTS 'sentinel_app'@'localhost' IDENTIFIED BY $password");
            $pdo->exec("GRANT ALL PRIVILEGES ON `$name`.* TO 'sentinel_app'@'localhost'");
        }; result(['ok'=>true,'empty'=>count($pdo->query('SHOW TABLES')->fetchAll())===0]);
    }; throw new RuntimeException('Unknown action');
} catch (Throwable $e) { result(['ok'=>false,'code'=>($e instanceof PDOException?($e->errorInfo[1]??(int)$e->getCode()):0),'type'=>(new ReflectionClass($e))->getShortName(),'message'=>$action==='seed'?$e->getMessage():null]); }
__halt_compiler();
#>
