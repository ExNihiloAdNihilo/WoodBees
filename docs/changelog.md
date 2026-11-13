# Beehive Forum Change Log (Generated: Tue, 23 Aug 2016 20:25:51)

## Unreleased (WoodBees Alpha 0.3.1)

- Fixed: Thread views slowed down as the forum grew: since Alpha 0.3
         every comment shown looked up its stable comment id with a
         full scan of POST_SEARCH_ID. New TID_PID index on
         POST_SEARCH_ID(TID, PID); the installer's Upgrade method adds
         it to existing forums automatically.
- Fixed: A second comment from the same user within the same second
         was silently thrown away (the page returned to the thread as if
         it had been posted). Every post, poll and PM form now carries
         its own random double-submit key, and a form submitted twice,
         even at the same moment, creates one comment; the repeat shows
         "This comment has already been posted".
- Fixed: The web installer could be run by anyone on a finished
         install, and its Reinstall method drops every table. It now
         only runs while include/config.inc.php is missing (a fresh
         copy) or while the file include/install.enable exists, which
         the operator creates to run Upgrade, Reinstall or Reconnect and
         the installer deletes when it is done. docker/nginx.conf
         applies the same rule. The forum shows a reminder while the
         file exists.
- Fixed: Folder subscription emails were never sent (the thread and
         post ids were passed in the wrong order).
- Fixed: Thread subscription emails were sent to subscribers who had
         the poster on "Ignored Completely", and were not sent to
         subscribers who had them as a Friend. Subscribers with the
         poster on Ignored or Ignored Completely now get no email.
- Fixed: A search string Manticore could not parse (a stray quote, a
         lone minus, unbalanced brackets, @, ~, ^ or $) switched search
         off for every user for a minute. Such a search is now retried
         with those characters treated as plain text; only a lost
         connection or a missing index marks the search server as down.
- Fixed: Moderator Merge Thread failed with an SQL error for every merge
         type, leaving both threads closed and an empty deleted thread
         behind. Merged threads now keep the comments' dates, reply
         links, tags, attachments, ratings and federation origin, and
         carry over subscriptions and the view count; a failed merge
         leaves both threads as they were. The "Posts in this thread at
         end" option worked as "at start". Merging also checked the
         moderator's permission on the first thread's folder twice
         instead of on both threads' folders.
- Fixed: The installer's Reinstall method never worked. It dropped the
         old tables and then stopped with "Selected database contains
         tables which conflict", leaving an empty forum; run again, it
         failed with "Table 'PM_RECIPIENT' already exists" because nine
         tables (PM_RECIPIENT, PM_TYPE, SFS_CACHE, USER_TOKEN and the
         per-forum POLL_QUESTIONS, POST_RATING, POST_RECIPIENT, POST_TAG
         and TAG) were missing from the installer's table list. New
         Install and Admin > Forums > Create now also notice those tables
         as conflicts.
- Fixed: The MariaDB 11.4 minimum version was never enforced: the
         check looked for "MariaDB" in a version string that had already
         been cut down to its numbers, so every MariaDB was measured
         against the MySQL 8.4 floor and MariaDB 10.x was accepted. New
         db::is_mariadb(); the installer and the forum's start-up check
         now refuse MariaDB older than 11.4 (MySQL older than 8.4 was
         already refused).

## Date: Fri, 13 Nov 2026 (WoodBees Alpha 0.3)

- Changed: Version bump to WoodBees Alpha 0.3.
- Added: Instance federation. Admin > Federation Export downloads a
         signed JSON bundle of threads, comments and tags; Admin >
         Federation Import verifies it (Ed25519, trust on first use with
         a confirmed key fingerprint) and merges comments into existing
         threads with the same address; Admin > Instance Redaction hides
         or deletes another instance's content comment by comment. See
         docs/federation.md.
- Added: Restore button on Admin > Instance Redaction. It puts back
         exactly what a Hide redaction changed (threads undeleted,
         original comment and tag approvals) and leaves alone anything a
         moderator has dealt with since.
- Fixed: A thread page's meta description/keywords were taken from
         comment 1 even when it was awaiting approval (or hidden by an
         instance redaction); they now use the first approved comment.
- Fixed: Threads started by a user with no forum-level permission row
         (e.g. federation placeholder authors) were missing from the
         RSS feed, the sitemap and most thread-list views other than
         All/Unread Discussions.
- Added: Comment links the forum hands out (comment number links, light
         mode, notification emails) carry a stable comment id (c=), so
         they keep working after a thread is renumbered. Links in comment
         text are rewritten during renumbering, and opening a renumbered
         number without an id shows where an older link's comment is now.
- Changed: Importing comments that are older than ones already in a
           merged thread now renumbers the thread into date order
           instead of appending them at the end; read positions,
           replies, tags, ratings and search ids move with their
           comments, and the thread shows a note that its comment
           numbers changed. New RESEQUENCE_LOG table (run Upgrade).
- Changed: Imported comments show "author @ instance" in the thread
           list, search results, threads RSS feed and Post Approval
           Queue too; federation placeholder accounts are left out of
           the member count, newest member and top poster stats.
- Fixed: Search results showed a snippet of comments awaiting approval
         (and of comments hidden by an instance redaction) to everyone;
         they now only show to the author and folder moderators.
- Added: Per-install Ed25519 signing key, kept outside the web root on
         the new instance_keys Docker volume (WOODBEES_KEY_DIR).
- Changed: New POST.ORIGIN_AUTHOR / POST.ORIGIN_REF columns and
           IMPORT_LOG / REDACTION_LOG / TRUSTED_INSTANCE tables; run the
           installer's Upgrade method on existing installs.
- Fixed: Installer Upgrade method failing with an SQL syntax error
         (missing backtick in the POST_SEARCH_ID TRUNCATE).
- Changed: Database engine is now picked by a Compose file
           (docker-compose.mariadb.yml / .mysql.yml / .external.yml)
           via COMPOSE_FILE in .env, replacing DB_MODE, DB_IMAGE and
           COMPOSE_PROFILES. Update your .env; see .env.example.
- Changed: include/config.inc.php is no longer tracked by git; a
           missing config file now sends you to the installer folder
           (install or install-disabled). Back up your config.inc.php
           before pulling this change - git removes it.

## Date: Fri, 13 Nov 2026

- Changed: DB_MODE is now a required .env setting (mariadb, mysql, or
           external), with support for pointing WoodBees at an
           external MariaDB/MySQL server instead of the bundled one.
- Added: Copy-to-clipboard buttons on the install wizard, and
         scripts/switch-db-engine.sh / switch-db-engine.ps1 helper
         scripts, for safely switching DB engine or mode without
         losing data or leaving orphaned containers behind.
- Added: Admin warning and user-facing grey-out of the search form
         when the Manticore search server is unreachable or has not
         indexed the forum, instead of searches failing silently.
- Fixed: Search staying disabled indefinitely after Manticore was
         briefly unavailable - a cached "down" status is now re-checked
         live after 60 seconds instead of waiting for an admin to open
         the settings page.
- Changed: Minimum supported database is now MySQL 8.4 or MariaDB
           11.4 (was MySQL 5.7). Older external database servers are
           refused by the installer.
- Fixed: Manticore container leaking an anonymous Docker volume every
         time it was recreated.

## Date: Thu, 12 Nov 2026

- Changed: docker-compose.yml's database image is now selectable via
           DB_IMAGE (MariaDB 11.4 or MySQL 8.4).
- Fixed: Renamed the GROUPS table to GROUP_INFO, since MySQL 8
         reserves GROUPS as a keyword and refused to create it.
- Fixed: Web image build failing against Alpine's CDN due to a stale
         CA bundle.
- Fixed: Forum Stats page 500/502s on a fresh install with no
         date-of-birth data.

## Date: Mon, 08 Aug 2016

- Fixed: Remove width and height from images.css main .image selector.
- Fixed: application-name and msapplication-tooltip attributes had
         wordfilter tags in output.
- Fixed: Various XSS vulnerabilities fixed.

## Date: Wed, 22 Jun 2016

- Fixed: Prevent toolbar button hover making the page bounce.

## Date: Thu, 10 Mar 2016

- Fixed: CKEditor not loading content.css due to wrong path.
- Changed: Tidy-up some code.

## Date: Tue, 08 Mar 2016

- Fixed: Avoid collision with PHP's own Error class.
- Changed: Tidy-up some code.

## Date: Thu, 03 Mar 2016

- Changed: Update .gitignore.

## Date: Wed, 24 Feb 2016

- Changed: Remove forum\_path property and related code.
- Changed: Removed Beehive dependency from CKEditor plugins and add
- Changed: Load json.php via AJAX in general.js and pass result as
           argument
- Changed: Add localization support to CKEditor plugins and remove
           dependency on Beehive.
- Changed: Load json.php from general.js and pass result through in
           beehive.init event.
- Changed: Move 3rd Party JavaScript into lib subdirectory.
- Changed: Replaced old jquery.sprintf with sprintf-js
- Changed: Tidy JavaScript with JSHint.
- Changed: Remove call usage and add argument for element.
- Changed: Hide vote icons on own posts.
- Changed: Rename forum\_links.js

## Date: Sun, 31 Jan 2016

- Fixed: Current Thread icon wasn't being applied correctly.

## Date: Thu, 17 Dec 2015

- Fixed: Including wrong bootstrap in ledit.php (caused error in PHP7)

## Date: Fri, 16 Oct 2015

- Fixed: Undefined index UID when performing SFS check

## Date: Wed, 26 Aug 2015

- Changed: Added constant to disable CSRF checking. Fixes AJAXChat
           integration.

## Date: Sat, 08 Aug 2015

- Fixed: Unable to login if no existing session due to failure to call
         session\_start in session::init.
- Fixed: Lots of code had calls to functions with too many arguments.
- Fixed: Tabular polls not working.

## Date: Fri, 07 Aug 2015

- Changed: Generate CSRF token when login occurs / session is restored
           and don't regenerate it unless invalid

## Date: Tue, 14 Jul 2015

- Changed: Add CSS classes to elements of the message pane to allow
           styling separately.

## Date: Sat, 13 Jun 2015

- Fixed: Function forum\_delete not removing TAG and POST\_TAG tables.
- Fixed: Google Chrome squashing the content into narrow column.

## Date: Sun, 07 Jun 2015

- Changed: Quick Reply closing when clicking outside it causing
           premature close. Use cancel button instead.

