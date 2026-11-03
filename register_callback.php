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
 * Hub domain ownership callback: GET ?nonce=... (registration contract §2.3). No session.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing -- answered to the hub without a session by design.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

[$status, $body] = \local_ubionai\registration\callback::handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    optional_param('nonce', '', PARAM_RAW_TRIMMED)
);

http_response_code($status);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if ($status === 405) {
    header('Allow: GET');
}
echo json_encode($body, JSON_UNESCAPED_SLASHES);
