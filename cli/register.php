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
 * CLI for the hub self-registration: --status | --retry | --rotate. Never prints secrets.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ubionai\registration\registrar;
use local_ubionai\registration\state;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'status' => false,
    'retry' => false,
    'rotate' => false,
], ['h' => 'help']);

$actioncount = (int) !empty($options['status']) + (int) !empty($options['retry']) + (int) !empty($options['rotate']);
if (!empty($options['help'])) {
    echo get_string('cli_register_usage', 'local_ubionai') . PHP_EOL;
    exit(0);
}
if ($actioncount !== 1 || !empty($unrecognised)) {
    echo get_string('cli_register_usage', 'local_ubionai') . PHP_EOL;
    exit(2);
}

if (!empty($options['retry'])) {
    $result = registrar::retry();
} else if (!empty($options['rotate'])) {
    $result = registrar::rotate();
} else {
    $result = registrar::status()['state'];
}

$status = registrar::status();
$status['limits'] = json_encode($status['limits']);
$status['egress_ips'] = implode(',', $status['egress_ips']);
foreach (['last_attempt_at', 'next_retry_at', 'registered_at'] as $name) {
    $status[$name] = $status[$name] ? gmdate('Y-m-d\TH:i:s\Z', $status[$name]) : '';
}
foreach ($status as $name => $value) {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    cli_writeln(str_pad($name, 20) . ' ' . (string) $value);
}
if ($status['last_error'] !== '' && $status['state'] !== state::REGISTERED) {
    $key = 'reg_error_' . $status['last_error'];
    if (get_string_manager()->string_exists($key, 'local_ubionai')) {
        cli_writeln(get_string($key, 'local_ubionai'));
    }
}

exit(in_array($result, [state::REGISTERED, state::AWAITING_CONSENT, state::CONSENTED, state::DEREGISTERED], true)
    || !empty($options['status']) ? 0 : 1);
