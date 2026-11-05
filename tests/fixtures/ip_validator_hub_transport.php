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

/**
 * Fixture transport double for hub_client registration tests.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Transport double for hub_client registration tests.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ip_validator_hub_transport extends hub_client {
    /** @var array{body: string, errno: int, httpcode: int} Next response. */
    public static $response = ['body' => '', 'errno' => 0, 'httpcode' => 200];

    /**
     * Return the configured response without making a request.
     *
     * @param string $endpoint the registration endpoint
     * @param string $body the JSON request body
     * @return array{body: string, errno: int, httpcode: int}
     */
    protected static function post_json(string $endpoint, string $body): array {
        return self::$response;
    }
}
