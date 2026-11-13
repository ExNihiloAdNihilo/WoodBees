# WoodBees Alpha 0.2

A fork of Beehive Forum. See [docs/release.md](docs/release.md) for what's new in this release.

## Database engine

Before running `docker compose up`, copy [.env.example](.env.example) to
`.env` and set `DB_MODE`. There is no default - choose `mariadb` or `mysql`
to have Docker provision that database for you, or `external` to connect
WoodBees to a MariaDB/MySQL server you already run.

### Switching engines or modes later

The install wizard (New Install/Reinstall/Reconnect page) shows these same
two warnings with Copy buttons next to each command, so you can grab the
exact text without retyping it.

- **Switching `DB_IMAGE` (MariaDB ↔ MySQL)** re-uses the same `db_data`
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
- **Switching `DB_MODE` between a bundled engine and `external`** changes
  which services are active (the `mysql` service only runs behind the `db`
  Compose profile), but Compose doesn't remove a container just because it
  dropped out of the active profile - the old database container can keep
  running, unnoticed, in the background. Run `docker compose down
  --remove-orphans` before switching to clean it up.

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