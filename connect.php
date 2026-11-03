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
use local_ubionai\registration\registrar;
use local_ubionai\registration\state;

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

    if ($action === 'regretry' || $action === 'regrotate') {
        if (!is_siteadmin()) {
            throw new moodle_exception('reg_adminonly', 'local_ubionai');
        }
        $regstate = $action === 'regretry' ? registrar::retry() : registrar::rotate();
        redirect(
            $pageurl,
            get_string('reg_result', 'local_ubionai', get_string('reg_state_' . $regstate, 'local_ubionai')),
            null,
            $regstate === state::REGISTERED ? notification::NOTIFY_SUCCESS : notification::NOTIFY_WARNING
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

// Hub self-registration status (site administrators only).
if (is_siteadmin()) {
    $reg = registrar::status();
    echo $OUTPUT->heading(get_string('reg_heading', 'local_ubionai'), 3);
    if (!$reg['enabled']) {
        echo $OUTPUT->notification(get_string('reg_disabled', 'local_ubionai'), notification::NOTIFY_INFO);
    } else {
        if ($reg['url_changed']) {
            echo $OUTPUT->notification(get_string('reg_url_changed', 'local_ubionai'), notification::NOTIFY_WARNING);
        }
        if ($reg['retry_exhausted']) {
            echo $OUTPUT->notification(get_string('reg_retry_exhausted', 'local_ubionai'), notification::NOTIFY_WARNING);
        }
        $regtable = new html_table();
        $regtable->attributes['class'] = 'generaltable';
        $regtable->data = [
            [get_string('reg_state', 'local_ubionai'), get_string('reg_state_' . $reg['state'], 'local_ubionai')],
            [get_string('reg_hub', 'local_ubionai'), s($reg['hub'] ?? '-')],
        ];
        if ($reg['site_key'] !== '') {
            $regtable->data[] = [get_string('reg_site_key', 'local_ubionai'), s($reg['site_key'])];
            $regtable->data[] = [get_string('reg_secret_version', 'local_ubionai'), (string) ($reg['secret_version'] ?? '-')];
        }
        if ($reg['state'] === state::REGISTERED && $reg['tenant_status'] === 'pending') {
            echo $OUTPUT->notification(get_string('reg_tenant_pending', 'local_ubionai'), notification::NOTIFY_WARNING);
        }
        if ($reg['state'] === state::REGISTERED && $reg['grants_kept'] !== null) {
            echo $OUTPUT->notification(
                get_string($reg['grants_kept'] ? 'reg_grants_kept' : 'reg_grants_revoked', 'local_ubionai'),
                $reg['grants_kept'] ? notification::NOTIFY_INFO : notification::NOTIFY_WARNING
            );
        }
        if ($reg['tier'] !== '') {
            $tierkey = 'reg_tier_' . $reg['tier'];
            $statuskey = 'reg_tenant_status_' . $reg['tenant_status'];
            $strings = get_string_manager();
            $regtable->data[] = [get_string('reg_tier', 'local_ubionai'),
                $strings->string_exists($tierkey, 'local_ubionai') ? get_string($tierkey, 'local_ubionai') : s($reg['tier'])];
            $regtable->data[] = [get_string('reg_tenant_status', 'local_ubionai'),
                $strings->string_exists($statuskey, 'local_ubionai')
                    ? get_string($statuskey, 'local_ubionai') : s($reg['tenant_status'])];
        }
        if ($reg['limits']) {
            $limits = array_map('s', registrar::describe_limits($reg['limits']));
            $regtable->data[] = [get_string('reg_limits', 'local_ubionai'),
                implode('<br>', $limits) . html_writer::div(get_string('reg_limits_help', 'local_ubionai'), 'form-text text-muted')];
        }
        if ($reg['registered_at']) {
            $regtable->data[] = [get_string('reg_registered_at', 'local_ubionai'), userdate($reg['registered_at'])];
        }
        if ($reg['last_error'] !== '' && $reg['state'] !== state::REGISTERED) {
            $errorkey = 'reg_error_' . $reg['last_error'];
            $regtable->data[] = [get_string('reg_last_error', 'local_ubionai'),
                (get_string_manager()->string_exists($errorkey, 'local_ubionai')
                    ? get_string($errorkey, 'local_ubionai') : '') . ' (' . s($reg['last_error']) . ')'];
        }
        if ($reg['last_attempt_at']) {
            $regtable->data[] = [get_string('reg_last_attempt', 'local_ubionai'), userdate($reg['last_attempt_at'])];
        }
        if ($reg['next_retry_at'] && $reg['state'] === state::FAILED_RETRYABLE) {
            $regtable->data[] = [get_string('reg_next_retry', 'local_ubionai'), userdate($reg['next_retry_at'])];
        }
        $teacherurl = $reg['mcp_url'] !== '' ? $reg['mcp_url'] : $reg['legacy_mcp_url'];
        if ($teacherurl !== '' && $reg['state'] === state::REGISTERED) {
            $copy = html_writer::empty_tag('input', [
                'type' => 'text', 'readonly' => 'readonly', 'id' => 'local_ubionai_mcp_url',
                'class' => 'form-control d-inline-block w-75', 'value' => $teacherurl,
            ]) . ' ' . html_writer::tag('button', get_string('reg_copy', 'local_ubionai'), [
                'type' => 'button', 'class' => 'btn btn-secondary',
                'data-action' => 'copytoclipboard', 'data-clipboard-target' => '#local_ubionai_mcp_url',
            ]) . html_writer::div(get_string('reg_mcp_url_help', 'local_ubionai'), 'form-text text-muted');
            $regtable->data[] = [get_string('reg_mcp_url', 'local_ubionai'), $copy];
            $PAGE->requires->js_amd_inline("require(['core/copy_to_clipboard']);");
        }
        echo html_writer::table($regtable);
        if ($reg['egress_ips']) {
            echo $OUTPUT->notification(
                get_string('reg_egress', 'local_ubionai', s(implode(', ', $reg['egress_ips']))),
                notification::NOTIFY_INFO
            );
        }
        if ($reg['state'] !== state::REGISTERED) {
            echo $OUTPUT->notification(get_string('reg_maintenance_note', 'local_ubionai'), notification::NOTIFY_INFO);
        }

        echo html_writer::start_div('local_ubionai_regactions mb-4');
        if ($reg['state'] !== state::AWAITING_CONSENT && $reg['state'] !== state::DEREGISTERED) {
            echo $OUTPUT->render(new single_button(
                new moodle_url($pageurl, ['action' => 'regretry']),
                get_string('reg_retry', 'local_ubionai'),
                'post'
            ));
        }
        if ($reg['state'] === state::REGISTERED) {
            $rotatebutton = new single_button(
                new moodle_url($pageurl, ['action' => 'regrotate']),
                get_string('reg_rotate', 'local_ubionai'),
                'post'
            );
            $rotatebutton->add_confirm_action(get_string('reg_rotate_confirm', 'local_ubionai'));
            echo $OUTPUT->render($rotatebutton);
        }
        echo html_writer::end_div();
    }
}

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
