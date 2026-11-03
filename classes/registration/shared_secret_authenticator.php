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

use moodle_exception;

/**
 * shared_secret mode (contract §3.1, §7.2): the ubionai-oauth-v1 HMAC format plus an integer kv
 * claim that selects the MAC key (current or next secret version, derived with HKDF).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shared_secret_authenticator implements site_authenticator {
    /** MAC domain separator (same as the legacy format). */
    public const MAC_PREFIX = 'ubionai-oauth-v1.';

    /**
     * Mode name.
     *
     * @return string
     */
    public function mode(): string {
        return 'shared_secret';
    }

    /**
     * Verify the MAC with the key selected by kv.
     *
     * @param string $token signed message
     * @return array claims
     */
    public function verify(string $token): array {
        if (strlen($token) > 8192 || !preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/D', $token, $m)) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        // Only kv is read before the MAC check, to pick the key.
        $claims = json_decode(self::unb64($m[1]), true);
        $kv = is_array($claims) ? ($claims['kv'] ?? null) : null;
        if (!is_int($kv) || ($key = secrets::mac_key($kv)) === null) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        $expected = hash_hmac('sha256', self::MAC_PREFIX . $m[1], $key, true);
        unset($key);
        if (!hash_equals($expected, self::unb64($m[2]))) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        return $claims;
    }

    /**
     * Public parameters.
     *
     * @return array
     */
    public function describe(): array {
        return [
            'mode' => $this->mode(),
            'site_key' => (string) get_config('local_ubionai', 'site_key'),
            'kv' => array_values(array_filter([secrets::current_version(), secrets::next_version()])),
        ];
    }

    /**
     * Sign claims with a raw MAC key (used by tests and interop vectors).
     *
     * @param array $claims claims including kv
     * @param string $key raw MAC key
     * @return string
     */
    public static function sign_with_key(array $claims, string $key): string {
        $body = \local_ubionai\local\oauth::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $body . '.' . \local_ubionai\local\oauth::b64(hash_hmac('sha256', self::MAC_PREFIX . $body, $key, true));
    }

    /**
     * Base64url decode.
     *
     * @param string $data text
     * @return string
     */
    private static function unb64(string $data): string {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
