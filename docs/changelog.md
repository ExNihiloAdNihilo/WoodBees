# Beehive Forum Change Log (Generated: Tue, 23 Aug 2016 20:25:51)

## Unreleased (WoodBees Alpha 0.3.1)

- Fixed: Password-reset and email-confirmation links contained the
         account's stored password hash as their secret, so the hash
         was mailed, ended up in mail and web server logs and Referer
         headers, and a reset link never expired (an unused reminder
         email stayed a working key to the account indefinitely). The
         links now carry a random one-time token (stored only as a
         SHA-256 in the new USER_ACTION_TOKEN table, created by Upgrade):
         password reset links last 2 hours, email confirmation links 7
         days, a new request replaces the old link, and using the link
         or changing the password ends it.
- Fixed: Attachments could be downloaded by anyone who knew the hash,
         whatever folder the comment was in: get_attachment.php only
         checked that the user was logged in, and nginx served
         /attachments/<hash> as a static file with no check at all. A
         download is now allowed only for the uploader, or if the file is
         linked to an approved comment (in an approved thread that is not
         deleted) in a folder the user can read (folder moderators also
         see unapproved comments), to a private message the user sent or
         received, or as someone's avatar or profile picture; anything
         else gets the "Missing or invalid attachment hash" page.
         docker/nginx.conf no longer serves /attachments/, and the
         attachment directory gets a .htaccess for Apache (effective only
         where AllowOverride permits it).
- Fixed: Deleting a comment, rejecting one in the Post Approval Queue,
         or permanently deleting a thread never deleted its attachments:
         the file stayed on disk, downloadable, and kept counting against
         the uploader's quota. They are now deleted with the comment
         (a file shared by several comments goes with the last one).
         Deleting an attachment also removes the links from comments and
         private messages that pointed at it, and a moderator deleting
         someone else's attachment is now checked against the comment's
         real folder.
- Fixed: Comments awaiting moderator approval triggered the
         reply-notification and thread/folder subscription emails,
         showing their content (and a link) to people who were not meant
         to see them yet. These emails are now sent when a moderator
         approves the comment (single or bulk), and never for a
         rejected one.
- Added: Upgrade offers "delete attachments that nothing uses any more"
         (Advanced Options): it removes attachment files and links left
         by comments deleted or rejected before this fix, deleted
         threads and abandoned uploads (files under a day old, avatars
         and profile pictures are kept).
- Fixed: Passwords were hashed with DES crypt(), so only the first 8
         characters counted ("Passw0rdZ" logged in as "Passw0rd10"), and
         there was no limit on logon attempts. New and changed passwords
         are now bcrypt; an existing account is converted at its next
         successful logon (what was typed becomes the stored password, so
         a long password is kept in full from then on). Failed logons are
         counted per client address (10) and per account name (20) in a
         15-minute window in the new LOGON_THROTTLE table (created by
         Upgrade, and without it logons still work, unthrottled); over the
         limit the password is not checked and the user is told to wait.
         A successful logon gives its address slot back.
- Fixed: Usernames were not unique under concurrency: ten people
         registering the same name at the same moment got up to four
         accounts with it (impersonation, ban and moderation confusion).
         USER.LOGON now has a UNIQUE index (new installs create it;
         Upgrade adds it), and the second registration gets "a user with
         that name already exists". If a forum already has duplicate
         usernames, Upgrade lists them on its last page and leaves the
         old index in place until they are renamed.
- Fixed: Two people commenting on a new address at the same moment
         created two threads for that address (4 of 5 runs with five
         people), splitting the first comments between them for good.
         The look-up and creation of a thread at a new address, in post,
         light post and poll creation, now run under a database lock
         named after a hash of the address, so there is one thread and
         everyone's comment lands in it. Threads that were already
         duplicated are not merged by this.
- Fixed: The installer's Upgrade method rebuilt POST_SEARCH_ID from
         scratch every time it ran, renumbering the stable comment ids
         ("c=" in comment links, and the Manticore document ids), so
         links made before an Upgrade could open a different comment
         afterwards. Upgrade now keeps existing ids, adds the TID_PID
         index if missing, and only gives ids to comments that have
         none, so running it again changes nothing.
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
- Fixed: On MySQL, an emoji or other 4-byte character (U+10000 and up,
         e.g. CJK Extension B) cut the rest of the text off: "Hello
         <emoji> world" was saved as "Hello ", two addresses differing
         after an emoji landed in the same thread, and an address
         starting with one was saved empty. (MariaDB stored "????"
         instead.) The tables are 3-byte utf8 and cannot hold these
         characters, so they are now replaced with U+FFFD before they
         reach the database, in form input, cookies, upload file names
         and federation imports, on both engines. Password fields are
         left as typed. Real emoji support needs the utf8mb4 migration
         (docs/utf8mb4-migration.md).
- Fixed: With the bundled MySQL 8.4, an unclean database stop (OOM
         kill, power cut, docker kill) could take the forum down until
         someone ran REPAIR TABLE by hand: MySQL never repairs MyISAM
         tables by default, so damaged tables were served until a query
         noticed, then failed every page that used them ("Table ... is
         marked as crashed"). docker-compose.mysql.yml now starts MySQL
         with --myisam-recover-options=BACKUP,FORCE, which checks and
         repairs such tables when they are first opened.

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

