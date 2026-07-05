param(
    [string]$ProjectName = $(if ($env:CONTROLPANEL_PROJECT_NAME) { $env:CONTROLPANEL_PROJECT_NAME } else { "controlpanel" }),
    [string]$EnvFile = $(Join-Path (Split-Path -Parent $PSScriptRoot) ".env"),
    [switch]$Guided,
    [switch]$DestroyData,
    [switch]$ConfirmDestroy,
    [switch]$RemoveEnv,
    [switch]$RemoveAgent,
    [switch]$Yes
)

$ErrorActionPreference = "Stop"
$RootDir = Split-Path -Parent $PSScriptRoot
$ComposeFile = Join-Path $RootDir "infra/docker-compose.yml"
$AgentBin = if ($env:CONTROLPANEL_AGENT_BIN) { $env:CONTROLPANEL_AGENT_BIN } else { Join-Path $RootDir "dist/controlpanel-agent.exe" }

function Write-Log {
    param([string]$Message)
    Write-Host "[controlpanel:uninstall] $Message"
}

function Fail {
    param([string]$Message)
    throw "[controlpanel:uninstall] $Message"
}

function Invoke-Compose {
    param([string[]]$ComposeArgs)
    $baseArgs = @("compose", "-p", $ProjectName, "-f", $ComposeFile, "--env-file", $EnvFile)
    & docker @baseArgs @ComposeArgs
    if ($LASTEXITCODE -ne 0) {
        Fail "Docker Compose failed with exit code $LASTEXITCODE"
    }
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
    Write-Host "ControlPanel OS guided uninstaller"
    Write-Host "----------------------------------"
    $script:ProjectName = Read-Default "Docker project name" $ProjectName
    $script:EnvFile = Read-Default "Environment file path" $EnvFile
    $script:DestroyData = Read-YesNo "Delete Docker volumes and hosted data?" $DestroyData
    $script:RemoveEnv = Read-YesNo "Remove env file?" $RemoveEnv
    $script:RemoveAgent = Read-YesNo "Remove node agent?" $RemoveAgent
    if ($DestroyData) {
        $script:ConfirmDestroy = $true
    }

    Write-Host ""
    Write-Host "Summary"
    Write-Host "  Project: $ProjectName"
    Write-Host "  Env file: $EnvFile"
    Write-Host "  Delete Docker volumes: $DestroyData"
    Write-Host "  Remove env file: $RemoveEnv"
    Write-Host "  Remove agent: $RemoveAgent"
    Write-Host ""
    if (-not (Read-YesNo "Continue with uninstall?" $false)) {
        Fail "Uninstall cancelled."
    }
    $script:Yes = $true
}

if (-not (Test-Path -LiteralPath $ComposeFile)) {
    Fail "Compose file not found at $ComposeFile"
}

if ($env:CONTROLPANEL_REMOVE_DATA -eq "true") {
    $DestroyData = $true
}
if ($env:CONTROLPANEL_REMOVE_ENV -eq "true") {
    $RemoveEnv = $true
}
if ($env:CONTROLPANEL_REMOVE_AGENT -eq "true") {
    $RemoveAgent = $true
}
if ($env:CONTROLPANEL_CONFIRM_DESTROY -eq "DESTROY") {
    $ConfirmDestroy = $true
}

Invoke-GuidedWizard

if ($DestroyData -and -not $ConfirmDestroy) {
    Fail "Data removal requested. Re-run with -ConfirmDestroy to delete volumes."
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Fail "Missing required command: docker"
}

if (-not $Yes -and $env:CONTROLPANEL_YES -ne "true") {
    $prompt = if ($DestroyData) {
        "Stop ControlPanel OS project '$ProjectName' and DELETE Docker volumes? [y/N]"
    }
    else {
        "Stop ControlPanel OS project '$ProjectName' and keep Docker volumes? [y/N]"
    }
    $answer = Read-Host $prompt
    if ($answer -ne "y" -and $answer -ne "Y") {
        Fail "Uninstall cancelled."
    }
}

if ($DestroyData) {
    Write-Log "Stopping services and deleting Docker volumes"
    Invoke-Compose -ComposeArgs @("down", "--remove-orphans", "--volumes")
}
else {
    Write-Log "Stopping services and keeping Docker volumes"
    Invoke-Compose -ComposeArgs @("down", "--remove-orphans")
}

if ($RemoveAgent) {
    Remove-Item -LiteralPath $AgentBin -Force -ErrorAction SilentlyContinue
    Write-Log "Removed agent binary if present: $AgentBin"
}

if ($RemoveEnv) {
    Remove-Item -LiteralPath $EnvFile -Force -ErrorAction SilentlyContinue
    Write-Log "Removed env file: $EnvFile"
}

Write-Log "Uninstall complete"
