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
 * Domain ownership callback answered to the hub (registration contract §2.3).
 *
 * 200 only when consent is accepted and a pending registration with this exact nonce exists and
 * is younger than ten minutes. Every other case gives the same 404, so the hub (or anyone else)
 * cannot tell "no consent", "wrong nonce" and "expired" apart.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class callback {
    /**
     * Build the response.
     *
     * @param string $method HTTP method
     * @param string $nonce nonce query parameter
     * @return array{0: int, 1: array} [status, JSON body]
     */
    public static function handle(string $method, string $nonce): array {
        global $CFG;
        if ($method !== 'GET') {
            return [405, ['error' => 'method_not_allowed']];
        }
        $notfound = [404, ['error' => 'unknown_nonce']];
        if (get_config('local_ubionai', 'consent') !== 'accepted' || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $nonce)) {
            return $notfound;
        }
        $pending = registrar::pending();
        if ($pending === null || !hash_equals($pending['nonce'], $nonce)) {
            return $notfound;
        }
        return [200, [
            'typ' => 'ubionai-register-callback/v1',
            'nonce' => $pending['nonce'],
            'challenge' => $pending['challenge'],
            'wwwroot' => (string) $CFG->wwwroot,
            'plugin_version' => registrar::plugin_info()['version'],
            'site_identifier_hash' => registrar::site_identifier_hash(),
        ]];
    }
}
