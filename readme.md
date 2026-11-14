# WoodBees Alpha 0.3.2

A fork of Beehive Forum. See [docs/release.md](docs/release.md) for what's new in this release.

**Upgrading from Alpha 0.3?** Read "Before you upgrade" in the Alpha 0.3.2 section of
[docs/release.md](docs/release.md): back up `forum/include/config.inc.php` (Upgrade rewrites it),
restart nginx, create `forum/include/install.enable` and run the installer's Upgrade method
(it is locked on an installed forum from this release on).

**Upgrading from Alpha 0.2?** Read "Before you upgrade" in
[docs/release.md](docs/release.md) first: back up
`forum/include/config.inc.php` before pulling, update `.env` for the
per-engine Compose files, then `docker compose up -d --build` and run the
installer's Upgrade method. Since Alpha 0.3.2 the installer is locked on an
installed forum: create `forum/include/install.enable` first (see the Alpha
0.3.2 "Before you upgrade" steps in [docs/release.md](docs/release.md)).

## Instance federation

WoodBees instances can share threads, comments and tags with each other as
signed bundle files: *Admin > Federation Export* on one instance, *Admin >
Federation Import* on the other. Each install has its own signing key (kept
on the `instance_keys` Docker volume - don't delete it), and an instance's
key is pinned the first time you import from it, after you check its
fingerprint with that instance's admin. *Admin > Instance Redaction* hides
(with Restore) or deletes everything that came from a given instance. Full
details: [docs/federation.md](docs/federation.md).

## Database engine

Before running `docker compose up`, copy [.env.example](.env.example) to
`.env` and uncomment one `COMPOSE_FILE` line. There is no default. The file
you pick is the database engine:

- `docker-compose.yml:docker-compose.mariadb.yml` - Docker provisions MariaDB 11.4.
- `docker-compose.yml:docker-compose.mysql.yml` - Docker provisions MySQL 8.4.
- `docker-compose.yml:docker-compose.external.yml` - connect WoodBees to a
  MariaDB/MySQL server you already run (no database container is started;
  also fill in the `MANTICORE_DB_*` lines in `.env`).

`docker-compose.yml` on its own holds only the services every install
shares (web server, PHP, Manticore search). Needs Docker Compose v2.

### Switching engines or modes later

The install wizard (New Install/Reinstall/Reconnect page) shows these same
two warnings with Copy buttons next to each command, so you can grab the
exact text without retyping it.

- **Switching between the MariaDB and MySQL engine files** re-uses the same `db_data`
  volume, and the two engines don't share an on-disk format. Stop the stack
  and remove its volumes before starting the other engine:
  ```
  docker compose down -v
  docker compose up -d
  ```
  `-v` permanently deletes this project's volumes only - the database plus
  the Manticore search index, which is rebuilt automatically (and would be
  stale after a database wipe anyway). Other Docker projects' volumes are
  not touched. Run the installer again afterwards.
- **Switching between a bundled engine file and the external one** changes
  which services exist (only the bundled files define `mysql`), but Compose
  doesn't remove a container just because it dropped out of the file set -
  the old database container can keep running, unnoticed, in the background.
  Run `docker compose down --remove-orphans` after switching to clean it up.

**Prefer one command?** [scripts/switch-db-engine.sh](scripts/switch-db-engine.sh)
(macOS/Linux/WSL/git-bash) and
[scripts/switch-db-engine.ps1](scripts/switch-db-engine.ps1) (Windows
PowerShell) run the sequence above for you - they show your current `.env`,
confirm before stopping the stack, ask separately whether to wipe `db_data`,
then bring everything back up. Edit `.env` yourself first; the scripts don't
touch it.

[About](https://github.com/BeehiveForum/BeehiveForum/blob/master/docs/beehive.md)

[Readme](https://github.com/BeehiveForum/BeehiveForum/blob/master/docs/readme.md)

[Release Notes](https://github.com/BeehiveForum/BeehiveForum/blob/master/docs/release.md)

[FAQ](https://github.com/BeehiveForum/BeehiveForum/blob/master/docs/faq.md)

[Change-Log](https://github.com/BeehiveForum/BeehiveForum/blob/master/docs/changelog.md)