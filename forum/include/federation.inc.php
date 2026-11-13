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

/**
 * WoodBees instance federation: export / import / redaction of threads,
 * comments and tags between cooperating instances. See docs/federation.md
 * for the bundle format and the trust model.
 */

// Required includes
require_once BH_INCLUDE_PATH . 'constants.inc.php';
require_once BH_INCLUDE_PATH . 'db.inc.php';
require_once BH_INCLUDE_PATH . 'fixhtml.inc.php';
require_once BH_INCLUDE_PATH . 'folder.inc.php';
require_once BH_INCLUDE_PATH . 'forum.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'instance_key.inc.php';
require_once BH_INCLUDE_PATH . 'post.inc.php';
require_once BH_INCLUDE_PATH . 'tag.inc.php';
require_once BH_INCLUDE_PATH . 'thread.inc.php';
require_once BH_INCLUDE_PATH . 'user.inc.php';
// End Required includes

define('FEDERATION_FORMAT', 'woodbees-bundle');
define('FEDERATION_FORMAT_VERSION', 1);

// Hard cap on an uploaded bundle (bytes); PHP's upload limits apply too.
define('FEDERATION_MAX_BUNDLE_BYTES', 64 * 1024 * 1024);

// Trust states of a verified bundle (federation_bundle_check()).
define('FEDERATION_TRUST_KNOWN', 'known');             // signed, key matches a trusted pin
define('FEDERATION_TRUST_FIRST_SEEN', 'first_seen');   // signed, identifier never seen - admin must confirm fingerprint
define('FEDERATION_TRUST_UNSIGNED', 'unsigned');       // no signature, identifier unknown - admin must opt in

define('FEDERATION_TRUSTED', 'TRUSTED');
define('FEDERATION_UNTRUSTED', 'UNTRUSTED');

/* ------------------------------------------------------------------ */
/* Helpers                                                            */
/* ------------------------------------------------------------------ */

/**
 * Case-insensitive comparison of two instance identifiers (normalised).
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function federation_same_instance($a, $b)
{
    $a = mb_strtolower(instance_identifier_normalise($a), 'UTF-8');
    $b = mb_strtolower(instance_identifier_normalise($b), 'UTF-8');

    return strlen($a) > 0 && $a === $b;
}

/**
 * Plain-text single-line value: strip tags, control / format characters,
 * fold whitespace, cap length. Used for author names and refs from bundles.
 *
 * @param mixed $value
 * @param int   $max_length characters
 * @return string
 */
function federation_clean_text($value, $max_length)
{
    if (!is_string($value) && !is_numeric($value)) return '';

    $value = strip_tags(strval($value));

    if (!mb_check_encoding($value, 'UTF-8')) return '';

    $value = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\p{Zs}]+/u', ' ', $value);
    $value = preg_replace('/\p{Cf}/u', '', $value);
    $value = trim(preg_replace('/ +/', ' ', $value));

    if (mb_strlen($value, 'UTF-8') > $max_length) {
        $value = rtrim(mb_substr($value, 0, $max_length, 'UTF-8'));
    }

    return $value;
}

/**
 * Normalised instance identifier from a bundle, with any markup removed so a
 * hostile bundle cannot smuggle HTML in through an origin label.
 *
 * @param mixed $value
 * @return string '' when unusable
 */
function federation_clean_origin($value)
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return '';

    return instance_identifier_normalise(str_replace(array('<', '>'), '', strip_tags($value)));
}

/**
 * ISO 8601 (UTC) -> MySQL DATETIME in PHP's timezone, clamped to now.
 *
 * @param mixed $value
 * @return string
 */
function federation_parse_datetime($value)
{
    $now = time();

    $timestamp = (is_string($value) && strlen($value) > 0) ? strtotime($value) : false;

    if ($timestamp === false || $timestamp < 0 || $timestamp > $now) $timestamp = $now;

    return date(MYSQL_DATETIME, $timestamp);
}

/**
 * Local admin user id doing the work (session), or 0.
 *
 * @return int
 */
function federation_current_uid()
{
    return (isset($_SESSION['UID']) && is_numeric($_SESSION['UID'])) ? intval($_SESSION['UID']) : 0;
}

/* ------------------------------------------------------------------ */
/* Placeholder authors                                                */
/* ------------------------------------------------------------------ */

/**
 * LOGON of the placeholder user for an origin instance. Deterministic, so
 * the same origin always maps to the same account without a lookup table.
 *
 * @param string $origin
 * @return string
 */
function federation_placeholder_logon($origin)
{
    return USER_PLACEHOLDER_LOGON_PREFIX . strtoupper(substr(sha1(instance_identifier_normalise($origin)), 0, 12));
}

/**
 * UID of the non-login placeholder user that owns comments imported from
 * $origin, created on first use. One placeholder per origin instance.
 *
 * The account has PASSWD = USER_PLACEHOLDER_PASSWD (matches no hash, and
 * user_logon() refuses it outright), no forum access rows and no email, and
 * is filtered out of member lists. The real author's name is stored as text
 * on each comment (POST.ORIGIN_AUTHOR).
 *
 * @param string $origin
 * @return int|false
 */
function federation_placeholder_uid($origin)
{
    static $cache = array();

    $origin = instance_identifier_normalise($origin);

    if (strlen($origin) == 0) return false;

    if (isset($cache[$origin])) return $cache[$origin];

    if (!$db = db::get()) return false;

    $logon = federation_placeholder_logon($origin);

    $logon_escaped = $db->escape($logon);

    $sql = "SELECT UID, PASSWD FROM USER WHERE LOGON = '$logon_escaped'";

    if (!($result = $db->query($sql))) return false;

    if ($result->num_rows > 0) {

        list($uid, $passhash) = $result->fetch_row();

        // A real account holding the reserved name: refuse rather than
        // attribute imported content to it.
        if ($passhash !== USER_PLACEHOLDER_PASSWD) return false;

        return $cache[$origin] = intval($uid);
    }

    $nickname = $db->escape(mb_substr($origin, 0, 32, 'UTF-8'));

    $current_datetime = date(MYSQL_DATETIME, time());

    $passwd = USER_PLACEHOLDER_PASSWD;

    $sql = "INSERT INTO USER (LOGON, PASSWD, SALT, NICKNAME, EMAIL, REGISTERED, IPADDRESS, REFERER, APPROVED) ";
    $sql .= "VALUES ('$logon_escaped', '$passwd', '', '$nickname', '', CAST('$current_datetime' AS DATETIME), ";
    $sql .= "'', NULL, CAST('$current_datetime' AS DATETIME))";

    if (!$db->query($sql)) return false;

    return $cache[$origin] = intval($db->insert_id);
}

/* ------------------------------------------------------------------ */
/* Trusted instances (TOFU)                                           */
/* ------------------------------------------------------------------ */

/**
 * @param string $identifier
 * @return array|false TRUSTED_INSTANCE row
 */
function federation_trust_get($identifier)
{
    if (!$db = db::get()) return false;

    $identifier = $db->escape(instance_identifier_normalise($identifier));

    $sql = "SELECT IID, IDENTIFIER, PUBLIC_KEY, STATUS, UNIX_TIMESTAMP(FIRST_SEEN) AS FIRST_SEEN, ";
    $sql .= "UNIX_TIMESTAMP(LAST_SEEN) AS LAST_SEEN, CONFIRMED_BY FROM TRUSTED_INSTANCE ";
    $sql .= "WHERE IDENTIFIER = '$identifier'";

    if (!($result = $db->query($sql)) || $result->num_rows == 0) return false;

    return $result->fetch_assoc();
}

/**
 * Identifiers already pinned to this public key (other than $except).
 *
 * @param string $public_key_b64
 * @param string $except
 * @return array
 */
function federation_trust_identifiers_for_key($public_key_b64, $except = '')
{
    if (!$db = db::get()) return array();

    $public_key_b64 = $db->escape($public_key_b64);
    $except = $db->escape(instance_identifier_normalise($except));

    $sql = "SELECT IDENTIFIER FROM TRUSTED_INSTANCE WHERE PUBLIC_KEY = '$public_key_b64' AND IDENTIFIER <> '$except'";

    if (!($result = $db->query($sql))) return array();

    $identifiers = array();

    while (($row = $result->fetch_row()) !== null) $identifiers[] = $row[0];

    return $identifiers;
}

/**
 * @return array all TRUSTED_INSTANCE rows
 */
function federation_trust_list()
{
    if (!$db = db::get()) return array();

    $sql = "SELECT TRUSTED_INSTANCE.IID, TRUSTED_INSTANCE.IDENTIFIER, TRUSTED_INSTANCE.PUBLIC_KEY, ";
    $sql .= "TRUSTED_INSTANCE.STATUS, UNIX_TIMESTAMP(TRUSTED_INSTANCE.FIRST_SEEN) AS FIRST_SEEN, ";
    $sql .= "UNIX_TIMESTAMP(TRUSTED_INSTANCE.LAST_SEEN) AS LAST_SEEN, USER.LOGON, USER.NICKNAME ";
    $sql .= "FROM TRUSTED_INSTANCE LEFT JOIN USER ON (USER.UID = TRUSTED_INSTANCE.CONFIRMED_BY) ";
    $sql .= "ORDER BY TRUSTED_INSTANCE.IDENTIFIER";

    if (!($result = $db->query($sql))) return array();

    $rows = array();

    while (($row = $result->fetch_assoc()) !== null) $rows[] = $row;

    return $rows;
}

/**
 * Pin a public key for an identifier (first-seen confirmation).
 *
 * @param string $identifier
 * @param string $public_key_b64
 * @param int    $uid confirming admin
 * @return bool
 */
function federation_trust_add($identifier, $public_key_b64, $uid)
{
    if (!$db = db::get()) return false;

    $identifier = $db->escape(instance_identifier_normalise($identifier));
    $public_key_b64 = $db->escape($public_key_b64);
    $uid = intval($uid);

    $current_datetime = date(MYSQL_DATETIME, time());

    $sql = "INSERT IGNORE INTO TRUSTED_INSTANCE (IDENTIFIER, PUBLIC_KEY, STATUS, FIRST_SEEN, LAST_SEEN, CONFIRMED_BY) ";
    $sql .= "VALUES ('$identifier', '$public_key_b64', '" . FEDERATION_TRUSTED . "', ";
    $sql .= "CAST('$current_datetime' AS DATETIME), CAST('$current_datetime' AS DATETIME), $uid)";

    if (!$db->query($sql)) return false;

    return $db->affected_rows > 0;
}

/**
 * @param string $identifier
 * @return bool
 */
function federation_trust_touch($identifier)
{
    if (!$db = db::get()) return false;

    $identifier = $db->escape(instance_identifier_normalise($identifier));

    $current_datetime = date(MYSQL_DATETIME, time());

    return (bool) $db->query("UPDATE TRUSTED_INSTANCE SET LAST_SEEN = CAST('$current_datetime' AS DATETIME) WHERE IDENTIFIER = '$identifier'");
}

/**
 * Set a pinned instance's status (FEDERATION_TRUSTED / FEDERATION_UNTRUSTED).
 * Untrusting is the alpha's revocation: its bundles are refused until an
 * admin re-trusts or forgets it. The pinned key is kept either way.
 *
 * @param int    $iid
 * @param string $status
 * @return bool
 */
function federation_trust_set_status($iid, $status)
{
    if (!is_numeric($iid)) return false;

    if (!in_array($status, array(FEDERATION_TRUSTED, FEDERATION_UNTRUSTED), true)) return false;

    if (!$db = db::get()) return false;

    return (bool) $db->query("UPDATE TRUSTED_INSTANCE SET STATUS = '$status' WHERE IID = '" . intval($iid) . "'");
}

/**
 * Forget a pinned key entirely (the next bundle is first-seen again). Use
 * when a peer legitimately lost or rotated its key.
 *
 * @param int $iid
 * @return bool
 */
function federation_trust_forget($iid)
{
    if (!is_numeric($iid)) return false;

    if (!$db = db::get()) return false;

    return (bool) $db->query("DELETE FROM TRUSTED_INSTANCE WHERE IID = '" . intval($iid) . "'");
}

/**
 * @param int $iid
 * @return array|false
 */
function federation_trust_get_by_id($iid)
{
    if (!is_numeric($iid)) return false;

    if (!$db = db::get()) return false;

    $sql = "SELECT IID, IDENTIFIER, PUBLIC_KEY, STATUS FROM TRUSTED_INSTANCE WHERE IID = '" . intval($iid) . "'";

    if (!($result = $db->query($sql)) || $result->num_rows == 0) return false;

    return $result->fetch_assoc();
}

/* ------------------------------------------------------------------ */
/* Export                                                             */
/* ------------------------------------------------------------------ */

/**
 * The identifier and ref a comment is exported under. Comments born here
 * get this instance's identifier and "tid.pid"; imported comments keep the
 * origin and ref they arrived with, so provenance survives re-sharing.
 *
 * @param array  $post        row with TID, PID, ORIGIN_INSTANCE, ORIGIN_REF
 * @param string $local_identifier
 * @return array [origin, ref]
 */
function federation_post_origin(array $post, $local_identifier)
{
    if (isset($post['ORIGIN_INSTANCE']) && strlen($post['ORIGIN_INSTANCE']) > 0 && isset($post['ORIGIN_REF']) && strlen($post['ORIGIN_REF']) > 0) {
        return array($post['ORIGIN_INSTANCE'], $post['ORIGIN_REF']);
    }

    return array($local_identifier, $post['TID'] . '.' . $post['PID']);
}

/**
 * Build the payload of an export bundle.
 *
 * @param array    $fids        folder ids to export (required, non-empty)
 * @param array    $tids        optional thread ids to restrict to
 * @param int|null $from_ts     optional: only comments created at/after
 * @param int|null $to_ts       optional: only comments created at/before
 * @param string   $error_str
 * @return array|false payload array (without envelope)
 */
function federation_export_build(array $fids, array $tids, $from_ts, $to_ts, &$error_str = null)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $local_identifier = instance_identifier_get();

    if (strlen($local_identifier) == 0) {
        $error_str = gettext("This instance has no instance identifier. Set one by running the installer's upgrade before exporting.");
        return false;
    }

    $fids = array_values(array_unique(array_map('intval', array_filter($fids, 'is_numeric'))));
    $tids = array_values(array_unique(array_map('intval', array_filter($tids, 'is_numeric'))));

    if (sizeof($fids) == 0) {
        $error_str = gettext("Select at least one folder to export.");
        return false;
    }

    $sql = "SELECT TID, TITLE, UNIX_TIMESTAMP(CREATED) AS CREATED, ORIGIN_INSTANCE ";
    $sql .= "FROM `{$table_prefix}THREAD` WHERE FID IN (" . implode(',', $fids) . ") ";
    $sql .= "AND DELETED = 'N' AND APPROVED IS NOT NULL AND LENGTH > 0 ";

    if (sizeof($tids) > 0) $sql .= "AND TID IN (" . implode(',', $tids) . ") ";

    $sql .= "ORDER BY TID";

    if (!($thread_result = $db->query($sql))) return false;

    $threads = array();

    $post_count = 0;

    while (($thread = $thread_result->fetch_assoc()) !== null) {

        $tid = intval($thread['TID']);

        // Origin/ref of every comment in the thread, so replies to comments
        // outside the date range still resolve to a stable reference.
        $sql = "SELECT TID, PID, ORIGIN_INSTANCE, ORIGIN_REF FROM `{$table_prefix}POST` WHERE TID = '$tid'";

        if (!($ref_result = $db->query($sql))) return false;

        $ref_map = array();

        while (($ref_row = $ref_result->fetch_assoc()) !== null) {
            $ref_map[$ref_row['PID']] = federation_post_origin($ref_row, $local_identifier);
        }

        $sql = "SELECT POST.TID, POST.PID, POST.REPLY_TO_PID, UNIX_TIMESTAMP(POST.CREATED) AS CREATED, ";
        $sql .= "POST.ORIGIN_INSTANCE, POST.ORIGIN_AUTHOR, POST.ORIGIN_REF, USER.LOGON, USER.NICKNAME, ";
        $sql .= "POST_CONTENT.CONTENT FROM `{$table_prefix}POST` POST ";
        $sql .= "INNER JOIN `{$table_prefix}POST_CONTENT` POST_CONTENT ON (POST_CONTENT.TID = POST.TID AND POST_CONTENT.PID = POST.PID) ";
        $sql .= "LEFT JOIN USER ON (USER.UID = POST.FROM_UID) ";
        $sql .= "WHERE POST.TID = '$tid' AND POST.APPROVED IS NOT NULL ";
        $sql .= "AND POST_CONTENT.CONTENT IS NOT NULL AND POST_CONTENT.CONTENT <> '' ";

        if (is_numeric($from_ts)) $sql .= "AND POST.CREATED >= CAST('" . date(MYSQL_DATETIME, $from_ts) . "' AS DATETIME) ";
        if (is_numeric($to_ts)) $sql .= "AND POST.CREATED <= CAST('" . date(MYSQL_DATETIME, $to_ts) . "' AS DATETIME) ";

        $sql .= "ORDER BY POST.PID";

        if (!($post_result = $db->query($sql))) return false;

        $posts = array();

        while (($post = $post_result->fetch_assoc()) !== null) {

            list($origin, $ref) = $ref_map[$post['PID']];

            if (isset($post['ORIGIN_AUTHOR']) && strlen($post['ORIGIN_AUTHOR']) > 0) {
                $author = $post['ORIGIN_AUTHOR'];
            } else if (isset($post['NICKNAME']) && strlen(trim($post['NICKNAME'])) > 0) {
                $author = $post['NICKNAME'];
            } else if (isset($post['LOGON'])) {
                $author = $post['LOGON'];
            } else {
                $author = '';
            }

            $reply_to = null;

            if ($post['REPLY_TO_PID'] > 0 && isset($ref_map[$post['REPLY_TO_PID']])) {
                $reply_to = array(
                    'origin' => $ref_map[$post['REPLY_TO_PID']][0],
                    'ref' => $ref_map[$post['REPLY_TO_PID']][1],
                );
            }

            // Approved tags only - pending requests are a local matter.
            $sql = "SELECT TAG.TAG FROM `{$table_prefix}POST_TAG` POST_TAG ";
            $sql .= "INNER JOIN `{$table_prefix}TAG` TAG ON (TAG.TID = POST_TAG.TAG) ";
            $sql .= "WHERE POST_TAG.TID = '$tid' AND POST_TAG.PID = '{$post['PID']}' AND TAG.APPROVED IS NOT NULL ";
            $sql .= "ORDER BY TAG.TAG";

            $tags = array();

            if (($tag_result = $db->query($sql))) {
                while (($tag_row = $tag_result->fetch_row()) !== null) $tags[] = $tag_row[0];
            }

            $posts[] = array(
                'origin' => $origin,
                'ref' => $ref,
                'reply_to' => $reply_to,
                'author' => $author,
                'created' => gmdate('Y-m-d\TH:i:s\Z', $post['CREATED']),
                'content' => $post['CONTENT'],
                'tags' => $tags,
            );
        }

        if (sizeof($posts) == 0) continue;

        $post_count += sizeof($posts);

        $threads[] = array(
            'origin' => (isset($thread['ORIGIN_INSTANCE']) && strlen($thread['ORIGIN_INSTANCE']) > 0) ? $thread['ORIGIN_INSTANCE'] : $local_identifier,
            'address' => $thread['TITLE'],
            'created' => gmdate('Y-m-d\TH:i:s\Z', $thread['CREATED']),
            'posts' => $posts,
        );
    }

    if (sizeof($threads) == 0) {
        $error_str = gettext("Nothing to export: no approved comments matched the selection.");
        return false;
    }

    return array(
        'format' => FEDERATION_FORMAT,
        'format_version' => FEDERATION_FORMAT_VERSION,
        'bundle_id' => bin2hex(random_bytes(16)),
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'origin' => $local_identifier,
        'thread_count' => sizeof($threads),
        'post_count' => $post_count,
        'threads' => $threads,
    );
}

/**
 * Wrap a payload in the bundle envelope and sign it.
 *
 * The payload is serialised ONCE to a string and the signature covers that
 * exact string, so no JSON canonicalisation is needed on either side. The
 * envelope repeats bundle_id / exported_at / origin for readability; the
 * importer checks they match the signed payload.
 *
 * @param array  $payload
 * @param bool   $sign       false = deliberately unsigned bundle
 * @param string $error_str
 * @return string|false bundle JSON
 */
function federation_bundle_encode(array $payload, $sign, &$error_str = null)
{
    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload_json === false) {
        $error_str = gettext("Could not encode the export bundle.");
        return false;
    }

    $public_key = null;
    $signature = null;

    if ($sign) {

        if (!instance_key_ensure($key_error)) {
            $error_str = sprintf(gettext("This instance has no usable signing key: %s"), $key_error);
            return false;
        }

        $public_key = base64_encode(instance_key_public());
        $signature = base64_encode(instance_key_sign($payload_json));
    }

    $envelope = array(
        'format' => FEDERATION_FORMAT,
        'format_version' => FEDERATION_FORMAT_VERSION,
        'bundle_id' => $payload['bundle_id'],
        'exported_at' => $payload['exported_at'],
        'origin' => $payload['origin'],
        'public_key' => $public_key,
        'payload' => $payload_json,
        'signature' => $signature,
    );

    $bundle = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if ($bundle === false) {
        $error_str = gettext("Could not encode the export bundle.");
        return false;
    }

    return $bundle;
}

/* ------------------------------------------------------------------ */
/* Import: parse + verify                                             */
/* ------------------------------------------------------------------ */

/**
 * Parse and verify a bundle. Rejections (returns false with $error_str):
 * malformed bundle; envelope/payload mismatch; bundle claiming to be from
 * this instance; bad signature; signed with a key other than the one pinned
 * for that identifier; identifier marked untrusted; unsigned bundle claiming
 * an identifier that has a pinned key; already-imported bundle_id.
 *
 * On success returns an array:
 *   'payload'     => decoded payload
 *   'origin'      => bundle origin identifier
 *   'trust'       => FEDERATION_TRUST_KNOWN | _FIRST_SEEN | _UNSIGNED
 *   'public_key'  => base64 public key (null when unsigned)
 *   'fingerprint' => key fingerprint (null when unsigned)
 *   'other_identifiers' => identifiers already pinned to this key
 *   'thread_count', 'post_count', 'own_post_count'
 *
 * @param string $bundle_json
 * @param string $error_str
 * @return array|false
 */
function federation_bundle_check($bundle_json, &$error_str = null)
{
    if (!is_string($bundle_json) || strlen($bundle_json) == 0) {
        $error_str = gettext("The uploaded file is empty.");
        return false;
    }

    if (strlen($bundle_json) > FEDERATION_MAX_BUNDLE_BYTES) {
        $error_str = gettext("The uploaded bundle is too large.");
        return false;
    }

    $envelope = json_decode($bundle_json, true);

    if (!is_array($envelope) || !isset($envelope['format']) || $envelope['format'] !== FEDERATION_FORMAT) {
        $error_str = gettext("This is not a WoodBees federation bundle.");
        return false;
    }

    if (!isset($envelope['format_version']) || intval($envelope['format_version']) !== FEDERATION_FORMAT_VERSION) {
        $error_str = gettext("Unsupported bundle format version.");
        return false;
    }

    if (!isset($envelope['payload']) || !is_string($envelope['payload'])) {
        $error_str = gettext("The bundle has no payload.");
        return false;
    }

    $payload = json_decode($envelope['payload'], true);

    if (!is_array($payload) || !isset($payload['threads']) || !is_array($payload['threads'])) {
        $error_str = gettext("The bundle payload is malformed.");
        return false;
    }

    // The signed payload is authoritative; the envelope copies must agree.
    foreach (array('bundle_id', 'exported_at', 'origin') as $field) {

        if (!isset($payload[$field]) || !is_string($payload[$field]) || !isset($envelope[$field]) || $envelope[$field] !== $payload[$field]) {
            $error_str = gettext("The bundle envelope does not match its payload.");
            return false;
        }
    }

    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $payload['bundle_id'])) {
        $error_str = gettext("The bundle has an invalid bundle_id.");
        return false;
    }

    $origin = federation_clean_origin($payload['origin']);

    if (strlen($origin) == 0 || $origin !== $payload['origin']) {
        $error_str = gettext("The bundle has an invalid origin identifier.");
        return false;
    }

    $local_identifier = instance_identifier_get();

    if (federation_same_instance($origin, $local_identifier)) {
        $error_str = gettext("This bundle claims to come from this instance. Importing your own export is refused.");
        return false;
    }

    $signed = isset($envelope['signature']) && is_string($envelope['signature']) && strlen($envelope['signature']) > 0;

    $pinned = federation_trust_get($origin);

    $public_key_b64 = null;
    $fingerprint = null;
    $other_identifiers = array();

    if ($signed) {

        $public_key = (isset($envelope['public_key']) && is_string($envelope['public_key'])) ? base64_decode($envelope['public_key'], true) : false;
        $signature = base64_decode($envelope['signature'], true);

        if (!is_string($public_key) || strlen($public_key) !== 32 || !is_string($signature)) {
            $error_str = gettext("The bundle's public key or signature is malformed.");
            return false;
        }

        if (!instance_key_sodium_available()) {
            $error_str = gettext("This server cannot verify signatures (PHP sodium extension missing).");
            return false;
        }

        if (!instance_key_verify($signature, $envelope['payload'], $public_key)) {
            $error_str = gettext("BAD SIGNATURE: the bundle has been altered or was not signed by the key it carries. Import refused.");
            return false;
        }

        $public_key_b64 = base64_encode($public_key);
        $fingerprint = instance_key_fingerprint($public_key);

        if (($own_key = instance_key_public()) !== false && hash_equals($own_key, $public_key)) {
            $error_str = gettext("This bundle is signed with this instance's own key. Importing your own export is refused.");
            return false;
        }

        if ($pinned !== false) {

            if (!hash_equals($pinned['PUBLIC_KEY'], $public_key_b64)) {
                $error_str = sprintf(gettext("KEY MISMATCH: \"%s\" is already known with a different key (%s) than this bundle's (%s). Import refused. If that instance really changed its key, confirm with its admin, then use \"Forget\" on the trusted instances list and import again."), $origin, instance_key_fingerprint(base64_decode($pinned['PUBLIC_KEY'])), $fingerprint);
                return false;
            }

            if ($pinned['STATUS'] !== FEDERATION_TRUSTED) {
                $error_str = sprintf(gettext("\"%s\" has been marked untrusted on this instance. Import refused."), $origin);
                return false;
            }

            $trust = FEDERATION_TRUST_KNOWN;

        } else {

            $trust = FEDERATION_TRUST_FIRST_SEEN;
            $other_identifiers = federation_trust_identifiers_for_key($public_key_b64, $origin);
        }

    } else {

        if ($pinned !== false) {
            $error_str = sprintf(gettext("This bundle is UNSIGNED but claims to come from \"%s\", whose signing key is pinned on this instance. Import refused."), $origin);
            return false;
        }

        $trust = FEDERATION_TRUST_UNSIGNED;
    }

    if (federation_bundle_imported($payload['bundle_id'])) {
        $error_str = gettext("This bundle has already been imported into this forum.");
        return false;
    }

    $thread_count = 0;
    $post_count = 0;
    $own_post_count = 0;

    foreach ($payload['threads'] as $thread) {

        if (!is_array($thread) || !isset($thread['posts']) || !is_array($thread['posts'])) continue;

        $thread_count++;

        foreach ($thread['posts'] as $post) {

            if (!is_array($post)) continue;

            $post_count++;

            if (isset($post['origin']) && federation_same_instance($post['origin'], $local_identifier)) $own_post_count++;
        }
    }

    return array(
        'payload' => $payload,
        'origin' => $origin,
        'trust' => $trust,
        'public_key' => $public_key_b64,
        'fingerprint' => $fingerprint,
        'other_identifiers' => $other_identifiers,
        'thread_count' => $thread_count,
        'post_count' => $post_count,
        'own_post_count' => $own_post_count,
    );
}

/**
 * @param string $bundle_id
 * @return bool
 */
function federation_bundle_imported($bundle_id)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $bundle_id = $db->escape($bundle_id);

    $sql = "SELECT BUNDLE_ID FROM `{$table_prefix}IMPORT_LOG` WHERE BUNDLE_ID = '$bundle_id'";

    return ($result = $db->query($sql)) && $result->num_rows > 0;
}

/**
 * @param int $limit
 * @return array recent IMPORT_LOG rows
 */
function federation_import_log($limit = 20)
{
    if (!$db = db::get()) return array();

    if (!($table_prefix = get_table_prefix())) return array();

    $sql = "SELECT IMPORT_LOG.BUNDLE_ID, IMPORT_LOG.ORIGIN_INSTANCE, IMPORT_LOG.PUBLIC_KEY, ";
    $sql .= "UNIX_TIMESTAMP(IMPORT_LOG.IMPORTED) AS IMPORTED, IMPORT_LOG.THREAD_COUNT, ";
    $sql .= "IMPORT_LOG.POST_COUNT, IMPORT_LOG.SKIPPED_COUNT, USER.LOGON, USER.NICKNAME ";
    $sql .= "FROM `{$table_prefix}IMPORT_LOG` IMPORT_LOG LEFT JOIN USER ON (USER.UID = IMPORT_LOG.IMPORTED_BY) ";
    $sql .= "ORDER BY IMPORT_LOG.IMPORTED DESC LIMIT " . intval($limit);

    if (!($result = $db->query($sql))) return array();

    $rows = array();

    while (($row = $result->fetch_assoc()) !== null) $rows[] = $row;

    return $rows;
}

/* ------------------------------------------------------------------ */
/* Import: write                                                      */
/* ------------------------------------------------------------------ */

/**
 * Local PID of a comment already imported into $tid with origin/ref, or false.
 *
 * @param int    $tid   0 = any thread
 * @param string $origin
 * @param string $ref
 * @return int|false
 */
function federation_find_post($tid, $origin, $ref)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $origin = $db->escape($origin);
    $ref = $db->escape($ref);

    $sql = "SELECT TID, PID FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE = '$origin' AND ORIGIN_REF = '$ref' ";

    if ($tid > 0) $sql .= "AND TID = '" . intval($tid) . "' ";

    $sql .= "LIMIT 1";

    if (!($result = $db->query($sql)) || $result->num_rows == 0) return false;

    $row = $result->fetch_assoc();

    return intval($row['PID']);
}

/**
 * Import a checked bundle (from federation_bundle_check()) into the forum.
 *
 * Per thread: the address is normalised; if a live thread with that address
 * exists the comments are APPENDED to it (its folder, title, tags and
 * moderation state win), otherwise a new thread is created in $target_fid.
 * Comments are appended in timestamp order with their original CREATED
 * time. A comment is skipped when its (origin, ref) already exists here
 * (double import / redacted tombstone) or when its origin is this instance.
 * Content is re-sanitised with the same HTML filter as local posts. Tags
 * already known here are linked as they are; new ones are created PENDING
 * in the tag moderation queue (TAG.ORIGIN_INSTANCE set) and one notice is
 * posted to the moderators' tag request thread.
 *
 * @param array  $checked     result of federation_bundle_check()
 * @param int    $target_fid  folder for new threads
 * @param string $error_str
 * @return array|false counts: threads_created, threads_merged, posts_imported, posts_skipped, tags_pending
 */
function federation_import(array $checked, $target_fid, &$error_str = null)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    if (!is_numeric($target_fid) || !folder_is_valid($target_fid)) {
        $error_str = gettext("Choose a valid target folder.");
        return false;
    }

    $target_fid = intval($target_fid);

    $payload = $checked['payload'];

    $bundle_origin = $checked['origin'];

    $local_identifier = instance_identifier_get();

    $admin_uid = federation_current_uid();

    $counts = array(
        'threads_created' => 0,
        'threads_merged' => 0,
        'posts_imported' => 0,
        'posts_skipped' => 0,
        'tags_pending' => 0,
    );

    $new_tags = array();

    // Claim the bundle id first so a concurrent second import of the same
    // bundle fails instead of racing (PRIMARY KEY on BUNDLE_ID).
    $bundle_id = $db->escape($payload['bundle_id']);
    $origin_escaped = $db->escape($bundle_origin);
    $public_key_sql = isset($checked['public_key']) ? "'" . $db->escape($checked['public_key']) . "'" : 'NULL';
    $current_datetime = date(MYSQL_DATETIME, time());

    $sql = "INSERT IGNORE INTO `{$table_prefix}IMPORT_LOG` (BUNDLE_ID, ORIGIN_INSTANCE, PUBLIC_KEY, IMPORTED, IMPORTED_BY) ";
    $sql .= "VALUES ('$bundle_id', '$origin_escaped', $public_key_sql, CAST('$current_datetime' AS DATETIME), $admin_uid)";

    if (!$db->query($sql) || $db->affected_rows < 1) {
        $error_str = gettext("This bundle has already been imported into this forum.");
        return false;
    }

    foreach ($payload['threads'] as $thread) {

        if (!is_array($thread) || !isset($thread['posts']) || !is_array($thread['posts'])) continue;

        $address = thread_title_normalise(isset($thread['address']) && is_string($thread['address']) ? $thread['address'] : '');

        $thread_origin = federation_clean_origin(isset($thread['origin']) ? $thread['origin'] : $bundle_origin);

        if (strlen($thread_origin) == 0) $thread_origin = $bundle_origin;

        // Validate + sanitise every comment before touching the thread, so a
        // thread whose comments are all duplicates is never created empty.
        $posts = array();

        foreach ($thread['posts'] as $post) {

            if (!is_array($post)) { $counts['posts_skipped']++; continue; }

            $post_origin = federation_clean_origin(isset($post['origin']) ? $post['origin'] : '');
            $post_ref = federation_clean_text(isset($post['ref']) ? $post['ref'] : '', 64);

            if (strlen($post_origin) == 0 || strlen($post_ref) == 0 || strlen($address) == 0) { $counts['posts_skipped']++; continue; }

            // Our own content coming back (or forged as ours): never import.
            if (federation_same_instance($post_origin, $local_identifier)) { $counts['posts_skipped']++; continue; }

            if (federation_find_post(0, $post_origin, $post_ref) !== false) { $counts['posts_skipped']++; continue; }

            $content = isset($post['content']) && is_string($post['content']) && mb_check_encoding($post['content'], 'UTF-8') ? fix_html($post['content']) : '';

            if (strlen(trim($content)) == 0) { $counts['posts_skipped']++; continue; }

            if (strlen($content) > 65535) $content = mb_strcut($content, 0, 65535, 'UTF-8');

            $author = federation_clean_text(isset($post['author']) ? $post['author'] : '', 64);

            if (strlen($author) == 0) $author = gettext("Unknown user");

            $reply_to = null;

            if (isset($post['reply_to']) && is_array($post['reply_to']) && isset($post['reply_to']['origin'], $post['reply_to']['ref'])) {
                $reply_to = array(
                    federation_clean_origin($post['reply_to']['origin']),
                    federation_clean_text($post['reply_to']['ref'], 64),
                );
            }

            $tags = array();

            if (isset($post['tags']) && is_array($post['tags'])) {

                foreach ($post['tags'] as $tag) {

                    if (!is_string($tag)) continue;

                    $tag = tag_normalise($tag);

                    if (strlen($tag) > 0) $tags[$tag] = $tag;
                }
            }

            $created = federation_parse_datetime(isset($post['created']) ? $post['created'] : null);

            $posts[] = array(
                'origin' => $post_origin,
                'ref' => $post_ref,
                'author' => $author,
                'content' => $content,
                'created' => $created,
                'reply_to' => $reply_to,
                'tags' => array_values($tags),
            );
        }

        if (sizeof($posts) == 0) continue;

        // Append in timestamp order (stable for equal times).
        $order = array_keys($posts);

        usort($order, function ($a, $b) use ($posts) {
            $cmp = strcmp($posts[$a]['created'], $posts[$b]['created']);
            return ($cmp != 0) ? $cmp : ($a - $b);
        });

        if (($tid = thread_get_tid_by_title($address)) !== false) {

            $tid = intval($tid);
            $counts['threads_merged']++;

        } else {

            if (($thread_uid = federation_placeholder_uid($thread_origin)) === false) {
                $error_str = sprintf(gettext("Could not create the placeholder author for \"%s\"."), $thread_origin);
                return federation_import_finish($payload['bundle_id'], $counts, $new_tags, $bundle_origin, $error_str);
            }

            $address_escaped = $db->escape($address);
            $thread_origin_escaped = $db->escape($thread_origin);
            $thread_created = federation_parse_datetime(isset($thread['created']) ? $thread['created'] : $posts[$order[0]]['created']);

            $sql = "INSERT INTO `{$table_prefix}THREAD` (FID, BY_UID, TITLE, LENGTH, POLL_FLAG, APPROVED, ";
            $sql .= "APPROVED_BY, STICKY, CREATED, MODIFIED, CLOSED, DELETED, ORIGIN_INSTANCE) VALUES ";
            $sql .= "('$target_fid', '$thread_uid', '$address_escaped', 0, 'N', CAST('$current_datetime' AS DATETIME), ";
            $sql .= "$admin_uid, 'N', CAST('$thread_created' AS DATETIME), CAST('$current_datetime' AS DATETIME), ";
            $sql .= "NULL, 'N', '$thread_origin_escaped')";

            if (!$db->query($sql)) {
                $error_str = gettext("Failed to create a thread.");
                return federation_import_finish($payload['bundle_id'], $counts, $new_tags, $bundle_origin, $error_str);
            }

            $tid = intval($db->insert_id);
            $counts['threads_created']++;
        }

        // origin|ref -> local pid for replies within this import.
        $pid_map = array();

        foreach ($order as $index) {

            $post = $posts[$index];

            if (($from_uid = federation_placeholder_uid($post['origin'])) === false) {
                $error_str = sprintf(gettext("Could not create the placeholder author for \"%s\"."), $post['origin']);
                return federation_import_finish($payload['bundle_id'], $counts, $new_tags, $bundle_origin, $error_str);
            }

            $reply_pid = 0;

            if (isset($post['reply_to'])) {

                list($reply_origin, $reply_ref) = $post['reply_to'];

                $reply_key = $reply_origin . '|' . $reply_ref;

                if (isset($pid_map[$reply_key])) {

                    $reply_pid = $pid_map[$reply_key];

                } else if (federation_same_instance($reply_origin, $local_identifier) && preg_match('/^(\d+)\.(\d+)$/D', $reply_ref, $matches) && intval($matches[1]) == $tid) {

                    // A reply (made elsewhere) to one of our own comments.
                    $reply_pid = intval($matches[2]);

                } else if (($found_pid = federation_find_post($tid, $reply_origin, $reply_ref)) !== false) {

                    $reply_pid = $found_pid;
                }
            }

            $origin_escaped = $db->escape($post['origin']);
            $author_escaped = $db->escape($post['author']);
            $ref_escaped = $db->escape($post['ref']);
            $content_escaped = $db->escape($post['content']);

            $sql = "INSERT INTO `{$table_prefix}POST` (TID, REPLY_TO_PID, FROM_UID, CREATED, APPROVED, APPROVED_BY, ";
            $sql .= "IPADDRESS, ORIGIN_INSTANCE, ORIGIN_AUTHOR, ORIGIN_REF) VALUES ('$tid', '$reply_pid', '$from_uid', ";
            $sql .= "CAST('{$post['created']}' AS DATETIME), CAST('$current_datetime' AS DATETIME), $admin_uid, '', ";
            $sql .= "'$origin_escaped', '$author_escaped', '$ref_escaped')";

            if (!$db->query($sql)) {
                $error_str = gettext("Failed to import a comment.");
                return federation_import_finish($payload['bundle_id'], $counts, $new_tags, $bundle_origin, $error_str);
            }

            $pid = intval($db->insert_id);

            $db->query("INSERT INTO `{$table_prefix}POST_CONTENT` (TID, PID, CONTENT) VALUES ('$tid', '$pid', '$content_escaped')");
            $db->query("INSERT INTO `{$table_prefix}POST_SEARCH_ID` (TID, PID) VALUES ('$tid', '$pid')");

            $pid_map[$post['origin'] . '|' . $post['ref']] = $pid;

            post_update_thread_length($tid, $pid);

            $counts['posts_imported']++;

            foreach ($post['tags'] as $tag) {

                $existing = tag_get_by_name($tag);

                if ($existing !== false) {
                    tag_link_post($existing['TAGID'], $tid, $pid);
                    continue;
                }

                if (($tagid = tag_get_or_create($tag, false, $from_uid, $tid, $pid)) === false) continue;

                tag_link_post($tagid, $tid, $pid);

                $db->query("UPDATE `{$table_prefix}TAG` SET ORIGIN_INSTANCE = '$origin_escaped' WHERE TID = '$tagid' AND ORIGIN_INSTANCE IS NULL");

                $new_tags[$tag] = $tag;
                $counts['tags_pending']++;
            }
        }
    }

    return federation_import_finish($payload['bundle_id'], $counts, $new_tags, $bundle_origin, $error_str);
}

/**
 * Record the outcome in IMPORT_LOG and post one tag-request notice for any
 * new pending tags. Returns $counts, or false when $error_str is set (a
 * partial import is still logged so its bundle_id cannot be replayed; the
 * per-comment origin refs make re-running a fixed bundle safe anyway).
 *
 * @param string $bundle_id
 * @param array  $counts
 * @param array  $new_tags
 * @param string $bundle_origin
 * @param string $error_str
 * @return array|false
 */
function federation_import_finish($bundle_id, array $counts, array $new_tags, $bundle_origin, $error_str)
{
    if (($db = db::get()) && ($table_prefix = get_table_prefix())) {

        $bundle_id = $db->escape($bundle_id);

        $sql = "UPDATE `{$table_prefix}IMPORT_LOG` SET THREAD_COUNT = '" . intval($counts['threads_created'] + $counts['threads_merged']) . "', ";
        $sql .= "POST_COUNT = '" . intval($counts['posts_imported']) . "', SKIPPED_COUNT = '" . intval($counts['posts_skipped']) . "' ";
        $sql .= "WHERE BUNDLE_ID = '$bundle_id'";

        $db->query($sql);
    }

    if (sizeof($new_tags) > 0) federation_post_tag_notice($bundle_origin, $new_tags);

    if (isset($error_str) && strlen($error_str) > 0) return false;

    return $counts;
}

/**
 * One notice in the moderators' tag request thread listing the new tags an
 * import created (rather than one notice per tag).
 *
 * @param string $origin
 * @param array  $tags
 * @return bool
 */
function federation_post_tag_notice($origin, array $tags)
{
    if (($mod_tid = tag_get_moderator_thread_tid()) === false) return false;

    if (!($mod_fid = thread_get_folder_fid($mod_tid))) return false;

    $webtag = get_webtag();

    $owner_uid = 1;

    if (($forum_settings = forum_get_settings()) && isset($forum_settings['owner_uid']) && is_numeric($forum_settings['owner_uid'])) {
        $owner_uid = intval($forum_settings['owner_uid']);
    }

    $queue_uri = htmlentities_array("admin_tag_approve.php?webtag=$webtag");

    $content = sprintf(gettext("An import from \"%s\" added %d new tag(s) awaiting approval: %s"), htmlentities_array($origin), sizeof($tags), htmlentities_array(implode(', ', $tags))) . "<br />";
    $content .= "<a href=\"$queue_uri\" target=\"_self\">" . gettext("Review in the Tag Approval Queue") . "</a>";

    return (post_create($mod_fid, $mod_tid, 0, $owner_uid, array(), $content) !== false);
}

/* ------------------------------------------------------------------ */
/* Redaction                                                          */
/* ------------------------------------------------------------------ */

/**
 * Summary of what an origin has contributed here (for the redaction page).
 *
 * @param string $origin
 * @return array post_count, thread_count (threads containing its comments),
 *               whole_thread_count (threads made up only of its comments), tag_count
 */
function federation_origin_stats($origin)
{
    $stats = array('post_count' => 0, 'thread_count' => 0, 'whole_thread_count' => 0, 'tag_count' => 0);

    if (!$db = db::get()) return $stats;

    if (!($table_prefix = get_table_prefix())) return $stats;

    $origin = $db->escape(instance_identifier_normalise($origin));

    $sql = "SELECT COUNT(*), COUNT(DISTINCT TID) FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE = '$origin'";

    if (($result = $db->query($sql))) list($stats['post_count'], $stats['thread_count']) = $result->fetch_row();

    $stats['whole_thread_count'] = sizeof(federation_origin_whole_threads($origin, false));

    $sql = "SELECT COUNT(*) FROM `{$table_prefix}TAG` WHERE ORIGIN_INSTANCE = '$origin'";

    if (($result = $db->query($sql))) list($stats['tag_count']) = $result->fetch_row();

    return array_map('intval', $stats);
}

/**
 * Threads in which EVERY comment came from $origin.
 *
 * @param string $origin
 * @param bool   $escape  false when $origin is already escaped
 * @return array tids
 */
function federation_origin_whole_threads($origin, $escape = true)
{
    if (!$db = db::get()) return array();

    if (!($table_prefix = get_table_prefix())) return array();

    if ($escape) $origin = $db->escape(instance_identifier_normalise($origin));

    $sql = "SELECT TID FROM `{$table_prefix}POST` WHERE TID IN ";
    $sql .= "(SELECT DISTINCT TID FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE = '$origin') ";
    $sql .= "GROUP BY TID HAVING SUM(ORIGIN_INSTANCE <=> '$origin') = COUNT(*)";

    if (!($result = $db->query($sql))) return array();

    $tids = array();

    while (($row = $result->fetch_row()) !== null) $tids[] = intval($row[0]);

    return $tids;
}

/**
 * Hide or delete everything (or only the tags) that came from an origin.
 *
 * Works per COMMENT via POST.ORIGIN_INSTANCE, because merged threads mix
 * local and imported comments:
 *
 *  scope 'all', action 'hide' (reversible):
 *    - threads made only of the origin's comments: THREAD.DELETED = 'Y'
 *      (restore with the normal thread undelete);
 *    - the origin's comments everywhere: POST.APPROVED = NULL, so they show
 *      as "awaiting approval" and sit in the Post Approval Queue (restore by
 *      approving them);
 *    - tags the origin introduced: TAG.APPROVED = NULL (re-approve in the
 *      Tag Approval Queue).
 *  scope 'all', action 'delete' (permanent):
 *    - threads made only of the origin's comments are purged outright;
 *    - the origin's comments in mixed threads become content-less
 *      tombstones (content, author name and tags removed). The row and its
 *      ORIGIN_INSTANCE/ORIGIN_REF stay, which keeps thread numbering intact
 *      and stops a later bundle from silently re-importing them;
 *    - tags the origin introduced are deleted.
 *  scope 'tags': only the tag part of the above.
 *
 * Refuses this instance's own identifier.
 *
 * @param string $origin
 * @param string $scope  'all' | 'tags'
 * @param string $action 'hide' | 'delete'
 * @param string $error_str
 * @return array|false counts: threads, posts, tags
 */
function federation_redact($origin, $scope, $action, &$error_str = null)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $origin = instance_identifier_normalise($origin);

    if (strlen($origin) == 0) {
        $error_str = gettext("Enter the origin instance identifier to redact.");
        return false;
    }

    if (federation_same_instance($origin, instance_identifier_get())) {
        $error_str = gettext("That is this instance's own identifier. Local content cannot be redacted here.");
        return false;
    }

    if (!in_array($scope, array('all', 'tags'), true) || !in_array($action, array('hide', 'delete'), true)) {
        $error_str = gettext("Invalid redaction options.");
        return false;
    }

    $origin_escaped = $db->escape($origin);

    $counts = array('threads' => 0, 'posts' => 0, 'tags' => 0);

    $current_datetime = date(MYSQL_DATETIME, time());

    $uid = federation_current_uid();

    // What a hide changes is recorded in REDACTION_LOG so that Restore can
    // put back exactly that and nothing else (not comments or tags that were
    // already awaiting approval, not threads a moderator had deleted).
    $log_sql = "REPLACE INTO `{$table_prefix}REDACTION_LOG` (ITEM_TYPE, TID, PID, ORIGIN_INSTANCE, APPROVED, APPROVED_BY, HIDDEN, HIDDEN_BY) ";

    // Tags introduced by the origin (hide: only those currently approved).
    $sql = "SELECT TID, APPROVED, APPROVED_BY FROM `{$table_prefix}TAG` WHERE ORIGIN_INSTANCE = '$origin_escaped'";

    if ($action == 'hide') $sql .= " AND APPROVED IS NOT NULL";

    if (($result = $db->query($sql))) {

        $tag_rows = array();

        while (($row = $result->fetch_assoc()) !== null) $tag_rows[] = $row;

        foreach ($tag_rows as $row) {

            $tagid = intval($row['TID']);

            if ($action == 'hide') {

                $approved_by = is_numeric($row['APPROVED_BY']) ? "'" . intval($row['APPROVED_BY']) . "'" : 'NULL';

                $db->query($log_sql . "VALUES ('G', '$tagid', 0, '$origin_escaped', '" . $db->escape($row['APPROVED']) . "', $approved_by, CAST('$current_datetime' AS DATETIME), '$uid')");

                if (tag_deapprove($tagid, $uid)) $counts['tags']++;

            } else {

                if (tag_delete($tagid)) $counts['tags']++;
            }
        }
    }

    if ($action == 'delete') {

        // Deleted tags can't be restored any more.
        $db->query("DELETE FROM `{$table_prefix}REDACTION_LOG` WHERE ORIGIN_INSTANCE = '$origin_escaped' AND ITEM_TYPE = 'G'");
    }

    if ($scope == 'tags') return $counts;

    $whole_tids = federation_origin_whole_threads($origin_escaped, false);

    // Threads touched (for cache busting), taken before anything changes.
    $touched_tids = array();

    if (($result = $db->query("SELECT DISTINCT TID FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE = '$origin_escaped'"))) {
        while (($row = $result->fetch_row()) !== null) $touched_tids[] = intval($row[0]);
    }

    if ($action == 'hide') {

        if (sizeof($whole_tids) > 0) {

            $tid_list = implode(',', $whole_tids);

            $sql = $log_sql . "SELECT 'T', TID, 0, '$origin_escaped', NULL, NULL, CAST('$current_datetime' AS DATETIME), '$uid' ";
            $sql .= "FROM `{$table_prefix}THREAD` WHERE TID IN ($tid_list) AND DELETED = 'N'";

            $db->query($sql);

            $db->query("UPDATE `{$table_prefix}THREAD` SET DELETED = 'Y' WHERE TID IN ($tid_list) AND DELETED = 'N'");

            $counts['threads'] = $db->affected_rows;
        }

        $sql = $log_sql . "SELECT 'P', TID, PID, '$origin_escaped', APPROVED, APPROVED_BY, CAST('$current_datetime' AS DATETIME), '$uid' ";
        $sql .= "FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE = '$origin_escaped' AND APPROVED IS NOT NULL";

        $db->query($sql);

        $db->query("UPDATE `{$table_prefix}POST` SET APPROVED = NULL, APPROVED_BY = NULL WHERE ORIGIN_INSTANCE = '$origin_escaped' AND APPROVED IS NOT NULL");

        $counts['posts'] = $db->affected_rows;

    } else {

        // Deleted content can't be restored any more.
        $db->query("DELETE FROM `{$table_prefix}REDACTION_LOG` WHERE ORIGIN_INSTANCE = '$origin_escaped'");


        foreach ($whole_tids as $tid) {

            foreach (array('POST_TAG', 'POST_SEARCH_ID', 'POST_RECIPIENT', 'POST_RATING', 'THREAD_STATS', 'USER_THREAD') as $table) {
                $db->query("DELETE QUICK FROM `{$table_prefix}$table` WHERE TID = '$tid'");
            }

            $result = $db->query("SELECT COUNT(*) FROM `{$table_prefix}POST` WHERE TID = '$tid'");

            if ($result) {
                list($post_count) = $result->fetch_row();
                $counts['posts'] += intval($post_count);
            }

            if (thread_delete($tid, THREAD_DELETE_PERMENANT)) $counts['threads']++;
        }

        // Comments in mixed threads -> tombstones.
        $sql = "SELECT TID, PID FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE = '$origin_escaped'";

        if (($result = $db->query($sql))) {

            while (($row = $result->fetch_assoc()) !== null) {

                $tid = intval($row['TID']);
                $pid = intval($row['PID']);

                $db->query("UPDATE `{$table_prefix}POST_CONTENT` SET CONTENT = NULL WHERE TID = '$tid' AND PID = '$pid'");
                $db->query("DELETE QUICK FROM `{$table_prefix}POST_TAG` WHERE TID = '$tid' AND PID = '$pid'");
                $db->query("UPDATE `{$table_prefix}POST` SET ORIGIN_AUTHOR = NULL WHERE TID = '$tid' AND PID = '$pid'");

                $counts['posts']++;
            }
        }
    }

    // Make the change visible straight away (messages-page cache).
    federation_touch_threads(array_diff($touched_tids, ($action == 'delete') ? $whole_tids : array()));

    return $counts;
}

/**
 * Bump THREAD.MODIFIED (within the unread cutoff) so cached message pages
 * pick up a redaction or restore straight away.
 *
 * @param array $tids
 */
function federation_touch_threads($tids)
{
    $tids = array_filter(array_map('intval', (array) $tids));

    if (sizeof($tids) == 0) return;

    if (!$db = db::get()) return;

    if (!($table_prefix = get_table_prefix())) return;

    $current_datetime = date(MYSQL_DATETIME, time());

    $cutoff_datetime = forum_get_unread_cutoff_datetime();

    $sql = "UPDATE LOW_PRIORITY `{$table_prefix}THREAD` SET MODIFIED = IF(MODIFIED < CAST('$cutoff_datetime' AS DATETIME), ";
    $sql .= "MODIFIED, CAST('$current_datetime' AS DATETIME)) WHERE TID IN (" . implode(',', array_unique($tids)) . ")";

    $db->query($sql);
}

/**
 * Origins with content currently hidden by a redaction (and restorable).
 *
 * @return array origin => ['threads' => n, 'posts' => n, 'tags' => n, 'hidden' => unix time of the latest hide]
 */
function federation_hidden_origins()
{
    if (!$db = db::get()) return array();

    if (!($table_prefix = get_table_prefix())) return array();

    $sql = "SELECT ORIGIN_INSTANCE, ITEM_TYPE, COUNT(*) AS ITEM_COUNT, UNIX_TIMESTAMP(MAX(HIDDEN)) AS HIDDEN ";
    $sql .= "FROM `{$table_prefix}REDACTION_LOG` GROUP BY ORIGIN_INSTANCE, ITEM_TYPE ORDER BY ORIGIN_INSTANCE";

    if (!($result = $db->query($sql))) return array();

    $type_keys = array('T' => 'threads', 'P' => 'posts', 'G' => 'tags');

    $origins = array();

    while (($row = $result->fetch_assoc()) !== null) {

        if (!isset($type_keys[$row['ITEM_TYPE']])) continue;

        $origin = $row['ORIGIN_INSTANCE'];

        if (!isset($origins[$origin])) $origins[$origin] = array('threads' => 0, 'posts' => 0, 'tags' => 0, 'hidden' => 0);

        $origins[$origin][$type_keys[$row['ITEM_TYPE']]] = intval($row['ITEM_COUNT']);
        $origins[$origin]['hidden'] = max($origins[$origin]['hidden'], intval($row['HIDDEN']));
    }

    return $origins;
}

/**
 * Undo the "hide" redactions of an origin, using REDACTION_LOG.
 *
 * Only puts back what the hide changed and that is still in the state the
 * hide left it: a thread still deleted, a comment or tag still unapproved
 * (it gets its original approval time and approver back). Anything a
 * moderator has since dealt with by hand is left alone. The origin's log
 * rows are then cleared.
 *
 * @param string $origin
 * @param string $error_str
 * @return array|false counts: threads, posts, tags
 */
function federation_restore($origin, &$error_str = null)
{
    if (!$db = db::get()) return false;

    if (!($table_prefix = get_table_prefix())) return false;

    $origin = instance_identifier_normalise($origin);

    if (strlen($origin) == 0) {
        $error_str = gettext("Enter the origin instance identifier to restore.");
        return false;
    }

    $origin_escaped = $db->escape($origin);

    $counts = array('threads' => 0, 'posts' => 0, 'tags' => 0);

    $touched_tids = array();

    // Threads.
    $sql = "SELECT TID FROM `{$table_prefix}REDACTION_LOG` WHERE ORIGIN_INSTANCE = '$origin_escaped' AND ITEM_TYPE = 'T'";

    if (($result = $db->query($sql))) {

        $tids = array();

        while (($row = $result->fetch_row()) !== null) $tids[] = intval($row[0]);

        if (sizeof($tids) > 0) {

            $db->query("UPDATE `{$table_prefix}THREAD` SET DELETED = 'N' WHERE TID IN (" . implode(',', $tids) . ") AND DELETED = 'Y'");

            $counts['threads'] = $db->affected_rows;

            $touched_tids = array_merge($touched_tids, $tids);
        }
    }

    // Comments.
    $sql = "SELECT DISTINCT TID FROM `{$table_prefix}REDACTION_LOG` WHERE ORIGIN_INSTANCE = '$origin_escaped' AND ITEM_TYPE = 'P'";

    if (($result = $db->query($sql))) {
        while (($row = $result->fetch_row()) !== null) $touched_tids[] = intval($row[0]);
    }

    $sql = "UPDATE `{$table_prefix}POST` POST INNER JOIN `{$table_prefix}REDACTION_LOG` REDACTION_LOG ";
    $sql .= "ON (REDACTION_LOG.ITEM_TYPE = 'P' AND REDACTION_LOG.TID = POST.TID AND REDACTION_LOG.PID = POST.PID) ";
    $sql .= "SET POST.APPROVED = REDACTION_LOG.APPROVED, POST.APPROVED_BY = REDACTION_LOG.APPROVED_BY ";
    $sql .= "WHERE REDACTION_LOG.ORIGIN_INSTANCE = '$origin_escaped' AND POST.APPROVED IS NULL";

    if ($db->query($sql)) $counts['posts'] = $db->affected_rows;

    // Tags.
    $sql = "SELECT REDACTION_LOG.TID, REDACTION_LOG.APPROVED, REDACTION_LOG.APPROVED_BY FROM `{$table_prefix}REDACTION_LOG` REDACTION_LOG ";
    $sql .= "INNER JOIN `{$table_prefix}TAG` TAG ON (TAG.TID = REDACTION_LOG.TID) ";
    $sql .= "WHERE REDACTION_LOG.ORIGIN_INSTANCE = '$origin_escaped' AND REDACTION_LOG.ITEM_TYPE = 'G' AND TAG.APPROVED IS NULL";

    if (($result = $db->query($sql))) {

        $tag_rows = array();

        while (($row = $result->fetch_assoc()) !== null) $tag_rows[] = $row;

        foreach ($tag_rows as $row) {

            $tagid = intval($row['TID']);

            $approved = $db->escape($row['APPROVED']);

            $approved_by = is_numeric($row['APPROVED_BY']) ? "'" . intval($row['APPROVED_BY']) . "'" : 'NULL';

            if ($db->query("UPDATE `{$table_prefix}TAG` SET APPROVED = '$approved', APPROVED_BY = $approved_by WHERE TID = '$tagid' AND APPROVED IS NULL")) {

                if ($db->affected_rows > 0) $counts['tags']++;

                tag_touch_threads($tagid);
            }
        }
    }

    $db->query("DELETE FROM `{$table_prefix}REDACTION_LOG` WHERE ORIGIN_INSTANCE = '$origin_escaped'");

    federation_touch_threads($touched_tids);

    return $counts;
}
