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
require_once 'lboot.php';

// Required includes
require_once BH_INCLUDE_PATH . 'constants.inc.php';
require_once BH_INCLUDE_PATH . 'format.inc.php';
require_once BH_INCLUDE_PATH . 'header.inc.php';
require_once BH_INCLUDE_PATH . 'html.inc.php';
require_once BH_INCLUDE_PATH . 'light.inc.php';
require_once BH_INCLUDE_PATH . 'server.inc.php';
require_once BH_INCLUDE_PATH . 'session.inc.php';
require_once BH_INCLUDE_PATH . 'user.inc.php';
// End Required includes

// Default final URI if one isn't specified.
$final_uri = null;

if (isset($_REQUEST['final_uri']) && strlen(trim($_REQUEST['final_uri'])) > 0) {

    $available_files_preg = implode("|^", array_map('preg_quote_callback', get_light_mode_files()));

    if (preg_match("/^$available_files_preg/u", trim($_REQUEST['final_uri'])) > 0) {
        $final_uri = sprintf('&final_uri=%s', rawurlencode(href_cleanup_query_keys($_REQUEST['final_uri'])));
    }
}

// "Log out all devices": ask first (llogout.php?all=1), then end every
// session and "remember me" token of this user, this browser included.
if (isset($_POST['logout_all']) && session::logged_in()) {

    user_logout_everywhere($_SESSION['UID']);

    session::end();

    html_set_cookie("user_logon", "", time() - YEAR_IN_SECONDS);
    html_set_cookie("user_token", "", time() - YEAR_IN_SECONDS);

    header_redirect("llogon.php?webtag=$webtag");
    exit;
}

if (isset($_GET['all']) && session::logged_in()) {

    light_html_draw_top();

    light_navigation_bar();

    light_html_display_msg(gettext("Log out all devices"), gettext("Ends your session on every browser and device you are logged on with, including this one, and cancels all \"remember me\" logons. You will have to log on again everywhere. Use this if you lost a device or think somebody else has your session."), 'llogout.php', 'post', array('logout_all' => gettext("Log out all devices")));

    light_html_draw_bottom();

    exit;
}

// Forget this device's "remember me" token, not just the session: a copy of
// the cookie must not keep working after logging out (same as logout.php).
if (session::logged_in() && ($logout_token = html_get_cookie('user_token')) && is_string($logout_token)) {
    user_delete_token($_SESSION['UID'], $logout_token);
}

session::end();

html_set_cookie("user_logon", "", time() - YEAR_IN_SECONDS);
html_set_cookie("user_token", "", time() - YEAR_IN_SECONDS);

header_redirect("llogon.php?webtag=$webtag$final_uri");