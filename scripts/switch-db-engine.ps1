# Helper for switching docker-compose.yml's DB_MODE/DB_IMAGE safely.
#
# Two things need care when you change .env and bring the stack back up:
#   - switching DB_IMAGE between MariaDB and MySQL re-uses the same db_data
#     volume, and the two engines don't share an on-disk format, so the
#     volume needs wiping first;
#   - switching DB_MODE between a bundled engine and "external" leaves an
#     old database container running unnoticed unless you pass
#     --remove-orphans, since Compose doesn't remove a container just
#     because it dropped out of the active profile.
#
# This script always does the --remove-orphans stop, optionally wipes
# db_data, then brings the stack back up with whatever .env currently says.
# Edit .env yourself first - this script does not touch it.

$ErrorActionPreference = "Stop"

Set-Location (Join-Path $PSScriptRoot "..")

if (-not (Test-Path .env)) {
    Write-Error "No .env found. Copy .env.example to .env and set DB_MODE (and DB_IMAGE/COMPOSE_PROFILES if needed) first."
    exit 1
}

Write-Host "Current .env:"
Get-Content .env | Select-String '^(DB_MODE|DB_IMAGE|COMPOSE_PROFILES)='
Write-Host ""

$ans = Read-Host "Stop the stack now (docker compose down --remove-orphans)? [Y/n]"
if ([string]::IsNullOrWhiteSpace($ans)) { $ans = "Y" }
if ($ans -notmatch '^[Yy]') {
    Write-Host "Aborted - nothing changed."
    exit 0
}

# This project's Compose name (usually the checkout folder's name, lowercased).
# Needed to pick out *this* install's db_data volume below - a plain
# `--filter name=db_data` is a substring match and would also catch other
# Docker projects' volumes (e.g. "someapp_db_data").
$project = $null
try { $project = (docker compose config --format json | ConvertFrom-Json).name } catch { }
if (-not $project) {
    Write-Error "Could not determine this project's Compose name (is .env valid? try 'docker compose config')."
    exit 1
}

docker compose down --remove-orphans

$wipe = Read-Host "Switching DB engine (MariaDB <-> MySQL)? This permanently deletes this install's database (db_data volume) - wipe it now? [y/N]"
if ($wipe -match '^[Yy]') {
    $vols = docker volume ls -q --filter "label=com.docker.compose.project=$project" --filter "label=com.docker.compose.volume=db_data"
    if ($vols) {
        docker volume rm $vols
        Write-Host "Removed: $vols"
    } else {
        Write-Host "No db_data volume found - nothing to remove."
    }
}

Write-Host ""
Write-Host "Make sure .env now has the DB_MODE/DB_IMAGE/COMPOSE_PROFILES you want."
Read-Host "Press Enter to run 'docker compose up -d' (or Ctrl+C to edit .env first)" | Out-Null

docker compose up -d
docker compose ps
