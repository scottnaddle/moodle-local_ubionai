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
 * CLI connection status and provisioning tool for local_ubionai.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ubionai\local\hub_client;
use local_ubionai\local\provisioner;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'status' => false,
    'connect' => false,
    'disconnect' => false,
    'iprestriction' => '',
], ['h' => 'help']);

$actioncount = (int) !empty($options['status']) + (int) !empty($options['connect'])
    + (int) !empty($options['disconnect']);
if (!empty($options['help']) || $actioncount !== 1 || !empty($unrecognised)) {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(!empty($options['help']) ? 0 : 2);
}

\core\session\manager::set_user(get_admin());

try {
    if (!empty($options['status'])) {
        $status = provisioner::status();
        foreach ($status as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? get_string('yes') : get_string('no');
            } else if ($value === null) {
                $value = '-';
            } else if ($key === 'token_timecreated' || $key === 'token_lastaccess'
                    || $key === 'connected_at') {
                $value = userdate((int) $value);
            }
            cli_writeln($key . ': ' . $value);
        }
    } else if (!empty($options['connect'])) {
        $result = provisioner::provision((string) $options['iprestriction']);
        foreach ($result as $key => $value) {
            if ($key === 'skipped') {
                $value = $value ? implode(', ', $value) : 'none';
            } else if (is_bool($value)) {
                $value = $value ? get_string('yes') : get_string('no');
            }
            cli_writeln($key . ': ' . $value);
        }
        cli_writeln(hub_client::is_configured()
            ? get_string('hub_configured', 'local_ubionai')
            : get_string('hub_notconfigured', 'local_ubionai'));
    } else {
        $deleted = provisioner::disconnect();
        cli_writeln('tokensdeleted: ' . $deleted);
    }
} catch (\Throwable $e) {
    cli_error($e->getMessage(), 1);
}
