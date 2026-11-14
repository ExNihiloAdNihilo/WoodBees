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
// End Required includes

function get_ip_address()
{
    if (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }

    return '';
}

/**
 * The client address as the logon throttle counts it.
 *
 * IPv4 is used as it is. An IPv6 address is reduced to its /64 prefix (one
 * subscriber or site normally holds a whole /64, so counting single addresses
 * would let a client simply rotate through its own range), after dropping a
 * zone id ("fe80::1%eth0") and normalising the spelling ("2001:DB8::1" and
 * "2001:db8:0:0:0:0:0:1" are the same). An IPv4-mapped IPv6 address
 * ("::ffff:192.0.2.1", as dual-stack sockets report IPv4 clients) is counted
 * as the IPv4 address it carries.
 *
 * @param string $ipaddress
 * @return string
 */
function ip_get_throttle_address($ipaddress)
{
    $ipaddress = preg_replace('/%.*$/', '', trim((string) $ipaddress));

    if (strpos($ipaddress, ':') === false) return substr($ipaddress, 0, 45);

    $packed = @inet_pton($ipaddress);

    if ($packed === false || strlen($packed) != 16) return substr($ipaddress, 0, 45);

    if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return inet_ntop(substr($packed, 12));

    return 'v6:' . bin2hex(substr($packed, 0, 8));
}

function check_ip_address($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP);
}

function ipv6_to_ipv4($ipv6)
{
    if (preg_match('/^::ffff:(((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(\.|$)){4})$/i', $ipv6, $matches_array)) {
        return $matches_array[1];
    }

    return $ipv6;
}