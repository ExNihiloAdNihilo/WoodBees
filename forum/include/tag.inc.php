<?php

/*======================================================================
Copyright Project Beehive Forum 2002

This file is part of Beehive Forum.

Beehive Forum is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

Beehive Forum is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Beehive; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307
USA
======================================================================*/

// Required includes
require_once BH_INCLUDE_PATH . 'constants.inc.php';
require_once BH_INCLUDE_PATH . 'db.inc.php';
require_once BH_INCLUDE_PATH . 'folder.inc.php';
require_once BH_INCLUDE_PATH . 'forum.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'post.inc.php';
require_once BH_INCLUDE_PATH . 'session.inc.php';
require_once BH_INCLUDE_PATH . 'thread.inc.php';
require_once BH_INCLUDE_PATH . 'user.inc.php';
// End Required includes

// Result codes returned by tag_request()
define('TAG_REQUEST_INVALID', 0);   // failed validation
define('TAG_REQUEST_LINKED', 1);    // tag already known and approved - attached immediately
define('TAG_REQUEST_PENDING', 2);   // tag already known but still awaiting approval - attached, hidden
define('TAG_REQUEST_REQUESTED', 3); // brand new tag - created pending and posted to the moderator thread
define('TAG_REQUEST_ALREADY', 4);   // this comment already carries this tag

// Maximum tag length (also the width of the TAG.TAG column).
define('TAG_MAX_LENGTH', 30);

/**
 * True unless tagging has been explicitly switched off in the forum
 * settings. Unlike the legacy inline #hashtag behaviour, the moderated
 * tag flow is on by default.
 *
 * @return bool
 */
function tag_enabled()
{
    return (mb_strtoupper(forum_get_setting('enable_tags', null, 'Y')) !== 'N');
}

/**
 * Normalise a user-supplied tag string.
 *
 * Tags are lower-case (Unicode-aware); may contain letters, marks and digits
 * of any script (Arabic, Japanese, Cyrillic, ...) plus single spaces; are
 * trimmed; and are capped at TAG_MAX_LENGTH characters. Control, format and
 * punctuation characters are stripped. Returns '' when nothing usable remains.
 *
 * @param string $tag
 * @return string
 */
function tag_normalise($tag)
{
    // Tags: letters, marks and digits of any script plus single spaces.
    return text_address_normalise($tag, TAG_MAX_LENGTH, true);
}

/**
 * True when the string is a valid tag (non-empty once normalised and
 * unchanged by normalisation).
 *
 * @param string $tag
 * @return bool
 */
function tag_is_valid($tag)
{
    return (is_string($tag) && strlen($tag) > 0 && $tag === tag_normalise($tag));
}

/**
 * Fetch a single tag row by its id.
 *
 * @param int $tagid
 * @return mixed array on success, false on failure
 */
function tag_get($tagid)
{
    if (!is_numeric($tagid)) return false;

    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $sql = "SELECT TID AS TAGID, TAG, UNIX_TIMESTAMP(CREATED) AS CREATED, CREATED_BY, ";
    $sql .= "UNIX_TIMESTAMP(APPROVED) AS APPROVED, APPROVED_BY, SOURCE_TID, SOURCE_PID ";
    $sql .= "FROM `{$table_prefix}TAG` WHERE TID = '$tagid'";

    if (!($result = $db->query($sql)) || $result->num_rows == 0) return false;

    return $result->fetch_assoc();
}

/**
 * Fetch a single tag row by its (normalised) name.
 *
 * @param string $tag
 * @return mixed array on success, false when not found
 */
function tag_get_by_name($tag)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $tag = $db->escape(tag_normalise($tag));

    if (strlen($tag) == 0) return false;

    $sql = "SELECT TID AS TAGID, TAG, UNIX_TIMESTAMP(CREATED) AS CREATED, CREATED_BY, ";
    $sql .= "UNIX_TIMESTAMP(APPROVED) AS APPROVED, APPROVED_BY, SOURCE_TID, SOURCE_PID ";
    $sql .= "FROM `{$table_prefix}TAG` WHERE TAG = '$tag'";

    if (!($result = $db->query($sql)) || $result->num_rows == 0) return false;

    return $result->fetch_assoc();
}

/**
 * Find or create a tag row for the given name.
 *
 * When the tag does not yet exist it is created; $approved controls whether
 * it starts life publicly visible (inline #hashtags) or pending a moderator
 * (the "add tag" flow).
 *
 * @param string $tag        tag name (will be normalised)
 * @param bool   $approved   create pre-approved when new
 * @param int    $uid        requesting user
 * @param int    $source_tid comment thread the request came from (0 for none)
 * @param int    $source_pid comment the request came from (0 for none)
 * @return mixed tag id on success, false on failure
 */
function tag_get_or_create($tag, $approved, $uid, $source_tid = 0, $source_pid = 0)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $tag_normalised = tag_normalise($tag);

    if (strlen($tag_normalised) == 0) return false;

    if (($existing = tag_get_by_name($tag_normalised)) !== false) {
        return $existing['TAGID'];
    }

    if (!is_numeric($uid)) $uid = 0;
    if (!is_numeric($source_tid)) $source_tid = 0;
    if (!is_numeric($source_pid)) $source_pid = 0;

    $tag_escaped = $db->escape($tag_normalised);

    $approved_sql = ($approved === true) ? 'NOW()' : 'NULL';
    $approved_by_sql = ($approved === true) ? intval($uid) : 'NULL';

    $sql = "INSERT IGNORE INTO `{$table_prefix}TAG` (TAG, CREATED, CREATED_BY, APPROVED, APPROVED_BY, SOURCE_TID, SOURCE_PID) ";
    $sql .= "VALUES ('$tag_escaped', NOW(), " . intval($uid) . ", $approved_sql, $approved_by_sql, ";
    $sql .= intval($source_tid) . ", " . intval($source_pid) . ")";

    $db->query($sql);

    if ($db->affected_rows > 0) {
        return $db->insert_id;
    }

    // Row already existed (lost a race with a concurrent insert).
    if (($existing = tag_get_by_name($tag_normalised)) !== false) {
        return $existing['TAGID'];
    }

    return false;
}

/**
 * Attach an existing tag id to a comment.
 *
 * @param int $tagid
 * @param int $tid
 * @param int $pid
 * @return bool true when a new link was created, false when it already existed or on error
 */
function tag_link_post($tagid, $tid, $pid)
{
    if (!is_numeric($tagid) || !is_numeric($tid) || !is_numeric($pid)) return false;

    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $sql = "INSERT IGNORE INTO `{$table_prefix}POST_TAG` (TID, PID, TAG) ";
    $sql .= "VALUES ('$tid', '$pid', '$tagid')";

    if (!$db->query($sql)) return false;

    return ($db->affected_rows > 0);
}

/**
 * Handle a user's request to add a tag to a comment.
 *
 * @param string $tag  raw tag string from the user
 * @param int    $tid  comment's thread id
 * @param int    $pid  comment's post id
 * @param int    $uid  requesting user
 * @return int one of the TAG_REQUEST_* constants
 */
function tag_request($tag, $tid, $pid, $uid)
{
    if (!is_numeric($tid) || !is_numeric($pid) || !is_numeric($uid)) return TAG_REQUEST_INVALID;

    $tag_normalised = tag_normalise($tag);

    if (strlen($tag_normalised) == 0) return TAG_REQUEST_INVALID;

    $existing = tag_get_by_name($tag_normalised);

    if ($existing !== false) {

        if (!tag_link_post($existing['TAGID'], $tid, $pid)) {
            return TAG_REQUEST_ALREADY;
        }

        return (isset($existing['APPROVED'])) ? TAG_REQUEST_LINKED : TAG_REQUEST_PENDING;
    }

    if (($tagid = tag_get_or_create($tag_normalised, false, $uid, $tid, $pid)) === false) {
        return TAG_REQUEST_INVALID;
    }

    tag_link_post($tagid, $tid, $pid);

    tag_post_moderator_request($tagid, $tag_normalised, $tid, $pid, $uid);

    return TAG_REQUEST_REQUESTED;
}

/**
 * Approve a pending (or previously de-approved) tag.
 *
 * @param int $tagid
 * @param int $uid moderator performing the action
 * @return bool
 */
function tag_approve($tagid, $uid)
{
    if (!is_numeric($tagid) || !is_numeric($uid)) return false;

    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $sql = "UPDATE `{$table_prefix}TAG` SET APPROVED = NOW(), APPROVED_BY = '" . intval($uid) . "' ";
    $sql .= "WHERE TID = '$tagid'";

    return (bool) $db->query($sql);
}

/**
 * Remove approval from a tag, hiding it from users again.
 *
 * @param int $tagid
 * @param int $uid moderator performing the action
 * @return bool
 */
function tag_deapprove($tagid, $uid)
{
    if (!is_numeric($tagid) || !is_numeric($uid)) return false;

    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $sql = "UPDATE `{$table_prefix}TAG` SET APPROVED = NULL, APPROVED_BY = NULL ";
    $sql .= "WHERE TID = '$tagid'";

    return (bool) $db->query($sql);
}

/**
 * Delete a tag entirely, along with every comment link that used it.
 *
 * @param int $tagid
 * @return bool
 */
function tag_delete($tagid)
{
    if (!is_numeric($tagid)) return false;

    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    if (!$db->query("DELETE FROM `{$table_prefix}POST_TAG` WHERE TAG = '$tagid'")) return false;

    return (bool) $db->query("DELETE FROM `{$table_prefix}TAG` WHERE TID = '$tagid'");
}

/**
 * Return the tags attached to a comment.
 *
 * Approved tags are returned for everyone. Pending / de-approved tags are
 * only returned when the viewer can moderate or originally requested them;
 * those carry 'PENDING' => true.
 *
 * @param int $tid
 * @param int $pid
 * @param int  $viewer_uid
 * @param bool $can_moderate
 * @return array list of ['TAGID','TAG','PENDING']
 */
function tag_get_for_post($tid, $pid, $viewer_uid = 0, $can_moderate = false)
{
    if (!is_numeric($tid) || !is_numeric($pid)) return array();

    if (!$db = db::get()) return array();

    if (!($table_prefix = get_table_prefix())) return array();

    $sql = "SELECT TAG.TID AS TAGID, TAG.TAG, TAG.APPROVED, TAG.CREATED_BY ";
    $sql .= "FROM `{$table_prefix}POST_TAG` POST_TAG ";
    $sql .= "INNER JOIN `{$table_prefix}TAG` TAG ON (TAG.TID = POST_TAG.TAG) ";
    $sql .= "WHERE POST_TAG.TID = '$tid' AND POST_TAG.PID = '$pid' ";
    $sql .= "ORDER BY TAG.TAG";

    if (!($result = $db->query($sql))) return array();

    $tags = array();

    while (($row = $result->fetch_assoc()) !== null) {

        $pending = ($row['APPROVED'] === null);

        if ($pending && !$can_moderate && $row['CREATED_BY'] != $viewer_uid) {
            continue;
        }

        $tags[] = array(
            'TAGID'   => $row['TAGID'],
            'TAG'     => $row['TAG'],
            'PENDING' => $pending
        );
    }

    return $tags;
}

/**
 * True when the current user may approve / de-approve tags: any folder
 * moderator, or anyone with admin access.
 *
 * @return bool
 */
function tag_user_can_moderate()
{
    if (session::check_perm(USER_PERM_ADMIN_TOOLS, 0)) return true;

    return (session::get_folders_by_perm(USER_PERM_FOLDER_MODERATE) !== false);
}

/**
 * Resolve (creating if necessary) the private folder that holds the
 * moderator tag-request thread.
 *
 * @return mixed folder id on success, false on failure
 */
function tag_get_moderator_folder_fid()
{
    $fid = forum_get_setting('tag_moderator_fid');

    if (is_numeric($fid) && folder_get($fid)) {
        return intval($fid);
    }

    $fid = folder_create(
        gettext("Moderators"),
        gettext("Private moderator folder. Tag approval requests are posted here."),
        '',
        FOLDER_ALLOW_NORMAL_THREAD,
        0
    );

    if (!is_numeric($fid) || $fid < 1) return false;

    tag_sync_moderator_folder_access($fid);

    forum_save_settings(array('tag_moderator_fid' => $fid));

    return intval($fid);
}

/**
 * Grant read/post access to the private moderator folder to every current
 * moderator and admin. Safe to re-run; only ever adds permission bits.
 *
 * @param int $fid folder id (defaults to the configured moderator folder)
 * @return bool
 */
function tag_sync_moderator_folder_access($fid = null)
{
    if (!$db = db::get()) return false;

    if (!is_numeric($fid)) {
        $fid = forum_get_setting('tag_moderator_fid');
    }

    if (!is_numeric($fid) || $fid < 1) return false;

    if (!($forum_fid = get_forum_fid())) return false;

    $grant = USER_PERM_POST_READ | USER_PERM_POST_CREATE | USER_PERM_THREAD_CREATE | USER_PERM_FOLDER_MODERATE;
    $mod_mask = USER_PERM_FOLDER_MODERATE | USER_PERM_ADMIN_TOOLS;

    $uid_list = array();

    $sql = "SELECT DISTINCT UID FROM USER_PERM ";
    $sql .= "WHERE FORUM IN (0, $forum_fid) AND (PERM & $mod_mask) > 0";

    if (($result = $db->query($sql))) {

        while (($row = $result->fetch_row()) !== null) {
            $uid_list[$row[0]] = $row[0];
        }
    }

    $sql = "SELECT DISTINCT GROUP_USERS.UID FROM GROUP_USERS ";
    $sql .= "INNER JOIN GROUPS ON (GROUPS.GID = GROUP_USERS.GID) ";
    $sql .= "INNER JOIN GROUP_PERMS ON (GROUP_PERMS.GID = GROUPS.GID) ";
    $sql .= "WHERE GROUPS.FORUM IN (0, $forum_fid) AND (GROUP_PERMS.PERM & $mod_mask) > 0";

    if (($result = $db->query($sql))) {

        while (($row = $result->fetch_row()) !== null) {
            $uid_list[$row[0]] = $row[0];
        }
    }

    // Always include the forum owner.
    if (($forum_settings = forum_get_settings()) && isset($forum_settings['owner_uid'])) {
        $uid_list[$forum_settings['owner_uid']] = $forum_settings['owner_uid'];
    }

    foreach ($uid_list as $uid) {

        if (!is_numeric($uid) || $uid < 1) continue;

        $sql = "INSERT INTO USER_PERM (UID, FORUM, FID, PERM) ";
        $sql .= "VALUES ('$uid', '$forum_fid', '$fid', '$grant') ";
        $sql .= "ON DUPLICATE KEY UPDATE PERM = PERM | VALUES(PERM)";

        $db->query($sql);
    }

    return true;
}

/**
 * Resolve (creating if necessary) the moderator thread that collects tag
 * requests. Returns its thread id.
 *
 * @return mixed thread id on success, false on failure
 */
function tag_get_moderator_thread_tid()
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $tid = forum_get_setting('tag_request_tid');

    if (is_numeric($tid)) {

        // Direct lookup - thread_get() is filtered to folders the current
        // user can read, and members cannot see the moderator folder.
        $sql = "SELECT TID FROM `{$table_prefix}THREAD` WHERE TID = '" . intval($tid) . "' AND DELETED = 'N'";

        if (($result = $db->query($sql)) && $result->num_rows > 0) {
            return intval($tid);
        }
    }

    if (($fid = tag_get_moderator_folder_fid()) === false) return false;

    $owner_uid = 1;

    if (($forum_settings = forum_get_settings()) && isset($forum_settings['owner_uid']) && is_numeric($forum_settings['owner_uid'])) {
        $owner_uid = intval($forum_settings['owner_uid']);
    }

    if (($tid = post_create_thread($fid, $owner_uid, gettext("moderator tag requests"))) === false) {
        return false;
    }

    $intro = gettext("New tag requests from members are posted to this thread. Approve or reject them from Admin &rsaquo; Tag Approval Queue.");

    post_create($fid, $tid, 0, $owner_uid, array(), $intro);

    forum_save_settings(array('tag_request_tid' => $tid));

    return intval($tid);
}

/**
 * Post a tag-request notice to the moderator thread.
 *
 * @param int    $tagid
 * @param string $tag        normalised tag name
 * @param int    $source_tid comment's thread id
 * @param int    $source_pid comment's post id
 * @param int    $uid        requesting user
 * @return bool
 */
function tag_post_moderator_request($tagid, $tag, $source_tid, $source_pid, $uid)
{
    if (($mod_tid = tag_get_moderator_thread_tid()) === false) return false;

    if (!($mod_fid = thread_get_folder_fid($mod_tid))) return false;

    $webtag = get_webtag();

    $requester = gettext("A member");

    if (($user = user_get($uid)) !== false) {
        $requester = format_user_name($user['LOGON'], $user['NICKNAME']);
    }

    $thread_title = thread_get_title($source_tid);

    if ($thread_title === false) $thread_title = gettext("(unknown thread)");

    $comment_uri = htmlentities_array("messages.php?webtag=$webtag&msg=$source_tid.$source_pid");
    $queue_uri = htmlentities_array("admin_tag_approve.php?webtag=$webtag");

    $content  = sprintf(gettext("%s requested the tag \"%s\"."), htmlentities_array($requester), htmlentities_array($tag)) . "<br />";
    $content .= sprintf(gettext("On: %s"), "<a href=\"$comment_uri\" target=\"_self\">" . gettext("this comment") . "</a>") . "<br />";
    $content .= sprintf(gettext("In thread: %s"), htmlentities_array($thread_title)) . "<br />";
    $content .= "<a href=\"$queue_uri\" target=\"_self\">" . gettext("Review in the Tag Approval Queue") . "</a>";

    $owner_uid = 1;

    if (($forum_settings = forum_get_settings()) && isset($forum_settings['owner_uid']) && is_numeric($forum_settings['owner_uid'])) {
        $owner_uid = intval($forum_settings['owner_uid']);
    }

    return (post_create($mod_fid, $mod_tid, 0, $owner_uid, array(), $content) !== false);
}

/**
 * Tag approval queue (pending tags) for the admin page.
 *
 * @param int $page
 * @param int $per_page
 * @return array ['tag_count' => int, 'tag_array' => array]
 */
function tag_get_approval_queue($page = 1, $per_page = 20)
{
    if (!$db = db::get()) return array('tag_count' => 0, 'tag_array' => array());

    if (!($table_prefix = get_table_prefix())) return array('tag_count' => 0, 'tag_array' => array());

    if (!is_numeric($page) || $page < 1) $page = 1;

    $offset = ($page - 1) * $per_page;

    $sql = "SELECT SQL_CALC_FOUND_ROWS TAG.TID AS TAGID, TAG.TAG, TAG.SOURCE_TID, TAG.SOURCE_PID, ";
    $sql .= "UNIX_TIMESTAMP(TAG.CREATED) AS CREATED, USER.UID, USER.LOGON, USER.NICKNAME, ";
    $sql .= "THREAD.TITLE AS THREAD_TITLE ";
    $sql .= "FROM `{$table_prefix}TAG` TAG ";
    $sql .= "LEFT JOIN USER ON (USER.UID = TAG.CREATED_BY) ";
    $sql .= "LEFT JOIN `{$table_prefix}THREAD` THREAD ON (THREAD.TID = TAG.SOURCE_TID) ";
    $sql .= "WHERE TAG.APPROVED IS NULL ";
    $sql .= "ORDER BY TAG.CREATED ASC ";
    $sql .= "LIMIT $offset, " . intval($per_page);

    if (!($result = $db->query($sql))) return array('tag_count' => 0, 'tag_array' => array());

    $tag_array = array();

    while (($row = $result->fetch_assoc()) !== null) {
        $tag_array[] = $row;
    }

    $count_result = $db->query("SELECT FOUND_ROWS()");
    list($tag_count) = $count_result->fetch_row();

    return array('tag_count' => $tag_count, 'tag_array' => $tag_array);
}

/**
 * Approved tags for the admin page.
 *
 * @param int $page
 * @param int $per_page
 * @return array ['tag_count' => int, 'tag_array' => array]
 */
function tag_get_approved($page = 1, $per_page = 50)
{
    if (!$db = db::get()) return array('tag_count' => 0, 'tag_array' => array());

    if (!($table_prefix = get_table_prefix())) return array('tag_count' => 0, 'tag_array' => array());

    if (!is_numeric($page) || $page < 1) $page = 1;

    $offset = ($page - 1) * $per_page;

    $sql = "SELECT SQL_CALC_FOUND_ROWS TAG.TID AS TAGID, TAG.TAG, ";
    $sql .= "UNIX_TIMESTAMP(TAG.APPROVED) AS APPROVED, USER.LOGON, USER.NICKNAME, ";
    $sql .= "(SELECT COUNT(*) FROM `{$table_prefix}POST_TAG` WHERE `{$table_prefix}POST_TAG`.TAG = TAG.TID) AS USE_COUNT ";
    $sql .= "FROM `{$table_prefix}TAG` TAG ";
    $sql .= "LEFT JOIN USER ON (USER.UID = TAG.APPROVED_BY) ";
    $sql .= "WHERE TAG.APPROVED IS NOT NULL ";
    $sql .= "ORDER BY TAG.TAG ASC ";
    $sql .= "LIMIT $offset, " . intval($per_page);

    if (!($result = $db->query($sql))) return array('tag_count' => 0, 'tag_array' => array());

    $tag_array = array();

    while (($row = $result->fetch_assoc()) !== null) {
        $tag_array[] = $row;
    }

    $count_result = $db->query("SELECT FOUND_ROWS()");
    list($tag_count) = $count_result->fetch_row();

    return array('tag_count' => $tag_count, 'tag_array' => $tag_array);
}
