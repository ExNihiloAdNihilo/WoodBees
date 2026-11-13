#!/usr/bin/env bash
# Helper for switching the docker-compose engine file (COMPOSE_FILE in .env) safely.
#
# Two things need care when you change .env and bring the stack back up:
#   - switching between the MariaDB and MySQL engine files re-uses the same db_data
#     volume, and the two engines don't share an on-disk format, so the
#     volume needs wiping first;
#   - switching between a bundled engine file and the external one leaves an
#     old database container running unnoticed unless you pass
#     --remove-orphans, since Compose doesn't remove a container just
#     because it dropped out of the active file set.
#
# This script always does the --remove-orphans stop, optionally wipes
# db_data, then brings the stack back up with whatever .env currently says.
# Edit .env yourself first - this script does not touch it.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
    echo "No .env found. Copy .env.example to .env and choose an engine file (COMPOSE_FILE) first." >&2
    exit 1
fi

echo "Current .env:"
grep -E '^(COMPOSE_FILE|COMPOSE_PATH_SEPARATOR)=' .env || true
echo

read -r -p "Stop the stack now (docker compose down --remove-orphans)? [Y/n] " ans
ans=${ans:-Y}
if [[ ! "$ans" =~ ^[Yy] ]]; then
    echo "Aborted - nothing changed."
    exit 0
fi

# This project's Compose name (usually the checkout folder's name, lowercased).
# Needed to pick out *this* install's db_data volume below - a plain
# `--filter name=db_data` is a substring match and would also catch other
# Docker projects' volumes (e.g. "someapp_db_data").
project=$(docker compose config 2>/dev/null | sed -n 's/^name: //p' | head -n 1)
if [ -z "$project" ]; then
    echo "Could not determine this project's Compose name (is .env valid? try 'docker compose config')." >&2
    exit 1
fi

docker compose down --remove-orphans

read -r -p "Switching DB engine (MariaDB <-> MySQL)? This permanently deletes this install's database (db_data volume) - wipe it now? [y/N] " wipe
if [[ "$wipe" =~ ^[Yy] ]]; then
    vols=$(docker volume ls -q \
        --filter "label=com.docker.compose.project=$project" \
        --filter "label=com.docker.compose.volume=db_data")
    if [ -n "$vols" ]; then
        docker volume rm $vols
        echo "Removed: $vols"
    else
        echo "No db_data volume found - nothing to remove."
    fi
fi

echo
echo "Make sure .env now has the COMPOSE_FILE you want."
read -r -p "Press Enter to run 'docker compose up -d' (or Ctrl+C to edit .env first): "

docker compose up -d
docker compose ps
