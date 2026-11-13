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
require_once BH_INCLUDE_PATH . 'form.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'header.inc.php';
require_once BH_INCLUDE_PATH . 'html.inc.php';
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

$origin = isset($_POST['origin']) ? instance_identifier_normalise($_POST['origin']) : '';

$scope = (isset($_POST['scope']) && $_POST['scope'] == 'tags') ? 'tags' : 'all';

$action = (isset($_POST['redact_action']) && $_POST['redact_action'] == 'delete') ? 'delete' : 'hide';

$scope_labels = array(
    'all' => gettext("All content (comments, threads and tags)"),
    'tags' => gettext("Tags only"),
);

$action_labels = array(
    'hide' => gettext("Hide (reversible)"),
    'delete' => gettext("Delete (permanent)"),
);

if (isset($_POST['cancel'])) {

    header_redirect("admin_instance_redact.php?webtag=$webtag");
    exit;
}

if (isset($_POST['restore']) && strlen($origin) > 0) {

    if (isset($_POST['restore_confirm']) && $_POST['restore_confirm'] == 'Y') {

        $error_str = null;

        if (($counts = federation_restore($origin, $error_str)) !== false) {

            admin_add_log_entry(FEDERATION_RESTORE, array($origin, $counts['threads'], $counts['posts'], $counts['tags']));

            $_SESSION['FEDERATION_RESTORE_RESULT'] = array('origin' => $origin, 'counts' => $counts);

            header_redirect("admin_instance_redact.php?webtag=$webtag&restore_success=true");
            exit;
        }

        $error_msg_array[] = htmlentities_array($error_str);

    } else {

        $hidden_origins = federation_hidden_origins();

        if (!isset($hidden_origins[$origin])) {

            $error_msg_array[] = sprintf(gettext("Nothing from \"%s\" is currently hidden by a redaction."), htmlentities_array($origin));

        } else {

            $hidden = $hidden_origins[$origin];

            html_draw_top(array('title' => gettext('Admin - Instance Redaction'), 'class' => 'window_title', 'main_css' => 'admin.css'));

            html_display_msg(
                gettext("Instance Redaction"),
                sprintf(
                    gettext("Restore what was hidden from \"%s\": %d thread(s), %d comment(s) and %d tag(s)? Items a moderator has already approved, undeleted or deleted since are left as they are."),
                    htmlentities_array($origin),
                    $hidden['threads'],
                    $hidden['posts'],
                    $hidden['tags']
                ),
                'admin_instance_redact.php',
                'post',
                array('restore' => gettext("Yes"), 'cancel' => gettext("No")),
                array('origin' => $origin, 'restore_confirm' => 'Y'),
                '_self',
                'center'
            );

            html_draw_bottom();
            exit;
        }
    }

} else if ((isset($_POST['preview']) || isset($_POST['redact'])) && strlen($origin) == 0) {

    $error_msg_array[] = gettext("Enter the origin instance identifier to redact.");

} else if ((isset($_POST['preview']) || isset($_POST['redact'])) && federation_same_instance($origin, $local_identifier)) {

    $error_msg_array[] = gettext("That is this instance's own identifier. Local content cannot be redacted here.");

} else if (isset($_POST['redact']) && isset($_POST['redact_confirm']) && $_POST['redact_confirm'] == 'Y') {

    $error_str = null;

    if (($counts = federation_redact($origin, $scope, $action, $error_str)) !== false) {

        admin_add_log_entry(FEDERATION_REDACT, array($origin, $scope, $action, $counts['threads'], $counts['posts'], $counts['tags']));

        $_SESSION['FEDERATION_REDACT_RESULT'] = array('origin' => $origin, 'scope' => $scope, 'action' => $action, 'counts' => $counts);

        header_redirect("admin_instance_redact.php?webtag=$webtag&redact_success=true");
        exit;
    }

    $error_msg_array[] = htmlentities_array($error_str);

} else if (isset($_POST['preview']) || isset($_POST['redact'])) {

    // Confirmation step with what would be affected.
    $stats = federation_origin_stats($origin);

    html_draw_top(array('title' => gettext('Admin - Instance Redaction'), 'class' => 'window_title', 'main_css' => 'admin.css'));

    if ($scope == 'tags') {

        $summary = sprintf(gettext("%d tag(s) introduced by imports from \"%s\"."), $stats['tag_count'], htmlentities_array($origin));

    } else {

        $summary = sprintf(
            gettext("%d comment(s) from \"%s\" in %d thread(s) (%d of them made up only of its comments), and %d tag(s) it introduced."),
            $stats['post_count'],
            htmlentities_array($origin),
            $stats['thread_count'],
            $stats['whole_thread_count'],
            $stats['tag_count']
        );
    }

    if ($action == 'delete') {
        $question = gettext("PERMANENTLY DELETE this content? This cannot be undone.");
    } else {
        $question = gettext("Hide this content? It can be put back later with the Restore button on this page.");
    }

    html_display_msg(
        gettext("Instance Redaction"),
        sprintf("%s<br /><br />%s: %s<br />%s: %s<br /><br />%s", $summary, gettext("Scope"), $scope_labels[$scope], gettext("Action"), $action_labels[$action], $question),
        'admin_instance_redact.php',
        'post',
        array('redact' => gettext("Yes"), 'cancel' => gettext("No")),
        array('origin' => $origin, 'scope' => $scope, 'redact_action' => $action, 'redact_confirm' => 'Y'),
        '_self',
        'center'
    );

    html_draw_bottom();
    exit;
}

html_draw_top(
    array(
        'title' => gettext('Admin - Instance Redaction'),
        'class' => 'window_title',
        'main_css' => 'admin.css'
    )
);

echo "<h1>", gettext("Admin"), html_style_image('separator'), gettext("Instance Redaction"), "</h1>\n";

if (isset($_GET['redact_success']) && isset($_SESSION['FEDERATION_REDACT_RESULT'])) {

    $redact_result = $_SESSION['FEDERATION_REDACT_RESULT'];
    unset($_SESSION['FEDERATION_REDACT_RESULT']);

    html_display_success_msg(sprintf(
        gettext("%s from \"%s\": %d thread(s), %d comment(s), %d tag(s)."),
        $action_labels[$redact_result['action']],
        htmlentities_array($redact_result['origin']),
        $redact_result['counts']['threads'],
        $redact_result['counts']['posts'],
        $redact_result['counts']['tags']
    ), '86%', 'center');

} else if (isset($_GET['restore_success']) && isset($_SESSION['FEDERATION_RESTORE_RESULT'])) {

    $restore_result = $_SESSION['FEDERATION_RESTORE_RESULT'];
    unset($_SESSION['FEDERATION_RESTORE_RESULT']);

    html_display_success_msg(sprintf(
        gettext("Restored from \"%s\": %d thread(s), %d comment(s), %d tag(s)."),
        htmlentities_array($restore_result['origin']),
        $restore_result['counts']['threads'],
        $restore_result['counts']['posts'],
        $restore_result['counts']['tags']
    ), '86%', 'center');

} else if (sizeof($error_msg_array) > 0) {

    html_display_error_array($error_msg_array, '86%', 'center');
}

// Origins present here, for convenience.
$origins = array();

if (($db = db::get()) && ($table_prefix = get_table_prefix())) {

    $sql = "SELECT ORIGIN_INSTANCE, COUNT(*) FROM `{$table_prefix}POST` WHERE ORIGIN_INSTANCE IS NOT NULL GROUP BY ORIGIN_INSTANCE ";
    $sql .= "UNION SELECT ORIGIN_INSTANCE, 0 FROM `{$table_prefix}TAG` WHERE ORIGIN_INSTANCE IS NOT NULL GROUP BY ORIGIN_INSTANCE";

    if (($result = $db->query($sql))) {

        while (($row = $result->fetch_row()) !== null) {
            $origins[$row[0]] = (isset($origins[$row[0]]) ? $origins[$row[0]] : 0) + intval($row[1]);
        }
    }
}

echo "<br />\n";
echo "<div align=\"center\">\n";
echo "<form accept-charset=\"utf-8\" name=\"f_redact\" action=\"admin_instance_redact.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr><td class=\"subhead\" align=\"left\" colspan=\"2\">", gettext("Redact an instance's content"), "</td></tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" width=\"220\">", gettext("Origin instance identifier"), ":</td>\n";
echo "                  <td align=\"left\">", form_input_text('origin', htmlentities_array($origin), 40, 64), "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" valign=\"top\">", gettext("Scope"), ":</td>\n";
echo "                  <td align=\"left\">", form_radio('scope', 'all', $scope_labels['all'], $scope == 'all'), "<br />", form_radio('scope', 'tags', $scope_labels['tags'], $scope == 'tags'), "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" valign=\"top\">", gettext("Action"), ":</td>\n";
echo "                  <td align=\"left\">", form_radio('redact_action', 'hide', $action_labels['hide'], $action == 'hide'), "<br />", form_radio('redact_action', 'delete', $action_labels['delete'], $action == 'delete'), "</td>\n";
echo "                </tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\"><span class=\"smallertext\">", gettext("Works comment by comment, so threads that mix local and imported comments keep their local comments. A thread is hidden or deleted as a whole only when every comment in it came from that instance. Deleted comments in mixed threads remain as empty \"deleted\" placeholders so they are never re-imported. Your own identifier is refused."), "</span></td></tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\"><span class=\"smallertext\">", sprintf(gettext("This instance is \"%s\"."), htmlentities_array($local_identifier)), "</span></td></tr>\n";
echo "                <tr><td align=\"left\" colspan=\"2\">&nbsp;</td></tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "    <tr><td align=\"left\">&nbsp;</td></tr>\n";
echo "    <tr><td align=\"center\">", form_submit('preview', gettext("Continue")), "</td></tr>\n";
echo "  </table>\n";
echo "</form>\n";

// Content currently hidden by a redaction, with a Restore button per origin.
$hidden_origins = federation_hidden_origins();

if (sizeof($hidden_origins) > 0) {

    echo "<br />\n";
    echo "<table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
    echo "  <tr>\n";
    echo "    <td align=\"left\">\n";
    echo "      <table class=\"box\" width=\"100%\">\n";
    echo "        <tr>\n";
    echo "          <td align=\"left\" class=\"posthead\">\n";
    echo "            <table class=\"posthead\" width=\"100%\">\n";
    echo "              <tr>\n";
    echo "                <td class=\"subhead\" align=\"left\">", gettext("Hidden by redaction"), "</td>\n";
    echo "                <td class=\"subhead\" align=\"left\">", gettext("Threads"), "</td>\n";
    echo "                <td class=\"subhead\" align=\"left\">", gettext("Comments"), "</td>\n";
    echo "                <td class=\"subhead\" align=\"left\">", gettext("Tags"), "</td>\n";
    echo "                <td class=\"subhead\" align=\"left\">", gettext("Hidden"), "</td>\n";
    echo "                <td class=\"subhead\" align=\"left\">&nbsp;</td>\n";
    echo "              </tr>\n";

    foreach ($hidden_origins as $hidden_origin => $hidden) {

        echo "              <tr>\n";
        echo "                <td align=\"left\">", htmlentities_array($hidden_origin), "</td>\n";
        echo "                <td align=\"left\">", intval($hidden['threads']), "</td>\n";
        echo "                <td align=\"left\">", intval($hidden['posts']), "</td>\n";
        echo "                <td align=\"left\">", intval($hidden['tags']), "</td>\n";
        echo "                <td align=\"left\">", ($hidden['hidden'] > 0 ? format_date_time($hidden['hidden']) : '&nbsp;'), "</td>\n";
        echo "                <td align=\"right\">";
        echo "<form accept-charset=\"utf-8\" name=\"f_restore\" action=\"admin_instance_redact.php\" method=\"post\" target=\"_self\" style=\"margin: 0\">";
        echo form_csrf_token_field(), form_input_hidden('webtag', htmlentities_array($webtag)), form_input_hidden('origin', htmlentities_array($hidden_origin));
        echo form_submit('restore', gettext("Restore")), "</form></td>\n";
        echo "              </tr>\n";
    }

    echo "              <tr><td align=\"left\" colspan=\"6\"><span class=\"smallertext\">", gettext("Restore puts back what a Hide redaction changed: hidden threads are undeleted, and comments and tags get their original approval back. Anything a moderator has dealt with by hand since is left alone. Deleted content cannot be restored."), "</span></td></tr>\n";
    echo "              <tr><td align=\"left\" colspan=\"6\">&nbsp;</td></tr>\n";
    echo "            </table>\n";
    echo "          </td>\n";
    echo "        </tr>\n";
    echo "      </table>\n";
    echo "    </td>\n";
    echo "  </tr>\n";
    echo "</table>\n";
}

if (sizeof($origins) > 0) {

    echo "<br />\n";
    echo "<table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
    echo "  <tr>\n";
    echo "    <td align=\"left\">\n";
    echo "      <table class=\"box\" width=\"100%\">\n";
    echo "        <tr>\n";
    echo "          <td align=\"left\" class=\"posthead\">\n";
    echo "            <table class=\"posthead\" width=\"100%\">\n";
    echo "              <tr><td class=\"subhead\" align=\"left\">", gettext("Origins with imported content here"), "</td><td class=\"subhead\" align=\"left\">", gettext("Comments"), "</td></tr>\n";

    foreach ($origins as $origin_name => $origin_count) {
        echo "              <tr><td align=\"left\">", htmlentities_array($origin_name), "</td><td align=\"left\">", intval($origin_count), "</td></tr>\n";
    }

    echo "              <tr><td align=\"left\" colspan=\"2\">&nbsp;</td></tr>\n";
    echo "            </table>\n";
    echo "          </td>\n";
    echo "        </tr>\n";
    echo "      </table>\n";
    echo "    </td>\n";
    echo "  </tr>\n";
    echo "</table>\n";
}

echo "</div>\n";

html_draw_bottom();
