param(
    [string]$ProjectName = $(if ($env:CONTROLPANEL_PROJECT_NAME) { $env:CONTROLPANEL_PROJECT_NAME } else { "controlpanel" }),
    [string]$EnvFile = $(Join-Path (Split-Path -Parent $PSScriptRoot) ".env"),
    [string]$AppUrl = $(if ($env:CONTROLPANEL_APP_URL) { $env:CONTROLPANEL_APP_URL } else { "http://localhost:8080" }),
    [string]$WebPort = $(if ($env:CONTROLPANEL_WEB_PORT) { $env:CONTROLPANEL_WEB_PORT } else { "3000" }),
    [string]$ApiPort = $(if ($env:CONTROLPANEL_API_PORT) { $env:CONTROLPANEL_API_PORT } else { "8080" }),
    [string]$PostgresPort = $(if ($env:CONTROLPANEL_POSTGRES_PORT) { $env:CONTROLPANEL_POSTGRES_PORT } else { "5432" }),
    [string]$RedisPort = $(if ($env:CONTROLPANEL_REDIS_PORT) { $env:CONTROLPANEL_REDIS_PORT } else { "6379" }),
    [string]$PowerDnsDnsPort = $(if ($env:CONTROLPANEL_POWERDNS_DNS_PORT) { $env:CONTROLPANEL_POWERDNS_DNS_PORT } else { "5300" }),
    [string]$PowerDnsApiPort = $(if ($env:CONTROLPANEL_POWERDNS_API_PORT) { $env:CONTROLPANEL_POWERDNS_API_PORT } else { "8082" }),
    [switch]$Guided,
    [switch]$OverwriteEnv,
    [switch]$WithAgent,
    [switch]$SkipStart,
    [switch]$Yes
)

$ErrorActionPreference = "Stop"
$RootDir = Split-Path -Parent $PSScriptRoot
$ComposeFile = Join-Path $RootDir "infra/docker-compose.yml"
$AgentBin = if ($env:CONTROLPANEL_AGENT_BIN) { $env:CONTROLPANEL_AGENT_BIN } else { Join-Path $RootDir "dist/controlpanel-agent.exe" }

function Write-Log {
    param([string]$Message)
    Write-Host "[controlpanel:install] $Message"
}

function Fail {
    param([string]$Message)
    throw "[controlpanel:install] $Message"
}

function Require-Command {
    param([string]$Name)
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        Fail "Missing required command: $Name"
    }
}

function Invoke-Compose {
    param([string[]]$ComposeArgs)
    $baseArgs = @("compose", "-p", $ProjectName, "-f", $ComposeFile, "--env-file", $EnvFile)
    & docker @baseArgs @ComposeArgs
    if ($LASTEXITCODE -ne 0) {
        Fail "Docker Compose failed with exit code $LASTEXITCODE"
    }
}

function New-Secret {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    return [Convert]::ToBase64String($bytes)
}

function New-AlphaNumSecret {
    param([int]$Length)
    $raw = [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes($Length * 2))
    return (($raw -replace '[^A-Za-z0-9]', '').Substring(0, $Length))
}

function Read-Default {
    param([string]$Label, [string]$Default)
    $answer = Read-Host "$Label [$Default]"
    if ([string]::IsNullOrWhiteSpace($answer)) {
        return $Default
    }
    return $answer
}

function Read-YesNo {
    param([string]$Label, [bool]$Default)
    $hint = if ($Default) { "[Y/n]" } else { "[y/N]" }
    $answer = Read-Host "$Label $hint"
    if ([string]::IsNullOrWhiteSpace($answer)) {
        return $Default
    }
    return $answer -eq "y" -or $answer -eq "Y"
}

function Invoke-GuidedWizard {
    if (-not $Guided -and $env:CONTROLPANEL_GUIDED -ne "true") {
        return
    }

    Write-Host ""
    Write-Host "ControlPanel OS guided installer"
    Write-Host "--------------------------------"
    $script:ProjectName = Read-Default "Docker project name" $ProjectName
    $script:EnvFile = Read-Default "Environment file path" $EnvFile
    if ((Test-Path -LiteralPath $EnvFile) -and (Read-YesNo "Env file exists. Regenerate it?" $false)) {
        $script:OverwriteEnv = $true
    }
    $script:AppUrl = Read-Default "Public API/control-plane URL" $AppUrl
    $script:WebPort = Read-Default "Dashboard port" $WebPort
    $script:ApiPort = Read-Default "API port" $ApiPort
    $script:PostgresPort = Read-Default "PostgreSQL host port" $PostgresPort
    $script:RedisPort = Read-Default "Redis host port" $RedisPort
    $script:PowerDnsDnsPort = Read-Default "PowerDNS DNS host port" $PowerDnsDnsPort
    $script:PowerDnsApiPort = Read-Default "PowerDNS API host port" $PowerDnsApiPort
    $script:WithAgent = Read-YesNo "Build node agent?" ($WithAgent -or $env:CONTROLPANEL_INSTALL_AGENT -eq "true")
    $startServices = Read-YesNo "Start Docker services after setup?" (-not $SkipStart)
    $script:SkipStart = -not $startServices

    Write-Host ""
    Write-Host "Summary"
    Write-Host "  Project: $ProjectName"
    Write-Host "  Env file: $EnvFile"
    Write-Host "  Dashboard: http://localhost:$WebPort"
    Write-Host "  API: http://localhost:$ApiPort/v1"
    Write-Host "  Agent: $WithAgent"
    Write-Host "  Start services: $startServices"
    Write-Host ""
    if (-not (Read-YesNo "Continue with installation?" $true)) {
        Fail "Installation cancelled."
    }
    $script:Yes = $true
}

function Write-EnvFile {
    if ((Test-Path -LiteralPath $EnvFile) -and -not $OverwriteEnv -and $env:CONTROLPANEL_OVERWRITE_ENV -ne "true") {
        Write-Log "Keeping existing env file: $EnvFile"
        return
    }

    $envDir = Split-Path -Parent $EnvFile
    if ($envDir -and -not (Test-Path -LiteralPath $envDir)) {
        New-Item -ItemType Directory -Force -Path $envDir | Out-Null
    }

    $appKey = "base64:$(New-Secret)"
    $dbPassword = New-AlphaNumSecret -Length 32
    $pdnsKey = New-AlphaNumSecret -Length 32
    $fossbillingSecret = New-AlphaNumSecret -Length 48

    @"
APP_ENV=production
APP_KEY=$appKey
APP_URL=$AppUrl

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=controlpanel
DB_USERNAME=controlpanel
DB_PASSWORD=$dbPassword

REDIS_HOST=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

POSTGRES_DB=controlpanel
POSTGRES_USER=controlpanel
POSTGRES_PASSWORD=$dbPassword

PDNS_AUTH_API_KEY=$pdnsKey
FOSSBILLING_WEBHOOK_SECRET=$fossbillingSecret

NEXT_PUBLIC_API_URL=http://localhost:$ApiPort/v1
NEXT_PUBLIC_WS_URL=ws://localhost:8081

WEB_PORT=$WebPort
API_PORT=$ApiPort
POSTGRES_PORT=$PostgresPort
REDIS_PORT=$RedisPort
POWERDNS_DNS_PORT=$PowerDnsDnsPort
POWERDNS_API_PORT=$PowerDnsApiPort
"@ | Set-Content -LiteralPath $EnvFile -Encoding UTF8NoBOM

    Write-Log "Created env file with generated secrets: $EnvFile"
}

function Install-Agent {
    if (-not $WithAgent -and $env:CONTROLPANEL_INSTALL_AGENT -ne "true") {
        return
    }

    Require-Command go
    $agentDir = Split-Path -Parent $AgentBin
    if ($agentDir -and -not (Test-Path -LiteralPath $agentDir)) {
        New-Item -ItemType Directory -Force -Path $agentDir | Out-Null
    }

    Write-Log "Building node agent"
    Push-Location (Join-Path $RootDir "apps/agent")
    try {
        go build -o $AgentBin ./cmd/agent
        if ($LASTEXITCODE -ne 0) {
            Fail "Agent build failed with exit code $LASTEXITCODE"
        }
    }
    finally {
        Pop-Location
    }
    Write-Log "Agent binary created at $AgentBin"
}

if (-not (Test-Path -LiteralPath $ComposeFile)) {
    Fail "Compose file not found at $ComposeFile"
}

Invoke-GuidedWizard

Require-Command docker
docker info *> $null
if ($LASTEXITCODE -ne 0) {
    Fail "Docker is not running or this user cannot access it."
}

if (-not $Yes -and $env:CONTROLPANEL_YES -ne "true") {
    $answer = Read-Host "Install ControlPanel OS project '$ProjectName' using '$ComposeFile'? [y/N]"
    if ($answer -ne "y" -and $answer -ne "Y") {
        Fail "Installation cancelled."
    }
}

Write-EnvFile

if ($SkipStart -or $env:CONTROLPANEL_SKIP_START -eq "true") {
    Write-Log "Skipping Docker service start"
}
else {
    Write-Log "Building and starting services"
    Invoke-Compose -ComposeArgs @("up", "-d", "--build")
}

Install-Agent

Write-Log "Installation complete"
Write-Log "Dashboard: http://localhost:$WebPort"
Write-Log "API: http://localhost:$ApiPort/v1"
