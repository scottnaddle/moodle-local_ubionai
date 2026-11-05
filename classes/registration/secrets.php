<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ubionai\registration;

/**
 * Storage of the hub-issued site secret (registration contract §3.1, §8.1).
 *
 * site_secret_current / site_secret_next are only ever written through \core\encryption::encrypt;
 * no plaintext secret is stored. The per-version message MAC key is never stored: it is derived
 * when needed with HKDF-SHA256(ikm = site_secret, salt = "ubionai-site-v1",
 * info = "oauth-mac|<site_key>|v<n>", L = 32).
 *
 * The secret is used as the hub sends it (the 43-character base64url string, as ASCII bytes).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class secrets {
    /** HKDF salt. */
    public const HKDF_SALT = 'ubionai-site-v1';
    /** MAC key length (bytes). */
    public const MAC_KEY_LENGTH = 32;
    /** Accepted secret format (32 random bytes, base64url; a little slack for longer secrets). */
    public const SECRET_RE = '/\A[A-Za-z0-9_-]{43,128}\z/D';

    /**
     * Store a newly issued secret as "next" (encrypted). The current secret is kept.
     *
     * @param string $secret plaintext secret from the hub
     * @param int $version secret version
     * @return void
     */
    public static function store_next(string $secret, int $version): void {
        if (!preg_match(self::SECRET_RE, $secret) || $version < 1) {
            throw new \coding_exception('Invalid site secret or version');
        }
        set_config('site_secret_next', \core\encryption::encrypt($secret), 'local_ubionai');
        set_config('secret_version_next', $version, 'local_ubionai');
    }

    /**
     * Promote "next" to "current" after the hub confirmed it; the old current secret is deleted.
     *
     * @return void
     */
    public static function promote_next(): void {
        $next = get_config('local_ubionai', 'site_secret_next');
        $version = (int) get_config('local_ubionai', 'secret_version_next');
        if (!is_string($next) || $next === '' || $version < 1) {
            throw new \coding_exception('No next site secret to promote');
        }
        set_config('site_secret_current', $next, 'local_ubionai');
        set_config('secret_version', $version, 'local_ubionai');
        unset_config('site_secret_next', 'local_ubionai');
        unset_config('secret_version_next', 'local_ubionai');
    }

    /**
     * Forget the "next" secret (for example when the hub replaces a pending one).
     *
     * @return void
     */
    public static function discard_next(): void {
        unset_config('site_secret_next', 'local_ubionai');
        unset_config('secret_version_next', 'local_ubionai');
    }

    /**
     * Forget all secrets.
     *
     * @return void
     */
    public static function clear(): void {
        self::discard_next();
        unset_config('site_secret_current', 'local_ubionai');
        unset_config('secret_version', 'local_ubionai');
    }

    /**
     * Current secret version, or null.
     *
     * @return int|null
     */
    public static function current_version(): ?int {
        $version = (int) get_config('local_ubionai', 'secret_version');
        return $version > 0 && self::has('current') ? $version : null;
    }

    /**
     * Next (unconfirmed) secret version, or null.
     *
     * @return int|null
     */
    public static function next_version(): ?int {
        $version = (int) get_config('local_ubionai', 'secret_version_next');
        return $version > 0 && self::has('next') ? $version : null;
    }

    /**
     * Whether a slot holds a secret.
     *
     * @param string $slot current|next
     * @return bool
     */
    public static function has(string $slot): bool {
        $value = get_config('local_ubionai', self::slot_name($slot));
        return is_string($value) && $value !== '';
    }

    /**
     * Decrypt a slot. Only for building a Bearer header or deriving a MAC key; never log or show it.
     *
     * @param string $slot current|next
     * @return string|null plaintext secret
     */
    public static function reveal(string $slot): ?string {
        $value = get_config('local_ubionai', self::slot_name($slot));
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $plain = \core\encryption::decrypt($value);
        } catch (\moodle_exception $e) {
            return null;
        }
        return preg_match(self::SECRET_RE, $plain) ? $plain : null;
    }

    /**
     * MAC key for a secret version (current or next only).
     *
     * @param int $version key version (kv claim)
     * @return string|null 32 raw bytes, or null when no secret of that version is held
     */
    public static function mac_key(int $version): ?string {
        $sitekey = (string) get_config('local_ubionai', 'site_key');
        if ($sitekey === '' || $version < 1) {
            return null;
        }
        $slot = null;
        if ($version === self::current_version()) {
            $slot = 'current';
        } else if ($version === self::next_version()) {
            $slot = 'next';
        }
        if ($slot === null || ($secret = self::reveal($slot)) === null) {
            return null;
        }
        $key = self::derive_mac_key($secret, $sitekey, $version);
        unset($secret);
        return $key;
    }

    /**
     * HKDF-SHA256 derivation shared with the hub (contract §3.1).
     *
     * @param string $secret site secret as issued
     * @param string $sitekey site key
     * @param int $version secret version
     * @return string 32 raw bytes
     */
    public static function derive_mac_key(string $secret, string $sitekey, int $version): string {
        return hash_hkdf('sha256', $secret, self::MAC_KEY_LENGTH, self::hkdf_info($sitekey, $version), self::HKDF_SALT);
    }

    /**
     * HKDF info string.
     *
     * @param string $sitekey site key
     * @param int $version secret version
     * @return string
     */
    public static function hkdf_info(string $sitekey, int $version): string {
        return 'oauth-mac|' . $sitekey . '|v' . $version;
    }

    /**
     * Config name of a slot.
     *
     * @param string $slot current|next
     * @return string
     */
    private static function slot_name(string $slot): string {
        if ($slot !== 'current' && $slot !== 'next') {
            throw new \coding_exception('Unknown secret slot: ' . $slot);
        }
        return 'site_secret_' . $slot;
    }
}
