#!/bin/sh
#
# WoodBees / Beehive Forum - Manticore Search container entrypoint.
#
# Substitutes the @@TOKENS@@ in manticore.conf.template from environment
# variables, waits for MySQL, builds the initial index, starts searchd and
# keeps the delta (and, less often, the main) index refreshed.

set -eu

WEBTAG="${MANTICORE_WEBTAG:-WOODBEES}"
DB_MODE="${DB_MODE:-}"
DB_HOST="${MANTICORE_DB_HOST:-mysql}"
DB_PORT="${MANTICORE_DB_PORT:-3306}"
DB_USER="${MANTICORE_DB_USER:-beehiveforum}"
DB_PASS="${MANTICORE_DB_PASS:-password}"
DB_NAME="${MANTICORE_DB_NAME:-beehiveforum}"
PREFIX="${MANTICORE_TABLE_PREFIX:-${WEBTAG}_}"
DELTA_INTERVAL="${MANTICORE_DELTA_INTERVAL:-900}"
MAIN_INTERVAL="${MANTICORE_MAIN_INTERVAL:-86400}"

TEMPLATE=/woodbees/manticore.conf.template
CONF=/var/lib/manticore/manticore.conf

# Loud, hard-to-miss warning for `docker compose logs manticore` - plain
# `echo` lines scroll past unnoticed next to routine indexer/searchd chatter.
warn() {
    echo "" >&2
    echo "############################################################" >&2
    echo "# MANTICORE WARNING" >&2
    for line in "$@"; do
        echo "# $line" >&2
    done
    echo "############################################################" >&2
    echo "" >&2
}

mkdir -p /var/lib/manticore/data /var/lib/manticore/binlog

# Pipe-delimited sed; passwords containing '|' are not supported (document this).
sed \
    -e "s|@@WEBTAG@@|${WEBTAG}|g" \
    -e "s|@@PREFIX@@|${PREFIX}|g" \
    -e "s|@@DB_HOST@@|${DB_HOST}|g" \
    -e "s|@@DB_PORT@@|${DB_PORT}|g" \
    -e "s|@@DB_USER@@|${DB_USER}|g" \
    -e "s|@@DB_PASS@@|${DB_PASS}|g" \
    -e "s|@@DB_NAME@@|${DB_NAME}|g" \
    "$TEMPLATE" > "$CONF"

if [ "$DB_MODE" = "external" ] && [ "$DB_HOST" = "mysql" ]; then
    warn "DB_MODE=external, but MANTICORE_DB_HOST is still the bundled-database" \
         "default ('mysql') - that host does not exist in external mode, so" \
         "Manticore can never reach your database and search will never index." \
         "Fix: in your .env file, set MANTICORE_DB_HOST (and _PORT/_USER/_PASS/_NAME" \
         "if needed) to your external database's connection details, then run" \
         "'docker compose up -d --force-recreate manticore'."
fi

echo "manticore: waiting for the forum's search tables ..."
i=0
index_built=0
while [ "$i" -lt 60 ]; do
    if indexer --config "$CONF" --all --quiet >/dev/null 2>&1; then
        echo "manticore: initial index built."
        index_built=1
        break
    fi
    i=$((i + 1))
    sleep 5
done

if [ "$index_built" -eq 0 ]; then
    warn "Could not build the initial search index after 5 minutes - Manticore" \
         "could not reach the database at ${DB_HOST}:${DB_PORT} as ${DB_USER}." \
         "Search will stay empty/unavailable until this is fixed. Double-check" \
         "MANTICORE_DB_HOST/_PORT/_USER/_PASS/_NAME in your .env file (common" \
         "cause: external database details that are wrong or still pointing" \
         "at the bundled mysql host), then restart this container. Retrying in" \
         "the background in the meantime."
fi

# Background reindex loop. Also retries the full build on the same cadence
# as the delta reindex until it has succeeded once, so fixing the DB
# connection (e.g. correcting MANTICORE_DB_HOST and recreating the container)
# is enough to recover without a human re-triggering indexing by hand.
(
    elapsed=0
    while true; do
        sleep "$DELTA_INTERVAL"
        if [ "$index_built" -eq 0 ]; then
            if indexer --config "$CONF" --all --quiet >/dev/null 2>&1; then
                echo "manticore: initial index built (recovered)."
                index_built=1
            fi
        else
            indexer --config "$CONF" --rotate --quiet "${WEBTAG}_DELTA" >/dev/null 2>&1 || true
        fi
        elapsed=$((elapsed + DELTA_INTERVAL))
        if [ "$elapsed" -ge "$MAIN_INTERVAL" ]; then
            indexer --config "$CONF" --rotate --quiet --all >/dev/null 2>&1 || true
            elapsed=0
        fi
    done
) &

echo "manticore: starting searchd."
exec searchd --config "$CONF" --nodetach
