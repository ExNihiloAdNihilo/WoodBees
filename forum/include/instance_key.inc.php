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
 * WoodBees instance signing key (Ed25519, PHP libsodium).
 *
 * The keypair is this install's federation identity: bundles exported by
 * admin_export.php are signed with it and other instances pin its public key
 * on first import (trust on first use). The instance_identifier setting is
 * only a human-readable label - it can change, the key must not.
 *
 * The secret key lives in a file OUTSIDE the web root (default
 * /var/lib/woodbees/keys, a Docker volume in the bundled stack; override with
 * the WOODBEES_KEY_DIR environment variable) and is never exported.
 *
 * Deliberately dependency-free so the installer / upgrade script can call it.
 */

define('INSTANCE_KEY_DEFAULT_DIR', '/var/lib/woodbees/keys');
define('INSTANCE_KEY_FILENAME', 'instance_ed25519.secret');

/**
 * True when PHP's libsodium extension (bundled since PHP 7.2) is available.
 *
 * @return bool
 */
function instance_key_sodium_available()
{
    return function_exists('sodium_crypto_sign_detached')
        && function_exists('sodium_crypto_sign_verify_detached')
        && function_exists('sodium_crypto_sign_keypair');
}

/**
 * Directory holding the secret key file.
 *
 * @return string
 */
function instance_key_dir()
{
    $dir = getenv('WOODBEES_KEY_DIR');

    if (!is_string($dir) || strlen(trim($dir)) == 0) {
        $dir = INSTANCE_KEY_DEFAULT_DIR;
    }

    return rtrim(trim($dir), '/\\');
}

/**
 * Full path of the secret key file.
 *
 * @return string
 */
function instance_key_path()
{
    return instance_key_dir() . DIRECTORY_SEPARATOR . INSTANCE_KEY_FILENAME;
}

/**
 * Load the 64-byte Ed25519 secret key, or false when missing / unreadable.
 *
 * @return string|false raw secret key
 */
function instance_key_load_secret()
{
    static $secret_key = null;

    if ($secret_key !== null) return $secret_key;

    if (!instance_key_sodium_available()) return false;

    $path = instance_key_path();

    if (!@is_readable($path)) return false;

    if (($data = @file_get_contents($path)) === false) return false;

    $raw = base64_decode(trim($data), true);

    if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return false;

    $secret_key = $raw;

    return $secret_key;
}

/**
 * Make sure a keypair exists, generating one when there is none.
 *
 * Never overwrites an existing key file (doing so would change this
 * instance's identity and every peer would then reject its bundles).
 *
 * @param string $error_str set to a human-readable reason on failure
 * @return bool true when a usable key exists afterwards
 */
function instance_key_ensure(&$error_str = null)
{
    if (!instance_key_sodium_available()) {
        $error_str = 'The PHP sodium extension is not available.';
        return false;
    }

    $path = instance_key_path();

    if (@file_exists($path)) {

        if (instance_key_load_secret() !== false) return true;

        $error_str = sprintf('The instance key file %s exists but could not be read or is invalid. It has NOT been replaced - fix its permissions (it must be readable by the web server user) or move it aside to generate a new identity.', $path);
        return false;
    }

    $dir = instance_key_dir();

    if (!@is_dir($dir) && !@mkdir($dir, 0700, true)) {
        $error_str = sprintf('Could not create the instance key directory %s. Create it outside the web root, writable by the web server user, or set WOODBEES_KEY_DIR.', $dir);
        return false;
    }

    if (!@is_writable($dir)) {
        $error_str = sprintf('The instance key directory %s is not writable by the web server user.', $dir);
        return false;
    }

    $keypair = sodium_crypto_sign_keypair();

    $secret_key = sodium_crypto_sign_secretkey($keypair);

    $tmp_path = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $old_umask = umask(0077);

    $written = @file_put_contents($tmp_path, base64_encode($secret_key) . "\n", LOCK_EX);

    umask($old_umask);

    if ($written === false) {
        $error_str = sprintf('Could not write the instance key file in %s.', $dir);
        return false;
    }

    @chmod($tmp_path, 0600);

    // When run as root (e.g. `docker compose exec web php ...`), hand the
    // file to whoever owns the key directory so php-fpm can still read it.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($dir_owner = @fileowner($dir)) !== false) {
        @chown($tmp_path, $dir_owner);
        if (($dir_group = @filegroup($dir)) !== false) @chgrp($tmp_path, $dir_group);
    }

    // link() fails if another request created the key in the meantime, so
    // two concurrent first uses can never end up with different keys.
    if (!@link($tmp_path, $path)) {

        @unlink($tmp_path);

        if (instance_key_load_secret() !== false) return true;

        $error_str = sprintf('Could not install the instance key file %s.', $path);
        return false;
    }

    @unlink($tmp_path);

    return instance_key_load_secret() !== false;
}

/**
 * This instance's raw 32-byte public key, or false when there is no key.
 *
 * @return string|false
 */
function instance_key_public()
{
    if (($secret_key = instance_key_load_secret()) === false) return false;

    return sodium_crypto_sign_publickey_from_secretkey($secret_key);
}

/**
 * Sign a message with this instance's secret key.
 *
 * @param string $message
 * @return string|false raw 64-byte detached signature
 */
function instance_key_sign($message)
{
    if (($secret_key = instance_key_load_secret()) === false) return false;

    return sodium_crypto_sign_detached($message, $secret_key);
}

/**
 * Verify a detached signature against a raw public key.
 *
 * @param string $signature raw signature
 * @param string $message
 * @param string $public_key raw 32-byte public key
 * @return bool
 */
function instance_key_verify($signature, $message, $public_key)
{
    if (!instance_key_sodium_available()) return false;

    if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) return false;

    if (!is_string($public_key) || strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;

    try {
        return sodium_crypto_sign_verify_detached($signature, $message, $public_key);
    } catch (Exception $e) {
        return false;
    } catch (Error $e) {
        return false;
    }
}

/**
 * Human-comparable fingerprint of a raw public key: SHA-256, upper-case hex
 * in groups of four. Admins compare this out of band before trusting a key.
 *
 * @param string $public_key raw public key
 * @return string
 */
function instance_key_fingerprint($public_key)
{
    return 'SHA256:' . implode(' ', str_split(strtoupper(hash('sha256', $public_key)), 4));
}
