#!/usr/bin/env bash
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
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
    echo "No .env found. Copy .env.example to .env and set DB_MODE (and DB_IMAGE/COMPOSE_PROFILES if needed) first." >&2
    exit 1
fi

echo "Current .env:"
grep -E '^(DB_MODE|DB_IMAGE|COMPOSE_PROFILES)=' .env || true
echo

read -r -p "Stop the stack now (docker compose down --remove-orphans)? [Y/n] " ans
ans=${ans:-Y}
if [[ ! "$ans" =~ ^[Yy] ]]; then
    echo "Aborted - nothing changed."
    exit 0
fi

docker compose down --remove-orphans

read -r -p "Switching DB engine (MariaDB <-> MySQL)? This needs a fresh db_data volume - wipe it now? [y/N] " wipe
if [[ "$wipe" =~ ^[Yy] ]]; then
    vols=$(docker volume ls -q --filter name=db_data)
    if [ -n "$vols" ]; then
        docker volume rm $vols
        echo "Removed: $vols"
    else
        echo "No db_data volume found - nothing to remove."
    fi
fi

echo
echo "Make sure .env now has the DB_MODE/DB_IMAGE/COMPOSE_PROFILES you want."
read -r -p "Press Enter to run 'docker compose up -d' (or Ctrl+C to edit .env first): "

docker compose up -d
docker compose ps
