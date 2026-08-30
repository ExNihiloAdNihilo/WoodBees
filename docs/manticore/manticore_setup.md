# WoodBees / Beehive Forum — Manticore Search

[Manticore Search](https://manticoresearch.com/) is the actively-maintained
open-source successor to Sphinx. WoodBees talks to it over the same SphinxQL
(MySQL) protocol the old Sphinx integration used, so `forum/include/sphinx.inc.php`
is unchanged apart from a webtag fallback.

Using a search server instead of MySQL's built-in `FULLTEXT` gives:

- better relevance ranking and lower database load, and
- **proper tokenisation of scripts without spaces** (Chinese, Japanese, Thai) —
  the bundled config indexes each CJK character as its own token
  (`ngram_len = 1`, `ngram_chars = cjk`).

The forum still works without it: if the search server is disabled, unreachable
or misconfigured, search falls back to MySQL `FULLTEXT` automatically.

## Docker (bundled)

`docker-compose.yml` already includes a `manticore` service. It:

1. renders `docker/manticore/manticore.conf.template` from the `MANTICORE_*`
   environment variables (set in `docker-compose.yml`),
2. waits for the database, builds the initial index,
3. runs `searchd` on port **9306**, and
4. refreshes the delta index every 15 minutes and the main index once a day.

To turn it on, sign in as an admin and go to **Admin → Global Forum Settings →
Manticore / Sphinx Search Integration**:

| Setting | Value |
|---|---|
| Enable integration | Yes |
| Search server hostname | `manticore` |
| Search server port | `9306` |

Save. The page reports whether it could connect.

### If your forum webtag is not `WOODBEES`

The index is named after the forum webtag (stored upper-case). Set
`MANTICORE_WEBTAG` in `docker-compose.yml` to match, then recreate the
container:

```bash
docker compose up -d --force-recreate manticore
```

### Rebuilding the index by hand

```bash
docker compose exec manticore indexer --config /var/lib/manticore/manticore.conf --rotate --all
```

### Caveat

The substitution uses `sed` with `|` as the delimiter, so the database password
passed to Manticore must not contain a `|` character.

## Manual install (no Docker)

1. Install Manticore from <https://manticoresearch.com/install/>.
2. Copy `docker/manticore/manticore.conf.template` to your Manticore config
   path and replace every `@@TOKEN@@`:
   `@@WEBTAG@@` (upper-case forum webtag), `@@PREFIX@@` (`<database>`.`<WEBTAG>_`),
   `@@DB_HOST@@`, `@@DB_PORT@@`, `@@DB_USER@@`, `@@DB_PASS@@`, `@@DB_NAME@@`.
3. Build the indexes: `indexer --all`.
4. Start `searchd`.
5. Add cron entries — delta every ~15 min, main daily:
   ```
   */15 * * * *  indexer --rotate WEBTAG_DELTA
   17 4 * * *    indexer --rotate --all
   ```
6. Enable the integration in Admin → Global Forum Settings as above, pointing at
   your Manticore host and its SphinxQL port (9306 by default).

The legacy Sphinx notes are kept in `docs/sphinx/` for reference but Sphinx
itself is no longer maintained as open source — use Manticore.
