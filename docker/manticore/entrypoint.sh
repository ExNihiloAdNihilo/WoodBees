#!/bin/sh
#
# WoodBees / Beehive Forum - Manticore Search container entrypoint.
#
# Substitutes the @@TOKENS@@ in manticore.conf.template from environment
# variables, waits for MySQL, builds the initial index, starts searchd and
# keeps the delta (and, less often, the main) index refreshed.

set -eu

WEBTAG="${MANTICORE_WEBTAG:-WOODBEES}"
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

echo "manticore: waiting for the forum's search tables ..."
i=0
while [ "$i" -lt 60 ]; do
    if indexer --config "$CONF" --all --quiet >/dev/null 2>&1; then
        echo "manticore: initial index built."
        break
    fi
    i=$((i + 1))
    sleep 5
done

# Background reindex loop.
(
    elapsed=0
    while true; do
        sleep "$DELTA_INTERVAL"
        indexer --config "$CONF" --rotate --quiet "${WEBTAG}_DELTA" >/dev/null 2>&1 || true
        elapsed=$((elapsed + DELTA_INTERVAL))
        if [ "$elapsed" -ge "$MAIN_INTERVAL" ]; then
            indexer --config "$CONF" --rotate --quiet --all >/dev/null 2>&1 || true
            elapsed=0
        fi
    done
) &

echo "manticore: starting searchd."
exec searchd --config "$CONF" --nodetach
