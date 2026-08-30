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
require_once BH_INCLUDE_PATH . 'form.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'header.inc.php';
require_once BH_INCLUDE_PATH . 'html.inc.php';
require_once BH_INCLUDE_PATH . 'session.inc.php';
require_once BH_INCLUDE_PATH . 'tag.inc.php';
require_once BH_INCLUDE_PATH . 'word_filter.inc.php';
// End Required includes

// Check we're logged in correctly
if (!session::logged_in()) {

    $request_uri = rawurlencode(get_request_uri());
    header_redirect("logon.php?webtag=$webtag&final_uri=$request_uri");
}

// Check we have Admin / Moderator access
if (!session::check_perm(USER_PERM_ADMIN_TOOLS, 0) && !session::check_perm(USER_PERM_FORUM_TOOLS, 0, 0) && !session::get_folders_by_perm(USER_PERM_FOLDER_MODERATE)) {
    html_draw_error(gettext("You do not have permission to use this section."));
}

// Perform additional admin login.
admin_check_credentials();

$error_msg_array = array();

// Page number
if (isset($_GET['page']) && is_numeric($_GET['page'])) {
    $page = ($_GET['page'] > 0) ? intval($_GET['page']) : 1;
} else {
    $page = 1;
}

if (isset($_POST['cancel'])) {
    header_redirect("admin_tag_approve.php?webtag=$webtag");
    exit;
}

// Selected tag ids
if (isset($_POST['process']) && is_array($_POST['process'])) {
    $process_tags = array_filter($_POST['process'], 'is_numeric');
} else {
    $process_tags = array();
}

if (isset($_POST['approve_tags'])) {

    if (sizeof($process_tags) > 0) {

        foreach ($process_tags as $approve_tagid) {

            if (tag_approve($approve_tagid, $_SESSION['UID'])) {
                admin_add_log_entry(APPROVED_TAG, array($approve_tagid));
            } else {
                $error_msg_array[] = gettext("Failed to approve some tags");
            }
        }

        if (sizeof($error_msg_array) == 0) {
            header_redirect("admin_tag_approve.php?webtag=$webtag&page=$page&approve_success=true");
            exit;
        }

    } else {

        $error_msg_array[] = gettext("You must select some tags to approve");
    }

} else if (isset($_POST['reject_tags'])) {

    if (sizeof($process_tags) > 0) {

        if (isset($_POST['reject_confirm']) && $_POST['reject_confirm'] == 'Y') {

            foreach ($process_tags as $reject_tagid) {

                if (tag_delete($reject_tagid)) {
                    admin_add_log_entry(DELETED_TAG, array($reject_tagid));
                } else {
                    $error_msg_array[] = gettext("Failed to reject some tags");
                }
            }

            if (sizeof($error_msg_array) == 0) {
                header_redirect("admin_tag_approve.php?webtag=$webtag&page=$page&reject_success=true");
                exit;
            }

        } else {

            html_draw_top(array('title' => gettext('Reject Tags'), 'class' => 'window_title'));

            html_display_msg(gettext("Reject"), gettext("Are you sure you want to permanently delete the selected tags and remove them from every comment?"), "admin_tag_approve.php", 'post', array(
                'reject_tags' => gettext("Yes"),
                'cancel' => gettext("No")
            ), array(
                'page' => $page,
                'process' => $process_tags,
                'reject_confirm' => 'Y'
            ), '_self', 'center');

            html_draw_bottom();
            exit;
        }

    } else {

        $error_msg_array[] = gettext("You must select some tags to reject");
    }

} else if (isset($_POST['deapprove']) && isset($_POST['deapprove_tagid']) && is_numeric($_POST['deapprove_tagid'])) {

    if (tag_deapprove(intval($_POST['deapprove_tagid']), $_SESSION['UID'])) {
        admin_add_log_entry(DEAPPROVED_TAG, array(intval($_POST['deapprove_tagid'])));
        header_redirect("admin_tag_approve.php?webtag=$webtag&page=$page&deapprove_success=true");
        exit;
    }

    $error_msg_array[] = gettext("Failed to de-approve tag");

} else if (isset($_POST['sync_access'])) {

    $_SESSION['TAG_SYNC_RESULT'] = tag_sync_moderator_folder_access();
    header_redirect("admin_tag_approve.php?webtag=$webtag&sync_success=true");
    exit;
}

html_draw_top(
    array(
        'title' => gettext('Admin - Tag Approval Queue'),
        'class' => 'window_title',
        'main_css' => 'admin.css'
    )
);

echo "<h1>", gettext("Admin"), html_style_image('separator'), gettext("Tag Approval Queue"), "</h1>\n";

if (isset($_GET['approve_success'])) {
    html_display_success_msg(gettext("Selected tags approved"), '86%', 'center');
} else if (isset($_GET['reject_success'])) {
    html_display_success_msg(gettext("Selected tags rejected"), '86%', 'center');
} else if (isset($_GET['deapprove_success'])) {
    html_display_success_msg(gettext("Tag de-approved"), '86%', 'center');
} else if (isset($_GET['sync_success'])) {

    $sync_result = (isset($_SESSION['TAG_SYNC_RESULT']) && is_array($_SESSION['TAG_SYNC_RESULT'])) ? $_SESSION['TAG_SYNC_RESULT'] : array();
    unset($_SESSION['TAG_SYNC_RESULT']);

    if (sizeof($sync_result) > 0) {
        html_display_success_msg(sprintf(gettext("The private Moderators folder is now accessible to: %s"), htmlentities_array(implode(', ', $sync_result))), '86%', 'center');
    } else {
        html_display_success_msg(gettext("Nothing to synchronise - no moderators or admins found."), '86%', 'center');
    }

} else if (sizeof($error_msg_array) > 0) {
    html_display_error_array($error_msg_array, '86%', 'center');
}

$queue = tag_get_approval_queue($page, 20);

echo "<br />\n";
echo "<div align=\"center\">\n";
echo "<form accept-charset=\"utf-8\" name=\"f_queue\" action=\"admin_tag_approve.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  ", form_input_hidden('page', htmlentities_array($page)), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr>\n";

if (sizeof($queue['tag_array']) > 0) {
    echo "                  <td class=\"subhead_checkbox\" align=\"center\" width=\"20\">", form_checkbox("toggle_all", "toggle_all"), "</td>\n";
} else {
    echo "                  <td align=\"left\" class=\"subhead\" width=\"20\">&nbsp;</td>\n";
}

echo "                  <td class=\"subhead\" align=\"left\">", gettext("Tag"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("Requested by"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("On comment"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("In thread"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\" width=\"160\">", gettext("Date/Time"), "</td>\n";
echo "                </tr>\n";

if (sizeof($queue['tag_array']) > 0) {

    foreach ($queue['tag_array'] as $queue_tag) {

        $comment_link = gettext("n/a");

        if (is_numeric($queue_tag['SOURCE_TID']) && is_numeric($queue_tag['SOURCE_PID']) && $queue_tag['SOURCE_TID'] > 0) {
            $comment_link = "<a href=\"messages.php?webtag=$webtag&amp;msg={$queue_tag['SOURCE_TID']}.{$queue_tag['SOURCE_PID']}\" target=\"" . html_get_frame_name('right') . "\">" . gettext("view comment") . "</a>";
        }

        echo "                <tr>\n";
        echo "                  <td align=\"left\" width=\"20\">", form_checkbox("process[]", $queue_tag['TAGID']), "</td>\n";
        echo "                  <td align=\"left\"><b>", htmlentities_array($queue_tag['TAG']), "</b></td>\n";

        if (isset($queue_tag['LOGON'])) {
            echo "                  <td align=\"left\">", word_filter_add_ob_tags(format_user_name($queue_tag['LOGON'], $queue_tag['NICKNAME']), true), "</td>\n";
        } else {
            echo "                  <td align=\"left\">", gettext("Unknown user"), "</td>\n";
        }

        echo "                  <td align=\"left\">", $comment_link, "</td>\n";
        echo "                  <td align=\"left\">", (isset($queue_tag['THREAD_TITLE']) ? word_filter_add_ob_tags($queue_tag['THREAD_TITLE'], true) : gettext("(unknown)")), "</td>\n";
        echo "                  <td align=\"left\">", format_date_time($queue_tag['CREATED']), "</td>\n";
        echo "                </tr>\n";
    }

} else {

    echo "                <tr><td align=\"left\" colspan=\"6\">", gettext("No tags are awaiting approval."), "</td></tr>\n";
}

echo "                <tr><td align=\"left\" colspan=\"6\">&nbsp;</td></tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "    <tr>\n";
echo "      <td class=\"postbody\" align=\"center\">";

html_page_links("admin_tag_approve.php?webtag=$webtag", $page, $queue['tag_count'], 20);

echo "      </td>\n";
echo "    </tr>\n";

if (sizeof($queue['tag_array']) > 0) {

    echo "    <tr>\n";
    echo "      <td align=\"right\" style=\"white-space: nowrap\">";
    echo form_submit('approve_tags', gettext("Approve")), "&nbsp;";
    echo form_submit('reject_tags', gettext("Reject")), "\n";
    echo "      </td>\n";
    echo "    </tr>\n";
}

echo "    <tr><td align=\"left\">&nbsp;</td></tr>\n";
echo "  </table>\n";
echo "</form>\n";

// Approved tags
$approved = tag_get_approved(1, 500);

echo "<form accept-charset=\"utf-8\" name=\"f_approved\" action=\"admin_tag_approve.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  ", form_input_hidden('page', htmlentities_array($page)), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr>\n";
echo "                  <td class=\"subhead\" align=\"left\">", gettext("Approved tag"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\" width=\"100\">", gettext("Uses"), "</td>\n";
echo "                  <td class=\"subhead\" align=\"left\" width=\"120\">&nbsp;</td>\n";
echo "                </tr>\n";

if (isset($approved['tag_array']) && sizeof($approved['tag_array']) > 0) {

    foreach ($approved['tag_array'] as $approved_tag) {

        echo "                <tr>\n";
        echo "                  <td align=\"left\">", htmlentities_array($approved_tag['TAG']), "</td>\n";
        echo "                  <td align=\"left\">", intval($approved_tag['USE_COUNT']), "</td>\n";
        echo "                  <td align=\"left\">", form_submit('deapprove', gettext("De-approve"), "onclick=\"this.form.deapprove_tagid.value='" . intval($approved_tag['TAGID']) . "';\""), "</td>\n";
        echo "                </tr>\n";
    }

} else {

    echo "                <tr><td align=\"left\" colspan=\"3\">", gettext("No approved tags yet."), "</td></tr>\n";
}

echo "                <tr><td align=\"left\" colspan=\"3\">&nbsp;</td></tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "  </table>\n";
echo "  ", form_input_hidden('deapprove_tagid', ''), "\n";
echo "</form>\n";

// Moderator folder access
echo "<form accept-charset=\"utf-8\" name=\"f_sync\" action=\"admin_tag_approve.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"86%\">\n";
echo "    <tr><td align=\"left\"><span class=\"smallertext\">", gettext("Tag requests are posted to the private Moderators folder. If you have added new moderators, use this to grant them access to it."), "</span></td></tr>\n";
echo "    <tr><td align=\"left\">", form_submit('sync_access', gettext("Sync moderator access")), "</td></tr>\n";
echo "  </table>\n";
echo "</form>\n";

echo "</div>\n";

html_draw_bottom();
