# Migrating WoodBees from `utf8` to `utf8mb4`

WoodBees ships on MySQL's 3-byte `utf8`, which stores every character of every
living script (Arabic, Hebrew, Cyrillic, Japanese, Korean, common Chinese, ...).
Migrate to `utf8mb4` only if you need to store **emoji**, **supplementary-plane
CJK** (e.g. some Hong Kong / Taiwan personal-name characters) or **historic
scripts**.

The schema is already prepared for this: every indexed `VARCHAR` column is
narrow enough that its index still fits MySQL's key-length limits after the
conversion, so the migration is mechanical.

## 1. Back up the database

```
mysqldump -u <user> -p <database> > woodbees-pre-utf8mb4.sql
```

## 2. Convert every table

For each table in the database:

```sql
ALTER TABLE <table> CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

A helper that generates the statements for the whole database:

```sql
SELECT CONCAT('ALTER TABLE `', TABLE_NAME,
              '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE';
```

Run the output.

### Index-length notes (already handled by the schema)

MyISAM's b-tree key limit is 1000 bytes; `utf8mb4` is 4 bytes/char.

| Table | Column | Width | Index bytes (utf8mb4) |
|-------|--------|-------|----------------------|
| `<webtag>_THREAD` | `TITLE` | 250 | 1000 (`TITLE_LOOKUP`, lone column) |
| `<webtag>_POST` | `IPADDRESS` | 64 | 256 (+ `FROM_UID`) |
| `<webtag>_BANNED` | `BANDATA` | 190 | 761 (+ `BANTYPE`) |
| `<webtag>_USER_TRACK` | `USER_KEY` | 190 | 763 (+ `UID`, PK) |
| `FORUMS` | `WEBTAG` | 64 | 256 |
| `FORUM_SETTINGS` | `SNAME` | 190 | 764 (+ `FID`, PK) |
| `USER_TOKEN` | `TOKEN` | 64 | 256 (+ `UID`, PK) |
| `VISITOR_LOG` | `IPADDRESS` | 64 | 256 |

`SESSIONS` is InnoDB (Dynamic row format, 3072-byte key limit), so its
`REFERER VARCHAR(255)` index needs nothing.

`FULLTEXT` indexes (`<webtag>_THREAD.TITLE`, `<webtag>_POST_CONTENT.CONTENT`)
are rebuilt automatically by `CONVERT TO CHARACTER SET` and have no length
limit.

## 3. Switch the connection character set

Edit `forum/include/config.inc.php`:

```php
$db_charset = "utf8mb4";
```

## 4. Verify

- Post a comment containing an emoji and a supplementary-plane character; reload
  it and confirm it round-trips.
- Create a thread whose address is non-Latin; confirm the duplicate-address
  check still finds it.
- Run a search.
- Check the admin **Forum Stats** page renders.

## Rolling back

Restore `woodbees-pre-utf8mb4.sql` and set `$db_charset` back to `"utf8"`.
