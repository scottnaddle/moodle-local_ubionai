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
use local_ubionai\local\ip_validator;
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
    'regcode' => '',
    'regcode-file' => '',
    'revoke-legacy' => false,
], ['h' => 'help']);

$actioncount = (int) !empty($options['status']) + (int) !empty($options['connect'])
    + (int) !empty($options['disconnect']);
if (!empty($options['help'])) {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(0);
}
if ($actioncount !== 1 || !empty($unrecognised)) {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(2);
}

$regcode = trim((string) $options['regcode']);
$regcodefile = (string) $options['regcode-file'];
if ($regcode !== '' && $regcodefile !== '') {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(2);
}
if (($regcode !== '' || $regcodefile !== '') && empty($options['connect'])) {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(2);
}
if (trim((string) $options['iprestriction']) !== '' && empty($options['connect'])) {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(2);
}
if (!empty($options['revoke-legacy']) && empty($options['connect'])) {
    echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
    exit(2);
}

$code = '';
$codesupplied = false;
if ($regcode !== '') {
    $code = $regcode;
    $codesupplied = true;
    cli_writeln(get_string('cli_regcode_argv_warning', 'local_ubionai'));
} else if ($regcodefile !== '') {
    $codesupplied = true;
    $raw = false;
    if ($regcodefile === '-') {
        $raw = stream_get_contents(STDIN, 1024);
    } else {
        $stat = @stat($regcodefile);
        if (
            $stat === false || (($stat['mode'] & 0170000) !== 0100000)
                || !is_readable($regcodefile)
        ) {
            echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
            exit(2);
        }
        if (($stat['mode'] & 0077) !== 0) {
            cli_writeln(get_string('cli_regcode_perms_warning', 'local_ubionai'));
        }
        $raw = @file_get_contents($regcodefile, false, null, 0, 1024);
    }
    if ($raw === false) {
        echo get_string('cli_usage', 'local_ubionai') . PHP_EOL;
        exit(2);
    }
    $firstline = strtok($raw, "\r\n");
    $code = trim($firstline === false ? '' : $firstline);
}
unset($raw, $firstline, $regcode, $options['regcode']);

\core\session\manager::set_user(get_admin());

try {
    if (!empty($options['status'])) {
        $status = provisioner::status();
        foreach ($status as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? get_string('yes') : get_string('no');
            } else if ($value === null) {
                $value = '-';
            } else if (
                $key === 'token_timecreated' || $key === 'token_lastaccess'
                    || $key === 'connected_at' || $key === 'hub_registered_at'
            ) {
                $value = userdate((int) $value);
            }
            cli_writeln($key . ': ' . $value);
        }
    } else if (!empty($options['connect'])) {
        try {
            $iprestriction = ip_validator::normalise((string) $options['iprestriction']);
        } catch (\invalid_parameter_exception $e) {
            echo get_string('cli_iprestriction_invalid', 'local_ubionai', $e->getMessage()) . PHP_EOL;
            exit(2);
        }
        $result = provisioner::provision($iprestriction, !empty($options['revoke-legacy']));
        foreach ($result as $key => $value) {
            if ($key === 'skipped') {
                $value = $value ? implode(', ', $value) : 'none';
            } else if (is_bool($value)) {
                $value = $value ? get_string('yes') : get_string('no');
            }
            cli_writeln($key . ': ' . $value);
        }
        if ($result['legacy_untracked'] > 0) {
            cli_writeln(get_string('token_legacy_kept', 'local_ubionai', $result['legacy_untracked']));
        }
        cli_writeln(hub_client::is_configured()
            ? get_string('hub_configured', 'local_ubionai')
            : get_string('hub_notconfigured', 'local_ubionai'));

        if ($codesupplied) {
            $registration = hub_client::register($code);
            unset($code);
            cli_writeln('hub_status: ' . $registration['status']);
            if ($registration['siteid'] !== null) {
                cli_writeln('hub_siteid: ' . $registration['siteid']);
            }
            if ($registration['status'] !== 'registered') {
                exit(3);
            }
        }
    } else {
        $result = provisioner::disconnect();
        cli_writeln('tokensdeleted: ' . (int) $result['deleted']);
        cli_writeln('tokensuntracked: ' . (int) $result['untracked']);
        if ($result['untracked'] > 0) {
            cli_writeln(get_string('disconnect_untracked_warning', 'local_ubionai', $result['untracked']));
        }
    }
} catch (\Throwable $e) {
    cli_error($e->getMessage(), 1);
}
