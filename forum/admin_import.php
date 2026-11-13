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

// Bootstrap
require_once 'boot.php';

// Required includes
require_once BH_INCLUDE_PATH . 'admin.inc.php';
require_once BH_INCLUDE_PATH . 'constants.inc.php';
require_once BH_INCLUDE_PATH . 'federation.inc.php';
require_once BH_INCLUDE_PATH . 'folder.inc.php';
require_once BH_INCLUDE_PATH . 'form.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'header.inc.php';
require_once BH_INCLUDE_PATH . 'html.inc.php';
require_once BH_INCLUDE_PATH . 'instance_key.inc.php';
require_once BH_INCLUDE_PATH . 'session.inc.php';
require_once BH_INCLUDE_PATH . 'word_filter.inc.php';
// End Required includes

// Check we're logged in correctly
if (!session::logged_in()) {

    $request_uri = rawurlencode(get_request_uri());
    header_redirect("logon.php?webtag=$webtag&final_uri=$request_uri");
}

// Federation is an admin-only tool.
if (!session::check_perm(USER_PERM_ADMIN_TOOLS, 0)) {
    html_draw_error(gettext("You do not have permission to use this section."));
}

// Perform additional admin login.
admin_check_credentials();

$error_msg_array = array();

/**
 * Uploaded bundles wait in the temp dir between "upload" and "confirm"; the
 * session only holds an opaque token. Everything is re-verified on confirm.
 */
function admin_import_pending_path($token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) return false;

    if (!isset($_SESSION['FEDERATION_IMPORT_TOKENS']) || !is_array($_SESSION['FEDERATION_IMPORT_TOKENS'])) return false;

    if (!in_array($token, $_SESSION['FEDERATION_IMPORT_TOKENS'], true)) return false;

    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . "woodbees_import_$token.json";
}

/**
 * Remove pending uploads nobody confirmed or cancelled (older than a day).
 */
function admin_import_pending_prune()
{
    $pattern = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'woodbees_import_*.json';

    foreach ((array) glob($pattern) as $path) {
        if (is_file($path) && filemtime($path) < time() - DAY_IN_SECONDS) @unlink($path);
    }
}

function admin_import_pending_discard($token)
{
    if (($path = admin_import_pending_path($token)) !== false) @unlink($path);

    if (isset($_SESSION['FEDERATION_IMPORT_TOKENS']) && is_array($_SESSION['FEDERATION_IMPORT_TOKENS'])) {
        $_SESSION['FEDERATION_IMPORT_TOKENS'] = array_values(array_diff($_SESSION['FEDERATION_IMPORT_TOKENS'], array($token)));
    }
}

$review = null;
$token = null;
$target_fid = (isset($_POST['target_fid']) && is_numeric($_POST['target_fid'])) ? intval($_POST['target_fid']) : 0;

if (isset($_POST['cancel'])) {

    if (isset($_POST['token'])) admin_import_pending_discard($_POST['token']);

    header_redirect("admin_import.php?webtag=$webtag");
    exit;

} else if (isset($_POST['upload'])) {

    admin_import_pending_prune();

    if (!isset($_FILES['bundle']) || !is_array($_FILES['bundle']) || $_FILES['bundle']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['bundle']['tmp_name'])) {

        $error_msg_array[] = gettext("Choose a bundle file to upload.");

    } else if ($_FILES['bundle']['size'] > FEDERATION_MAX_BUNDLE_BYTES) {

        $error_msg_array[] = gettext("The uploaded bundle is too large.");

    } else {

        $bundle_json = file_get_contents($_FILES['bundle']['tmp_name']);

        $error_str = null;

        if (($review = federation_bundle_check($bundle_json, $error_str)) === false) {

            $review = null;
            $error_msg_array[] = htmlentities_array($error_str);

        } else {

            $token = bin2hex(random_bytes(16));

            if (!isset($_SESSION['FEDERATION_IMPORT_TOKENS']) || !is_array($_SESSION['FEDERATION_IMPORT_TOKENS'])) {
                $_SESSION['FEDERATION_IMPORT_TOKENS'] = array();
            }

            $_SESSION['FEDERATION_IMPORT_TOKENS'][] = $token;

            if (@file_put_contents(admin_import_pending_path($token), $bundle_json, LOCK_EX) === false) {
                $review = null;
                $error_msg_array[] = gettext("Could not store the uploaded bundle for review.");
            }
        }
    }

} else if (isset($_POST['confirm'])) {

    $token = isset($_POST['token']) ? $_POST['token'] : null;

    if (($path = admin_import_pending_path($token)) === false || ($bundle_json = @file_get_contents($path)) === false) {

        $error_msg_array[] = gettext("The uploaded bundle has expired. Please upload it again.");
        $token = null;

    } else {

        $error_str = null;

        if (($review = federation_bundle_check($bundle_json, $error_str)) === false) {

            admin_import_pending_discard($token);
            $review = null;
            $token = null;
            $error_msg_array[] = htmlentities_array($error_str);

        } else {

            if ($review['trust'] == FEDERATION_TRUST_FIRST_SEEN && (!isset($_POST['confirm_fingerprint']) || $_POST['confirm_fingerprint'] != 'Y')) {
                $error_msg_array[] = gettext("Confirm that you have checked the key fingerprint with the other instance's admin.");
            }

            if ($review['trust'] == FEDERATION_TRUST_UNSIGNED && (!isset($_POST['confirm_unsigned']) || $_POST['confirm_unsigned'] != 'Y')) {
                $error_msg_array[] = gettext("Confirm that you want to import an UNSIGNED bundle.");
            }

            if (!folder_is_valid($target_fid)) {
                $error_msg_array[] = gettext("Choose a valid target folder.");
            }

            if (sizeof($error_msg_array) == 0) {

                if ($review['trust'] == FEDERATION_TRUST_FIRST_SEEN) {

                    if (federation_trust_add($review['origin'], $review['public_key'], $_SESSION['UID'])) {
                        admin_add_log_entry(FEDERATION_TRUST, array($review['origin'], $review['fingerprint']));
                    }

                } else if ($review['trust'] == FEDERATION_TRUST_KNOWN) {

                    federation_trust_touch($review['origin']);
                }

                $import_error = null;

                $result = federation_import($review, $target_fid, $import_error);

                admin_import_pending_discard($token);

                $_SESSION['FEDERATION_IMPORT_RESULT'] = array(
                    'origin' => $review['origin'],
                    'bundle_id' => $review['payload']['bundle_id'],
                    'counts' => ($result !== false) ? $result : null,
                    'error' => ($result === false) ? $import_error : null,
                );

                if ($result !== false) {
                    admin_add_log_entry(FEDERATION_IMPORT, array($review['origin'], $review['payload']['bundle_id'], $result['posts_imported'], $result['posts_skipped']));
                }

                header_redirect("admin_import.php?webtag=$webtag&import_done=true");
                exit;
            }
        }
    }

} else if (isset($_POST['trust_action']) && isset($_POST['trust_iid']) && is_numeric($_POST['trust_iid'])) {

    if (($trusted = federation_trust_get_by_id($_POST['trust_iid'])) !== false) {

        switch ($_POST['trust_action']) {

            case 'untrust':

                federation_trust_set_status($trusted['IID'], FEDERATION_UNTRUSTED);
                admin_add_log_entry(FEDERATION_UNTRUST, array($trusted['IDENTIFIER']));
                break;

            case 'trust':

                federation_trust_set_status($trusted['IID'], FEDERATION_TRUSTED);
                admin_add_log_entry(FEDERATION_TRUST, array($trusted['IDENTIFIER'], instance_key_fingerprint(base64_decode($trusted['PUBLIC_KEY']))));
                break;

            case 'forget':

                federation_trust_forget($trusted['IID']);
                admin_add_log_entry(FEDERATION_FORGET, array($trusted['IDENTIFIER']));
                break;
        }
    }

    header_redirect("admin_import.php?webtag=$webtag&trust_updated=true");
    exit;
}

html_draw_top(
    array(
        'title' => gettext('Admin - Federation Import'),
        'class' => 'window_title',
        'main_css' => 'admin.css'
    )
);

echo "<h1>", gettext("Admin"), html_style_image('separator'), gettext("Federation Import"), "</h1>\n";

if (isset($_GET['import_done']) && isset($_SESSION['FEDERATION_IMPORT_RESULT'])) {

    $import_result = $_SESSION['FEDERATION_IMPORT_RESULT'];
    unset($_SESSION['FEDERATION_IMPORT_RESULT']);

    if (isset($import_result['counts'])) {

        $counts = $import_result['counts'];

        html_display_success_msg(sprintf(
            gettext("Imported from %s: %d comment(s) in %d new and %d existing thread(s); %d skipped (already here, or this instance's own); %d new tag(s) sent to the Tag Approval Queue."),
            htmlentities_array($import_result['origin']),
            $counts['posts_imported'],
            $counts['threads_created'],
            $counts['threads_merged'],
            $counts['posts_skipped'],
            $counts['tags_pending']
        ) . (!empty($counts['threads_resequenced']) ? ' ' . sprintf(
            gettext("%d existing thread(s) were renumbered so their comments are in date order."),
            $counts['threads_resequenced']
        ) : '') . (!empty($counts['threads_not_resequenced']) ? ' ' . sprintf(
            gettext("%d thread(s) could not be put into date order (poll thread, or the database user may not LOCK TABLES); their imported comments were added at the end."),
            $counts['threads_not_resequenced']
        ) : ''), '86%', 'center');

    } else {

        html_display_error_msg(sprintf(gettext("The import from %s stopped part-way: %s"), htmlentities_array($import_result['origin']), htmlentities_array($import_result['error'])), '86%', 'center');
    }

} else if (isset($_GET['trust_updated'])) {

    html_display_success_msg(gettext("Trusted instances updated"), '86%', 'center');
}

if (sizeof($error_msg_array) > 0) {
    html_display_error_array($error_msg_array, '86%', 'center');
}

echo "<br />\n";
echo "<div align=\"center\">\n";

if (isset($review) && isset($token)) {

    // Review step
    echo "<form accept-charset=\"utf-8\" name=\"f_import_confirm\" action=\"admin_import.php\" method=\"post\" target=\"_self\">\n";
    echo "  ", form_csrf_token_field(), "\n";
    echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
    echo "  ", form_input_hidden('token', htmlentities_array($token)), "\n";
    echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
    echo "    <tr>\n";
    echo "      <td align=\"left\">\n";
    echo "        <table class=\"box\" width=\"100%\">\n";
    echo "          <tr>\n";
    echo "            <td align=\"left\" class=\"posthead\">\n";
    echo "              <table class=\"posthead\" width=\"100%\">\n";
    echo "                <tr><td class=\"subhead\" align=\"left\" colspan=\"2\">", gettext("Review bundle"), "</td></tr>\n";
    echo "                <tr><td align=\"left\" width=\"220\">", gettext("From instance"), ":</td><td align=\"left\"><b>", htmlentities_array($review['origin']), "</b></td></tr>\n";
    echo "                <tr><td align=\"left\">", gettext("Exported at"), ":</td><td align=\"left\">", htmlentities_array($review['payload']['exported_at']), "</td></tr>\n";
    echo "                <tr><td align=\"left\">", gettext("Bundle id"), ":</td><td align=\"left\"><code>", htmlentities_array($review['payload']['bundle_id']), "</code></td></tr>\n";
    echo "                <tr><td align=\"left\">", gettext("Contents"), ":</td><td align=\"left\">", sprintf(gettext("%d thread(s), %d comment(s)"), $review['thread_count'], $review['post_count']), "</td></tr>\n";

    if ($review['own_post_count'] > 0) {
        echo "                <tr><td align=\"left\">&nbsp;</td><td align=\"left\">", sprintf(gettext("%d comment(s) originally from this instance will be skipped."), $review['own_post_count']), "</td></tr>\n";
    }

    echo "                <tr><td align=\"left\" valign=\"top\">", gettext("Signature"), ":</td><td align=\"left\">";

    switch ($review['trust']) {

        case FEDERATION_TRUST_KNOWN:

            echo "<b>", gettext("Valid"), "</b> - ", gettext("signed with the key already trusted for this instance."), "<br /><code>", htmlentities_array($review['fingerprint']), "</code>";
            break;

        case FEDERATION_TRUST_FIRST_SEEN:

            echo "<b>", gettext("Valid, but this instance has never been seen before."), "</b><br />";
            echo gettext("Key fingerprint"), ": <code>", htmlentities_array($review['fingerprint']), "</code><br />";
            echo gettext("Check this fingerprint with the other instance's admin over a separate channel (it is shown on their Federation Export page). If you continue, this key is pinned: later bundles claiming to be from this instance must be signed with it.");

            if (sizeof($review['other_identifiers']) > 0) {
                echo "<br /><b>", sprintf(gettext("Note: this key is already trusted here as %s - the instance may have been renamed."), htmlentities_array(implode(', ', $review['other_identifiers']))), "</b>";
            }

            break;

        default:

            echo "<b>", gettext("UNSIGNED"), "</b> - ", gettext("nothing proves which instance this bundle really came from. Only import unsigned bundles you received directly from an admin you trust.");
            break;
    }

    echo "</td></tr>\n";
    echo "                <tr><td align=\"left\">", gettext("Folder for new threads"), ":</td><td align=\"left\">", folder_draw_dropdown_all($target_fid, 'target_fid'), "</td></tr>\n";
    echo "                <tr><td align=\"left\" colspan=\"2\"><span class=\"smallertext\">", gettext("Threads whose address already exists here are merged: the imported comments are appended to the existing thread, which keeps its folder, title, tags and moderation state. Comments already imported before are skipped. New tags go to the Tag Approval Queue."), "</span></td></tr>\n";

    if ($review['trust'] == FEDERATION_TRUST_FIRST_SEEN) {
        echo "                <tr><td align=\"left\" colspan=\"2\">", form_checkbox('confirm_fingerprint', 'Y', gettext("I have checked the key fingerprint with the other instance's admin and want to trust it")), "</td></tr>\n";
    }

    if ($review['trust'] == FEDERATION_TRUST_UNSIGNED) {
        echo "                <tr><td align=\"left\" colspan=\"2\">", form_checkbox('confirm_unsigned', 'Y', gettext("Import this UNSIGNED bundle anyway")), "</td></tr>\n";
    }

    echo "                <tr><td align=\"left\" colspan=\"2\">&nbsp;</td></tr>\n";
    echo "              </table>\n";
    echo "            </td>\n";
    echo "          </tr>\n";
    echo "        </table>\n";
    echo "      </td>\n";
    echo "    </tr>\n";
    echo "    <tr><td align=\"left\">&nbsp;</td></tr>\n";
    echo "    <tr><td align=\"center\">", form_submit('confirm', gettext("Import")), "&nbsp;", form_submit('cancel', gettext("Cancel")), "</td></tr>\n";
    echo "  </table>\n";
    echo "</form>\n";

} else {

    // Upload step
    echo "<form accept-charset=\"utf-8\" name=\"f_import\" action=\"admin_import.php\" method=\"post\" target=\"_self\" enctype=\"multipart/form-data\">\n";
    echo "  ", form_csrf_token_field(), "\n";
    echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
    echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
    echo "    <tr>\n";
    echo "      <td align=\"left\">\n";
    echo "        <table class=\"box\" width=\"100%\">\n";
    echo "          <tr>\n";
    echo "            <td align=\"left\" class=\"posthead\">\n";
    echo "              <table class=\"posthead\" width=\"100%\">\n";
    echo "                <tr><td class=\"subhead\" align=\"left\">", gettext("Upload bundle"), "</td></tr>\n";
    echo "                <tr><td align=\"left\"><input type=\"file\" name=\"bundle\" accept=\".json,application/json\" /></td></tr>\n";
    echo "                <tr><td align=\"left\"><span class=\"smallertext\">", gettext("A .json bundle from another WoodBees instance's Federation Export page. You will see who it is from and whether its signature checks out before anything is imported."), "</span></td></tr>\n";
    echo "                <tr><td align=\"left\">&nbsp;</td></tr>\n";
    echo "              </table>\n";
    echo "            </td>\n";
    echo "          </tr>\n";
    echo "        </table>\n";
    echo "      </td>\n";
    echo "    </tr>\n";
    echo "    <tr><td align=\"left\">&nbsp;</td></tr>\n";
    echo "    <tr><td align=\"center\">", form_submit('upload', gettext("Upload and review")), "</td></tr>\n";
    echo "  </table>\n";
    echo "</form>\n";
}

// Trusted instances
$trusted_list = federation_trust_list();

echo "<br />\n";
echo "<form accept-charset=\"utf-8\" name=\"f_trust\" action=\"admin_import.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  ", form_input_hidden('trust_iid', ''), "\n";
echo "  ", form_input_hidden('trust_action', ''), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("Trusted instance"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("Key fingerprint"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("Status"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("First seen"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\" width=\"170\">&nbsp;</td>\n";
echo "                </tr>\n";

if (sizeof($trusted_list) > 0) {

    foreach ($trusted_list as $trusted) {

        $iid = intval($trusted['IID']);

        $is_trusted = ($trusted['STATUS'] === FEDERATION_TRUSTED);

        echo "                <tr>\n";
        echo "                  <td align=\"left\">", htmlentities_array($trusted['IDENTIFIER']), "</td>\n";
        echo "                  <td align=\"left\"><code class=\"smallertext\">", htmlentities_array(instance_key_fingerprint(base64_decode($trusted['PUBLIC_KEY']))), "</code></td>\n";
        echo "                  <td align=\"left\">", ($is_trusted ? gettext("Trusted") : "<b>" . gettext("Untrusted") . "</b>"), "</td>\n";
        echo "                  <td align=\"left\">", format_date_time($trusted['FIRST_SEEN']), "</td>\n";
        echo "                  <td align=\"left\" style=\"white-space: nowrap\">";
        echo form_submit('trust_submit', $is_trusted ? gettext("Untrust") : gettext("Trust"), "onclick=\"this.form.trust_iid.value='$iid'; this.form.trust_action.value='" . ($is_trusted ? 'untrust' : 'trust') . "';\""), "&nbsp;";
        echo form_submit('trust_submit', gettext("Forget"), "onclick=\"if (!confirm('" . htmlentities_array(gettext("Forget this key? The next bundle from this instance will be treated as first-seen.")) . "')) return false; this.form.trust_iid.value='$iid'; this.form.trust_action.value='forget';\"");
        echo "</td>\n";
        echo "                </tr>\n";
    }

} else {

    echo "                <tr><td align=\"left\" colspan=\"5\">", gettext("No instances trusted yet. A key is pinned the first time you import a signed bundle from an instance."), "</td></tr>\n";
}

echo "                <tr><td align=\"left\" colspan=\"5\"><span class=\"smallertext\">", gettext("Untrust refuses all further bundles from that instance (its already-imported content stays; use Instance Redaction to hide or delete it). Forget removes the pinned key, e.g. after the other admin really did replace theirs."), "</span></td></tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "  </table>\n";
echo "</form>\n";

// Import history
$import_log = federation_import_log(20);

echo "<br />\n";
echo "<table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "  <tr>\n";
echo "    <td align=\"left\">\n";
echo "      <table class=\"box\" width=\"100%\">\n";
echo "        <tr>\n";
echo "          <td align=\"left\" class=\"posthead\">\n";
echo "            <table class=\"posthead\" width=\"100%\">\n";
echo "              <tr>\n";
echo "                <td class=\"subhead\" align=\"left\">", gettext("Recent imports"), "</td>\n";
echo "                <td class=\"subhead\" align=\"left\">", gettext("Signed"), "</td>\n";
echo "                <td class=\"subhead\" align=\"left\">", gettext("Comments"), "</td>\n";
echo "                <td class=\"subhead\" align=\"left\">", gettext("Skipped"), "</td>\n";
echo "                <td class=\"subhead\" align=\"left\">", gettext("By"), "</td>\n";
echo "                <td class=\"subhead\" align=\"left\">", gettext("Date/Time"), "</td>\n";
echo "              </tr>\n";

if (sizeof($import_log) > 0) {

    foreach ($import_log as $import_entry) {

        echo "              <tr>\n";
        echo "                <td align=\"left\">", htmlentities_array($import_entry['ORIGIN_INSTANCE']), " <code class=\"smallertext\">", htmlentities_array(substr($import_entry['BUNDLE_ID'], 0, 8)), "</code></td>\n";
        echo "                <td align=\"left\">", (isset($import_entry['PUBLIC_KEY']) ? gettext("Yes") : "<b>" . gettext("UNSIGNED") . "</b>"), "</td>\n";
        echo "                <td align=\"left\">", intval($import_entry['POST_COUNT']), "</td>\n";
        echo "                <td align=\"left\">", intval($import_entry['SKIPPED_COUNT']), "</td>\n";
        echo "                <td align=\"left\">", (isset($import_entry['LOGON']) ? word_filter_add_ob_tags(format_user_name($import_entry['LOGON'], $import_entry['NICKNAME']), true) : gettext("Unknown user")), "</td>\n";
        echo "                <td align=\"left\">", format_date_time($import_entry['IMPORTED']), "</td>\n";
        echo "              </tr>\n";
    }

} else {

    echo "              <tr><td align=\"left\" colspan=\"6\">", gettext("Nothing imported yet."), "</td></tr>\n";
}

echo "              <tr><td align=\"left\" colspan=\"6\">&nbsp;</td></tr>\n";
echo "            </table>\n";
echo "          </td>\n";
echo "        </tr>\n";
echo "      </table>\n";
echo "    </td>\n";
echo "  </tr>\n";
echo "</table>\n";

echo "</div>\n";

html_draw_bottom();
