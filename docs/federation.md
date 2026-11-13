# Instance federation (export / import / redaction)

Cooperating WoodBees admins can exchange threads, comments and tags as signed
JSON **bundles**. There is no live network protocol: an admin downloads a
bundle from *Admin > Federation Export* and sends the file to another admin,
who uploads it on *Admin > Federation Import*. Content that turns out to be
unwanted can be hidden or deleted per source instance on
*Admin > Instance Redaction*.

All three pages need forum admin rights (`USER_PERM_ADMIN_TOOLS`) and the
usual second admin password prompt.

## Identity: key and identifier

- **Signing key.** Each install has an Ed25519 keypair (PHP libsodium). It is
  generated at install / upgrade (or on first export if that failed) and the
  secret key is stored **outside the web root**, in
  `$WOODBEES_KEY_DIR/instance_ed25519.secret` (default
  `/var/lib/woodbees/keys`). In the bundled Docker stack that directory is the
  `instance_keys` named volume on the `web` service. The secret key is never
  exported. **The key is the instance's identity**: if it is lost (for
  example the volume is deleted), a new key is generated and every peer will
  refuse this instance's bundles until they deliberately re-trust it. An
  existing key file is never overwritten.
- **Instance identifier.** The `instance_identifier` global setting (set in
  the installer) is only the human-readable label. It can change; the key
  does not. Content born on an instance is stamped with this label.
- **Fingerprint.** `SHA256:` + the upper-case hex SHA-256 of the raw public
  key, in groups of four. Shown on the export page; admins compare it out of
  band (phone, chat) on the first import.

## Bundle format (format_version 1)

A bundle is a JSON **envelope**:

```json
{
  "format": "woodbees-bundle",
  "format_version": 1,
  "bundle_id": "40a9da3f1b1219887b92d0edd970ec9c",
  "exported_at": "2026-11-13T09:33:12Z",
  "origin": "BedRock",
  "public_key": "<base64, 32-byte Ed25519 public key, or null>",
  "payload": "<the payload JSON, as one string>",
  "signature": "<base64, 64-byte detached Ed25519 signature of payload, or null>"
}
```

- `payload` is a **string**. The signature covers exactly those bytes, so
  neither side has to canonicalise JSON. Verify with
  `sodium_crypto_sign_verify_detached(signature, payload, public_key)`.
- `bundle_id`, `exported_at` and `origin` are repeated inside the signed
  payload; the importer refuses the bundle if the envelope copies differ.
- An **unsigned** bundle has `public_key` and `signature` set to `null`.

The decoded payload:

```json
{
  "format": "woodbees-bundle",
  "format_version": 1,
  "bundle_id": "...", "exported_at": "...", "origin": "BedRock",
  "thread_count": 1, "post_count": 2,
  "threads": [
    {
      "origin": "BedRock",
      "address": "https://example.com/article",
      "created": "2026-11-13T09:31:14Z",
      "posts": [
        {
          "origin": "BedRock",
          "ref": "2.1",
          "reply_to": null,
          "author": "CoolBreeze",
          "created": "2026-11-13T09:31:14Z",
          "content": "<p>sanitised HTML</p>",
          "tags": ["federation"]
        },
        {
          "origin": "BedRock", "ref": "2.2",
          "reply_to": { "origin": "BedRock", "ref": "2.1" },
          "author": "CoolBreeze", "created": "...", "content": "...", "tags": []
        }
      ]
    }
  ]
}
```

- `address` is the thread title (the free-form lowercase address).
- Each comment carries its **true origin**: comments born on the exporting
  instance get its identifier and `ref` = `"tid.pid"` there; comments it had
  itself imported keep the `origin`, `ref` and `author` they arrived with, so
  provenance survives re-sharing. A thread's `origin` works the same way.
- `(origin, ref)` identifies a comment across all instances.
- `reply_to` points at another comment by `(origin, ref)`.
- `author` is a display name only (nickname, else logon). No email, IP or
  account data is exported.
- Only approved, undeleted comments in undeleted, approved threads are
  exported, with **approved** tags only.
- Timestamps are ISO 8601 UTC.

## Export

*Admin > Federation Export*: tick folders, optionally limit to thread ids
and/or a comment date range, and download. Signing is on by default (it can
be switched off for a deliberately unsigned bundle). The page shows this
instance's identifier and key fingerprint. An instance with no identifier
cannot export.

## Import and trust (TOFU)

*Admin > Federation Import*: upload a bundle, review, then confirm. Nothing
is written before the confirmation step, and the bundle is re-verified then.

Pinned keys live in the global `TRUSTED_INSTANCE` table (identifier, public
key, status `TRUSTED`/`UNTRUSTED`, first/last seen, confirming admin).

| Bundle | Result |
| --- | --- |
| Malformed, envelope/payload mismatch, unsupported version | refused |
| Claims this instance's own identifier, or signed with this instance's own key | refused |
| Signature does not verify | refused ("BAD SIGNATURE") |
| Signed, identifier pinned with a **different** key | refused ("KEY MISMATCH") |
| Signed, identifier pinned but marked untrusted | refused |
| Signed, identifier pinned with this key | accepted |
| Signed, identifier never seen | admin must tick "I have checked the key fingerprint"; the key is then pinned |
| Unsigned, identifier pinned | refused |
| Unsigned, identifier never seen | shown as **UNSIGNED**; admin must tick "Import this UNSIGNED bundle anyway" |
| `bundle_id` already in this forum's `IMPORT_LOG` | refused |

If a first-seen key is already pinned under a different identifier, the
review page says so (the peer may have been renamed).

The trusted-instance list on the import page offers **Untrust** (refuse
further bundles; the alpha's revocation), **Trust** again, and **Forget**
(drop the pinned key, e.g. after a peer genuinely replaced its key; the next
bundle is first-seen again).

Bundle-level signing means you trust the instance that *relayed* a bundle
for the origins it claims for re-shared comments. Per-comment signatures
(chain of custody) are a possible later extension.

### What an import writes

- **Merge by address.** If a live thread with the same (normalised) address
  exists, the imported comments are **appended** to it; the existing thread
  keeps its folder, title, tags and moderation state. Otherwise a new thread
  is created in the folder chosen on the review page, with `ORIGIN_INSTANCE`
  set to the thread's origin.
- Comments are appended in timestamp order and keep their original
  `CREATED` time. (They get new local PIDs after any existing comments; a
  thread is not re-numbered to interleave them.)
- **Duplicates are skipped**: a comment whose `(origin, ref)` already exists
  anywhere in the forum (`POST.ORIGIN_INSTANCE` + `POST.ORIGIN_REF`), and any
  comment whose origin is this instance (its own content coming back).
  A thread is never created if all its comments are skipped.
- Content is re-sanitised with the same HTML filter as local posts.
- **Authors.** `POST.FROM_UID` is a non-login **placeholder user, one per
  origin instance**, created on first import: logon `~FED-<12 hex of
  sha1(identifier)>`, nickname = the identifier, password field `!` (no hash
  can match it and `user_logon()` refuses it outright), no email, no forum
  access rows. The original author's name is stored as text in
  `POST.ORIGIN_AUTHOR` and shown as e.g. **"CoolBreeze @ BedRock"**, without
  profile, PM, relationship or admin links. Placeholders are left out of the
  admin user list, user counts, logon lookups (so they cannot be addressed by
  PM or as a post recipient) and the thread recipient dropdown; the
  relationship and admin-user pages refuse them. Replying to an imported
  comment does not address the reply to anyone.
- **Tags.** Tags that already exist here are linked as they are (approved or
  not). New tags are created **pending** in the Tag Approval Queue with
  `TAG.ORIGIN_INSTANCE` set, and one notice per import listing them is posted
  to the moderators' tag request thread.
- The import is recorded in the per-forum `IMPORT_LOG` table (bundle id,
  origin, key, counts, who, when), listed on the import page.

## Redaction

*Admin > Instance Redaction*: enter an origin identifier, choose a scope
(**all content** or **tags only**) and an action (**hide** or **delete**),
review the counts, confirm. This instance's own identifier is refused.

Because merged threads mix local and imported comments, redaction works
**per comment** via `POST.ORIGIN_INSTANCE`:

| | Hide (reversible) | Delete (permanent) |
| --- | --- | --- |
| Threads made up only of that origin's comments | `THREAD.DELETED = 'Y'` | purged |
| That origin's comments (all threads) | `POST.APPROVED = NULL`: shown as awaiting approval, listed in the Post Approval Queue | in mixed threads: content, author name and tags removed; the row stays as a "deleted" tombstone |
| Tags the origin introduced (`TAG.ORIGIN_INSTANCE`) | de-approved | deleted |

Hide only touches what is currently visible: comments and tags that were
already awaiting approval, and threads already deleted, are left as they are.

### Restore

Every change a hide makes is recorded in the per-forum `REDACTION_LOG`
table (item, origin, the comment's or tag's original approval time and
approver, who hid it and when). The redaction page lists each origin with
hidden content and its counts, with a **Restore** button (asks for
confirmation). Restore:

- undeletes the hidden threads that are still deleted;
- gives the hidden comments and tags that are still unapproved their
  original `APPROVED` / `APPROVED_BY` back;
- leaves alone anything a moderator has since approved, undeleted or
  deleted by hand, and never approves anything that was pending before
  the hide;
- then clears that origin's log rows, and is written to the admin log.

Restore covers everything hidden for the origin, whatever the scope of
each hide. A **delete** clears the origin's log rows (all of them, or the
tag rows for a tags-only delete), so deleted content is never offered for
restore.

Tombstones keep `ORIGIN_INSTANCE`/`ORIGIN_REF`, which keeps thread numbering
intact and stops a later bundle from silently re-importing those comments.
Purged whole threads are gone completely, so a later bundle from that origin
can bring them back - **Untrust** the instance on the import page as well if
you want it kept out.

## Schema

- `POST.ORIGIN_INSTANCE`, `THREAD.ORIGIN_INSTANCE`, `TAG.ORIGIN_INSTANCE`
  (`VARCHAR(64)`, NULL = born here) - from Alpha 0.1.
- `POST.ORIGIN_AUTHOR VARCHAR(64)`, `POST.ORIGIN_REF VARCHAR(64)`, key
  `ORIGIN_REF (ORIGIN_INSTANCE, ORIGIN_REF)`.
- Per forum: `IMPORT_LOG` (primary key `BUNDLE_ID`); `REDACTION_LOG`
  (primary key `ITEM_TYPE, TID, PID`; `ITEM_TYPE` T = thread, P = comment,
  G = tag with `TID` = tag id).
- Global: `TRUSTED_INSTANCE` (unique `IDENTIFIER`).

New installs create these; the installer's Upgrade method adds them to an
existing install and generates the signing key if there is none.

## Known limits (alpha)

- Attachments, polls, ratings and post recipients are not exported.
- Thread view counts, stickiness and closed state are not exported.
- Search: imported comments are indexed by Manticore on its next reindex
  like any other comment.
- No per-comment signatures; no automatic key rotation.
