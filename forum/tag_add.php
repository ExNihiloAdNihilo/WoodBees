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
require_once BH_INCLUDE_PATH . 'constants.inc.php';
require_once BH_INCLUDE_PATH . 'form.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'header.inc.php';
require_once BH_INCLUDE_PATH . 'html.inc.php';
require_once BH_INCLUDE_PATH . 'messages.inc.php';
require_once BH_INCLUDE_PATH . 'session.inc.php';
require_once BH_INCLUDE_PATH . 'tag.inc.php';
require_once BH_INCLUDE_PATH . 'thread.inc.php';
// End Required includes

// Check we're logged in correctly
if (!session::logged_in()) {
    html_guest_error();
}

if (!tag_enabled()) {
    html_draw_error(gettext("Tagging is not enabled on this forum."));
}

$error_msg_array = array();

$show_sigs = session::show_sigs();

$tid = null;
$pid = null;
$t_fid = null;
$preview_message = null;

if (isset($_POST['msg']) && validate_msg($_POST['msg'])) {

    $msg = $_POST['msg'];

} else if (isset($_GET['msg']) && validate_msg($_GET['msg'])) {

    $msg = $_GET['msg'];

} else {

    html_draw_error(gettext("No comment specified"));
}

list($tid, $pid) = explode(".", $msg);

if (!$t_fid = thread_get_folder_fid($tid)) {
    html_draw_error(gettext("The requested thread could not be found or access was denied."));
}

if (isset($_POST['return_msg']) && validate_msg($_POST['return_msg'])) {
    $return_msg = $_POST['return_msg'];
} else if (isset($_GET['return_msg']) && validate_msg($_GET['return_msg'])) {
    $return_msg = $_GET['return_msg'];
} else {
    $return_msg = $msg;
}

if (isset($_POST['cancel'])) {

    header_redirect("discussion.php?webtag=$webtag&msg=$return_msg");
    exit;
}

if (session::check_perm(USER_PERM_EMAIL_CONFIRM, 0)) {

    html_email_confirmation_error();
    exit;
}

if (!session::check_perm(USER_PERM_POST_READ, $t_fid)) {
    html_draw_error(gettext("You do not have permission to tag comments in this folder."));
}

if (!$thread_data = thread_get($tid)) {
    html_draw_error(gettext("The requested thread could not be found or access was denied."));
}

if (($preview_message = messages_get($tid, $pid, 1)) === false) {
    html_draw_error(gettext("That comment does not exist in this thread!"));
}

$preview_message['CONTENT'] = message_get_content($tid, $pid);

if (isset($_POST['add_tag']) || isset($_POST['tag'])) {

    $t_tag = isset($_POST['tag']) ? $_POST['tag'] : '';

    $t_tag_normalised = tag_normalise($t_tag);

    if (strlen($t_tag_normalised) == 0) {

        $error_msg_array[] = gettext("Enter a tag using lower-case letters, digits and single spaces (30 characters maximum).");

    } else {

        $result = tag_request($t_tag_normalised, $tid, $pid, $_SESSION['UID']);

        switch ($result) {

            case TAG_REQUEST_REQUESTED:
            case TAG_REQUEST_PENDING:
            case TAG_REQUEST_LINKED:
                header_redirect("tag_add.php?webtag=$webtag&msg=$msg&return_msg=$return_msg&added=$result");
                exit;

            case TAG_REQUEST_ALREADY:
                $error_msg_array[] = gettext("That comment already carries that tag.");
                break;

            default:
                $error_msg_array[] = gettext("That tag could not be added.");
                break;
        }
    }
}

if (isset($_GET['added']) && is_numeric($_GET['added'])) {

    switch (intval($_GET['added'])) {

        case TAG_REQUEST_REQUESTED:
            $success_msg = gettext("Tag submitted. It will appear on the comment once a moderator approves it.");
            break;

        case TAG_REQUEST_PENDING:
            $success_msg = gettext("Tag added to the comment. It is still awaiting moderator approval, so it is not public yet.");
            break;

        case TAG_REQUEST_LINKED:
            $success_msg = gettext("Tag added to the comment.");
            break;
    }
}

$viewer_can_moderate = tag_user_can_moderate();

$current_tags = tag_get_for_post($tid, $pid, $_SESSION['UID'], $viewer_can_moderate);

$approved_tags = tag_get_approved(1, 500);

html_draw_top(
    array(
        'title' => gettext('Add Tag'),
        'base_target' => '_blank',
        'class' => 'window_title max_width'
    )
);

echo "<h1>", gettext("Add Tag"), "</h1>\n";

if (isset($success_msg)) {
    html_display_success_msg($success_msg, '720', 'left');
}

if (isset($error_msg_array) && sizeof($error_msg_array) > 0) {
    html_display_error_array($error_msg_array, '720', 'left');
}

echo "<br />\n";
echo "<form accept-charset=\"utf-8\" name=\"f_tag\" action=\"tag_add.php\" method=\"post\" target=\"_self\">\n";
echo "  ", form_csrf_token_field(), "\n";
echo "  ", form_input_hidden('webtag', htmlentities_array($webtag)), "\n";
echo "  ", form_input_hidden('msg', htmlentities_array($msg)), "\n";
echo "  ", form_input_hidden('return_msg', htmlentities_array($return_msg)), "\n";
echo "  <table cellpadding=\"0\" cellspacing=\"0\" width=\"720\">\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" class=\"subhead\">", gettext("Comment being tagged"), "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\"><br />";

message_display($tid, $preview_message, $thread_data['LENGTH'], $pid, $thread_data['FID'], false, false, false, $show_sigs, true);

echo "                  </td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\">&nbsp;</td>\n";
echo "                </tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "    <tr>\n";
echo "      <td align=\"left\">&nbsp;</td>\n";
echo "    </tr>\n";
echo "    <tr>\n";
echo "      <td align=\"left\">\n";
echo "        <table class=\"box\" width=\"100%\">\n";
echo "          <tr>\n";
echo "            <td align=\"left\" class=\"posthead\">\n";
echo "              <table class=\"posthead\" width=\"100%\">\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" class=\"subhead\" colspan=\"2\">", gettext("Tags on this comment"), "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" colspan=\"2\">";

if (sizeof($current_tags) > 0) {

    $current_tag_labels = array();

    foreach ($current_tags as $current_tag) {

        $label = htmlentities_array($current_tag['TAG']);

        if ($current_tag['PENDING']) {
            $label .= " <span class=\"smallertext\">(" . gettext("pending approval") . ")</span>";
        }

        $current_tag_labels[] = $label;
    }

    echo implode(', ', $current_tag_labels);

} else {

    echo "<span class=\"smallertext\">", gettext("None yet."), "</span>";
}

echo "</td>\n";
echo "                </tr>\n";
echo "                <tr>\n";
echo "                  <td align=\"left\" style=\"white-space: nowrap\" width=\"120\" valign=\"top\">", gettext("New tag"), ":</td>\n";
echo "                  <td align=\"left\">", form_input_text('tag', '', 34, TAG_MAX_LENGTH), " ", form_submit('add_tag', gettext("Add")), "<br />\n";
echo "                    <span class=\"smallertext\">", gettext("Lower-case letters, digits and single spaces only, 30 characters maximum. New tags are shown once a moderator approves them."), "</span></td>\n";
echo "                </tr>\n";

if (isset($approved_tags['tag_array']) && sizeof($approved_tags['tag_array']) > 0) {

    echo "                <tr>\n";
    echo "                  <td align=\"left\" valign=\"top\">", gettext("Existing tags"), ":</td>\n";
    echo "                  <td align=\"left\">";

    $existing_tag_buttons = array();

    foreach ($approved_tags['tag_array'] as $approved_tag) {
        $existing_tag_buttons[] = "<button type=\"submit\" name=\"tag\" value=\"" . htmlentities_array($approved_tag['TAG']) . "\" class=\"tag\">" . htmlentities_array($approved_tag['TAG']) . "</button>";
    }

    echo implode(' ', $existing_tag_buttons);
    echo "</td>\n";
    echo "                </tr>\n";
}

echo "                <tr>\n";
echo "                  <td align=\"left\" colspan=\"2\">&nbsp;</td>\n";
echo "                </tr>\n";
echo "              </table>\n";
echo "            </td>\n";
echo "          </tr>\n";
echo "        </table>\n";
echo "      </td>\n";
echo "    </tr>\n";
echo "    <tr>\n";
echo "      <td align=\"left\">&nbsp;</td>\n";
echo "    </tr>\n";
echo "    <tr>\n";
echo "      <td align=\"center\">", form_submit("cancel", gettext("Done")), "</td>\n";
echo "    </tr>\n";
echo "  </table>\n";
echo "</form>\n";

html_draw_bottom();
