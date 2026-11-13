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

$local_identifier = instance_identifier_get();

$key_error = null;

$key_ok = instance_key_ensure($key_error);

$selected_fids = (isset($_POST['fids']) && is_array($_POST['fids'])) ? array_filter($_POST['fids'], 'is_numeric') : array();

$thread_ids = isset($_POST['thread_ids']) ? trim($_POST['thread_ids']) : '';

$date_from = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';

$date_to = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';

$sign = !isset($_POST['export']) || isset($_POST['sign']);

if (isset($_POST['export'])) {

    $tids = array();

    if (strlen($thread_ids) > 0) {

        foreach (preg_split('/[\s,]+/u', $thread_ids) as $thread_id) {

            if (strlen($thread_id) == 0) continue;

            // Accept "12" or a message reference like "12.3".
            if (preg_match('/^(\d+)(\.\d+)?$/D', $thread_id, $matches)) {
                $tids[] = intval($matches[1]);
            } else {
                $error_msg_array[] = sprintf(gettext("Invalid thread id: %s"), htmlentities_array($thread_id));
            }
        }
    }

    $from_ts = null;
    $to_ts = null;

    if (strlen($date_from) > 0) {

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date_from) || ($from_ts = strtotime("$date_from 00:00:00")) === false) {
            $error_msg_array[] = gettext("The 'from' date must look like 2026-11-13.");
        }
    }

    if (strlen($date_to) > 0) {

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date_to) || ($to_ts = strtotime("$date_to 23:59:59")) === false) {
            $error_msg_array[] = gettext("The 'to' date must look like 2026-11-13.");
        }
    }

    if ($sign && !$key_ok) {
        $error_msg_array[] = sprintf(gettext("This instance has no usable signing key: %s"), htmlentities_array($key_error));
    }

    if (sizeof($error_msg_array) == 0) {

        $error_str = null;

        if (($payload = federation_export_build($selected_fids, $tids, $from_ts, $to_ts, $error_str)) === false) {

            $error_msg_array[] = isset($error_str) ? htmlentities_array($error_str) : gettext("Export failed.");

        } else if (($bundle = federation_bundle_encode($payload, $sign, $error_str)) === false) {

            $error_msg_array[] = isset($error_str) ? htmlentities_array($error_str) : gettext("Export failed.");

        } else {

            admin_add_log_entry(FEDERATION_EXPORT, array($payload['bundle_id'], $payload['thread_count'], $payload['post_count'], $sign ? 'signed' : 'unsigned'));

            $file_slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($local_identifier, 'UTF-8')), '-');

            if (strlen($file_slug) == 0) $file_slug = 'instance';

            $filename = sprintf('woodbees-%s-%s-%s.json', $file_slug, gmdate('Ymd-His'), substr($payload['bundle_id'], 0, 8));

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($bundle));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');

            echo $bundle;
            exit;
        }
    }
}

html_draw_top(
    array(
        'title' => gettext('Admin - Federation Export'),
        'class' => 'window_title',
        'main_css' => 'admin.css'
    )
);

echo "<h1>", gettext("Admin"), html_style_image('separator'), gettext("Federation Export"), "</h1>\n";

if (sizeof($error_msg_array) > 0) {
    html_display_error_array($error_msg_array, '86%', 'center');
}

if (strlen($local_identifier) == 0) {
    html_display_warning_msg(gettext("This instance has no instance identifier, so it cannot export. Set one by running the installer's upgrade."), '86%', 'center');
}

if (!$key_ok) {
    html_display_warning_msg(sprintf(gettext("Signing is unavailable: %s"), htmlentities_array($key_error)), '86%', 'center');
}

$folders = folder_get_all();

if (!is_array($folders)) $folders = array();

echo "<br />\n";
echo "<div align=\"center\">\n";
echo "<form accept-charset=\"utf-8\" name=\"f_export\" action=\"admin_export.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr><td class=\"subhead\" align=\"left\" colspan=\"2\">", gettext("This instance"), "</td></tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" width=\"220\">", gettext("Instance identifier"), ":</td>\n";
echo "                  <td align=\"left\"><b>", (strlen($local_identifier) > 0 ? htmlentities_array($local_identifier) : gettext("(not set)")), "</b></td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" valign=\"top\">", gettext("Signing key fingerprint"), ":</td>\n";
echo "                  <td align=\"left\"><code>", ($key_ok ? htmlentities_array(instance_key_fingerprint(instance_key_public())) : gettext("(no key)")), "</code></td>\n";
echo "                </tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\"><span class=\"smallertext\">", gettext("Read this fingerprint to the admin you send bundles to (by phone, chat - not inside the bundle) so they can confirm it on their first import."), "</span></td></tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\">&nbsp;</td></tr>\n";
echo "                <tr><td class=\"subhead\" align=\"left\" colspan=\"2\">", gettext("What to export"), "</td></tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" valign=\"top\">", gettext("Folders"), ":</td>\n";
echo "                  <td align=\"left\">\n";

foreach ($folders as $folder) {
    echo "                    ", form_checkbox('fids[]', $folder['FID'], htmlentities_array($folder['TITLE']), in_array($folder['FID'], $selected_fids)), "<br />\n";
}

echo "                  </td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\">", gettext("Only these threads (optional)"), ":</td>\n";
echo "                  <td align=\"left\">", form_input_text('thread_ids', htmlentities_array($thread_ids), 40, 1000), "&nbsp;<span class=\"smallertext\">", gettext("thread ids, comma separated"), "</span></td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\">", gettext("Comments posted from (optional)"), ":</td>\n";
echo "                  <td align=\"left\">", form_input_text('date_from', htmlentities_array($date_from), 12, 10, null, 'bhinputtext', 'YYYY-MM-DD'), "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\">", gettext("Comments posted until (optional)"), ":</td>\n";
echo "                  <td align=\"left\">", form_input_text('date_to', htmlentities_array($date_to), 12, 10, null, 'bhinputtext', 'YYYY-MM-DD'), "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\">", gettext("Signature"), ":</td>\n";
echo "                  <td align=\"left\">", form_checkbox('sign', 'Y', gettext("Sign the bundle with this instance's key (recommended)"), $sign && $key_ok, $key_ok ? null : 'disabled="disabled"'), "</td>\n";
echo "                </tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\"><span class=\"smallertext\">", gettext("Only approved, undeleted comments are exported, with their approved tags. Each comment keeps its true origin: comments imported from elsewhere are exported under their original instance and author, not as this instance's."), "</span></td></tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\">&nbsp;</td></tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "    <tr><td align=\"left\">&nbsp;</td></tr>\n";
echo "    <tr>\n";
echo "      <td align=\"center\">", form_submit('export', gettext("Download bundle")), "</td>\n";
echo "    </tr>\n";
echo "  </table>\n";
echo "</form>\n";
echo "</div>\n";

html_draw_bottom();
