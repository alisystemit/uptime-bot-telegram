<#
  One-command test environment (Windows + PowerShell 5.1+)

    powershell -NoProfile -ExecutionPolicy Bypass -File tests\env.ps1
    powershell -NoProfile -ExecutionPolicy Bypass -File tests\env.ps1 -Run

  What it does:
    1) starts the isolated MySQL on port 3399 (if not already up)
    2) (re)starts the project web server on 8199 and the mock gateway on 8321,
       BOTH with UPTIME_CONFIG pointing at the test config
    3) recreates the test database and installs the schema
    4) with -Run, runs every test file and reports failures

  NOTE: comments are ASCII on purpose - PowerShell 5.1 reads .ps1 as ANSI when
  there is no BOM, so non-ASCII text breaks the parser.
#>
param([switch]$Run)

$ErrorActionPreference = 'Stop'
$root    = Split-Path -Parent $PSScriptRoot
$php     = 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe'
$mysqld  = 'C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqld.exe'
$mysql   = 'C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe'
$dataDir = Join-Path $env:TEMP 'upt\data'
$cfg     = Join-Path $env:TEMP 'upt\config.test.php'

if (-not (Test-Path $php)) { throw "PHP not found: $php" }
if (-not (Test-Path $cfg)) { throw "test config not found: $cfg" }

# Child processes must inherit these BEFORE Start-Process is called.
$env:UPTIME_CONFIG = $cfg
$env:UPTIME_DEBUG  = '1'

function Wait-Port([int]$port, [int]$sec = 30) {
    for ($i = 0; $i -lt $sec; $i++) {
        if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) { return $true }
        Start-Sleep -Seconds 1
    }
    return $false
}

# --- 1) MySQL ----------------------------------------------------------------
if (-not (Get-NetTCPConnection -LocalPort 3399 -State Listen -ErrorAction SilentlyContinue)) {
    Write-Host 'starting mysqld on 3399 ...'
    Start-Process -FilePath $mysqld -ArgumentList '--port=3399', "--datadir=$dataDir", '--console' -WindowStyle Hidden
    if (-not (Wait-Port 3399)) { throw 'mysqld did not start' }
}

# --- 2) HTTP servers ---------------------------------------------------------
# Kill first: a server started WITHOUT UPTIME_CONFIG would boot against the
# real config.php and answer 503 for every request.
foreach ($p in @(8199, 8321)) {
    Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue |
        ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
}
Start-Sleep -Seconds 1
Start-Process -FilePath $php -ArgumentList '-S', '127.0.0.1:8199', '-t', $root -WindowStyle Hidden
Start-Process -FilePath $php -ArgumentList '-S', '127.0.0.1:8321', (Join-Path $PSScriptRoot 'mock_gw.php') -WindowStyle Hidden
if (-not (Wait-Port 8199)) { throw 'web server did not start' }
if (-not (Wait-Port 8321)) { throw 'mock gateway did not start' }
Write-Host 'servers up: 3399 (db), 8199 (web), 8321 (mock gateway)'

# --- 3) fresh database -------------------------------------------------------
Remove-Item (Join-Path $PSScriptRoot '.mock_state.json') -ErrorAction SilentlyContinue
& $mysql -h 127.0.0.1 -P 3399 -u root -e 'DROP DATABASE IF EXISTS uptimetest; CREATE DATABASE uptimetest CHARACTER SET utf8mb4;'
& $php (Join-Path $root 'table.php') --json | Out-Null
Write-Host 'schema installed'

# --- 4) tests ----------------------------------------------------------------
if ($Run) {
    $fail = 0
    foreach ($t in @('pay_test.php', 'integration.php', 'ranking_test.php', 'test_fixes.php')) {
        $f = Join-Path $PSScriptRoot $t
        if (-not (Test-Path $f)) { continue }
        Write-Host ''
        Write-Host "===== $t ====="
        $out = & $php $f 2>&1
        $lines = $out | ForEach-Object { [string]$_ }
        $lines | Where-Object { $_ -match 'reslt|Fatal error|Parse error|Uncaught' -or $_ -match [char]0x2717 } |
            ForEach-Object { $_ }
        if ($LASTEXITCODE -ne 0) { $fail++ }
    }
    Write-Host ''
    Write-Host "==== failing test files: $fail ===="
    exit $fail
}
