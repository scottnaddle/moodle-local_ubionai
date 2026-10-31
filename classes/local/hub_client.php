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

namespace local_ubionai\local;

/**
 * Stub client for the UBION AI hub.
 *
 * No network I/O is performed in this iteration. The token parameter is accepted for the future
 * registration protocol but is not logged, returned or stored anywhere by this class.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hub_client {
    /**
     * Whether a hub URL has been configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return trim((string) get_config('local_ubionai', 'hub_url')) !== '';
    }

    /**
     * Register the site with the hub.
     *
     * Not implemented this iteration: returns a status array and performs no network I/O.
     *
     * @param string $siteurl the site's public URL
     * @param string $token the web service token (handled internally, never returned or logged)
     * @return array ['ok' => bool, 'status' => string]
     */
    public static function register(string $siteurl, string $token): array {
        $huburl = trim((string) get_config('local_ubionai', 'hub_url'));
        if ($huburl !== '' && !preg_match('#^https://#i', $huburl)) {
            return ['ok' => false, 'status' => 'invalid_hub_url'];
        }

        return ['ok' => false, 'status' => 'not_implemented'];
    }
}
