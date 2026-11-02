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
 * Admin page for the UBION AI connection.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_ubionai\event\site_connected;
use local_ubionai\event\site_disconnected;
use local_ubionai\form\connect_form;
use local_ubionai\local\hub_client;
use local_ubionai\local\provisioner;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_ubionai_connection');
require_capability('local/ubionai:manageconnection', context_system::instance());

$pageurl = new moodle_url('/local/ubionai/connect.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && data_submitted()) {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'connect') {
        $result = provisioner::provision();
        $hubconfigured = hub_client::is_configured();
        $regcode = trim(optional_param('regcode', '', PARAM_RAW_TRIMMED));
        $registration = null;
        $tokenaction = (string) $result['token_action'];
        $message = get_string('connect_success', 'local_ubionai');
        if ($tokenaction === 'reused') {
            $message = get_string('connect_success_reused', 'local_ubionai');
        } else if ($tokenaction === 'rotated') {
            $message = get_string('connect_success_rotated', 'local_ubionai');
        }
        $type = notification::NOTIFY_SUCCESS;

        if ($hubconfigured && $regcode !== '') {
            $registration = hub_client::register($regcode);
            $message = get_string('hub_status_' . $registration['status'], 'local_ubionai');
            $type = $registration['ok'] ? notification::NOTIFY_SUCCESS : notification::NOTIFY_WARNING;

            $egressip = (string) get_config('local_ubionai', 'hub_egress_ip');
            if ($registration['ok'] && $egressip !== '') {
                \core\notification::add(
                    get_string('hub_egress_hint', 'local_ubionai', $egressip),
                    \core\notification::INFO
                );
            }
        } else if ($hubconfigured) {
            if (!hub_client::is_registered()) {
                $message = get_string('connect_hub_needcode', 'local_ubionai');
                $type = notification::NOTIFY_WARNING;
            }
        } else {
            $message = get_string('connect_success_nohub', 'local_ubionai');
            $type = notification::NOTIFY_INFO;
        }
        unset($regcode);

        $event = site_connected::create_from_service((int) $result['serviceid'], [
            'hubconfigured' => $hubconfigured,
            'tokencreated' => (bool) $result['token_created'],
            'tokenaction' => (string) $result['token_action'],
            'functions_total' => (int) $result['functions_total'],
            'hubregistered' => (bool) ($registration['ok'] ?? false),
            'hubstatus' => (string) ($registration['status'] ?? ''),
        ]);
        $event->trigger();

        if ($result['legacy_untracked'] > 0) {
            \core\notification::add(
                get_string('token_legacy_kept', 'local_ubionai', $result['legacy_untracked']),
                \core\notification::INFO
            );
        }

        redirect($pageurl, $message, null, $type);
    }

    if ($action === 'disconnect') {
        $result = provisioner::disconnect();
        unset_config('connected_at', 'local_ubionai');
        unset_config('hub_siteid', 'local_ubionai');
        unset_config('hub_mcp_endpoint', 'local_ubionai');
        unset_config('hub_egress_ip', 'local_ubionai');
        unset_config('hub_registered_at', 'local_ubionai');
        unset_config('hub_mcp_bearer', 'local_ubionai');

        $status = provisioner::status();
        $event = site_disconnected::create_from_service((int) ($status['serviceid'] ?? 0), [
            'tokensdeleted' => (int) $result['deleted'],
            'tokensuntracked' => (int) $result['untracked'],
        ]);
        $event->trigger();

        if (hub_client::is_configured()) {
            \core\notification::add(
                get_string('disconnect_hub_note', 'local_ubionai'),
                \core\notification::INFO
            );
        }
        if ($result['untracked'] > 0) {
            \core\notification::add(
                get_string('disconnect_untracked_warning', 'local_ubionai', $result['untracked']),
                \core\notification::WARNING
            );
        }

        redirect(
            $pageurl,
            get_string('disconnect_success', 'local_ubionai', $result['deleted']),
            null,
            notification::NOTIFY_SUCCESS
        );
    }

    redirect($pageurl, get_string('invalidaction'), null, notification::NOTIFY_WARNING);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && optional_param('action', '', PARAM_ALPHA) !== '') {
    redirect($pageurl, get_string('invalidaction'), null, notification::NOTIFY_WARNING);
}

$status = provisioner::status();
$connected = $status['tokens_tracked'] > 0 && !empty($status['token_valid'])
    && $status['user_authorised'] && $status['serviceid'] !== null;

$PAGE->set_title(get_string('connection', 'local_ubionai'));
$PAGE->set_heading(get_string('connection', 'local_ubionai'));

echo $OUTPUT->header();

if ($status['token_count'] > 0 && !$status['hub_configured']) {
    echo $OUTPUT->notification(
        get_string('token_warning_unused', 'local_ubionai'),
        notification::NOTIFY_WARNING
    );
}
if ($status['tokens_untracked'] > 0) {
    echo $OUTPUT->notification(
        get_string('status_untracked_tokens', 'local_ubionai', $status['tokens_untracked']),
        notification::NOTIFY_WARNING
    );
}
if (!$status['hub_configured']) {
    echo $OUTPUT->notification(
        get_string('hub_notconfigured', 'local_ubionai'),
        notification::NOTIFY_INFO
    );
}
echo $OUTPUT->notification(
    get_string('connect_warning', 'local_ubionai'),
    notification::NOTIFY_WARNING
);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = [
    [get_string('connectionstatus', 'local_ubionai'),
        $connected ? get_string('status_connected', 'local_ubionai') : get_string('status_notconnected', 'local_ubionai')],
    [get_string('status_webservices', 'local_ubionai'),
        $status['webservices_enabled'] ? get_string('yes') : get_string('no')],
    [get_string('status_rest', 'local_ubionai'),
        $status['rest_enabled'] ? get_string('yes') : get_string('no')],
    [get_string('status_serviceuser', 'local_ubionai'),
        $status['userid'] !== null ? provisioner::USERNAME . ' (#' . $status['userid'] . ')' : '-'],
    [get_string('status_role', 'local_ubionai'),
        $status['roleid'] !== null ? provisioner::ROLE . ' (#' . $status['roleid'] . ')' : '-'],
    [get_string('status_service', 'local_ubionai'),
        $status['serviceid'] !== null ? provisioner::SERVICE . ' (#' . $status['serviceid'] . ')' : '-'],
    [get_string('status_functions', 'local_ubionai'), $status['functions_total']],
    [get_string('status_authorised', 'local_ubionai'),
        $status['user_authorised'] ? get_string('yes') : get_string('no')],
    [get_string('status_tokens', 'local_ubionai'), $status['token_count']],
    [get_string('status_tokenvalid', 'local_ubionai'),
        $status['token_valid'] ? get_string('yes') : get_string('no')],
    [get_string('status_tokencreated', 'local_ubionai'),
        $status['token_timecreated'] !== null ? userdate($status['token_timecreated']) : get_string('status_never', 'local_ubionai')],
    [get_string('status_tokenlastaccess', 'local_ubionai'),
        $status['token_lastaccess'] !== null ? userdate($status['token_lastaccess']) : get_string('status_never', 'local_ubionai')],
    [get_string('hub_configured', 'local_ubionai'),
        $status['hub_configured'] ? get_string('yes') : get_string('no')],
];

if ($status['hub_registered']) {
    $table->data[] = [get_string('hub_siteid', 'local_ubionai'), $status['hub_siteid']];
    $table->data[] = [get_string('hub_registered_at', 'local_ubionai'),
        $status['hub_registered_at'] !== null ? userdate($status['hub_registered_at']) : '-'];
    $table->data[] = [get_string('hub_mcp_endpoint', 'local_ubionai'), $status['hub_mcp_endpoint']];
    $table->data[] = [get_string('hub_egress_ip', 'local_ubionai'),
        ($status['hub_egress_ip'] ?? '') !== '' ? $status['hub_egress_ip'] : '-'];
}

echo html_writer::table($table);

$connectbutton = new single_button(
    new moodle_url($pageurl, ['action' => 'connect']),
    get_string('connect', 'local_ubionai'),
    'post'
);
$disconnectbutton = new single_button(
    new moodle_url($pageurl, ['action' => 'disconnect']),
    get_string('disconnect', 'local_ubionai'),
    'post'
);
$disconnectbutton->add_confirm_action(get_string('disconnectconfirm', 'local_ubionai'));

echo html_writer::start_div('local_ubionai_actions');
if ($status['hub_configured'] && !$status['hub_registered']) {
    $form = new connect_form($pageurl);
    $form->display();
} else {
    echo $OUTPUT->render($connectbutton);
}
echo $OUTPUT->render($disconnectbutton);
echo html_writer::end_div();

echo $OUTPUT->footer();
