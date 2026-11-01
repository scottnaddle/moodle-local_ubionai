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
 * Delegated login + consent for the hub's OAuth server (browser leg).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ubionai\local\oauth;

require(__DIR__ . '/../../../config.php');

$req = required_param('req', PARAM_RAW_TRIMMED);
$claims = oauth::verify_authorize_request($req);

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/ubionai/oauth/authorize.php', ['req' => $req]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('login');
require_login(null, false);
oauth::require_can_connect((int) $USER->id);

$callback = new moodle_url($claims['callback']);
if (data_submitted()) {
    require_sesskey();
    if (optional_param('approve', '', PARAM_ALPHA) !== '') {
        $code = oauth::issue_code((int) $USER->id, $claims);
        $callback->params(['code' => $code, 'state' => $claims['state']]);
    } else {
        $callback->params(['error' => 'access_denied', 'state' => $claims['state']]);
    }
    redirect($callback);
}

$title = get_string('oauth_consent_title', 'local_ubionai');
$PAGE->set_title($title);
$PAGE->set_heading($title);
echo $OUTPUT->header();
echo $OUTPUT->heading($title);

$rows = [
    get_string('oauth_consent_user', 'local_ubionai') => s(fullname($USER) . ' (' . $USER->username . ')'),
    get_string('oauth_consent_site', 'local_ubionai') => format_string($SITE->fullname) . ' — ' . $CFG->wwwroot,
    get_string('oauth_consent_client', 'local_ubionai') => s((string) ($claims['client_name'] ?? ''))
        . ' (' . s((string) ($claims['redirect_host'] ?? '')) . ')',
    get_string('oauth_consent_hub', 'local_ubionai') => s($callback->get_host()),
    get_string('oauth_consent_scope', 'local_ubionai') => s((string) ($claims['scope'] ?? '')),
];
$table = new html_table();
$table->attributes['class'] = 'generaltable';
foreach ($rows as $label => $value) {
    $table->data[] = [html_writer::tag('strong', $label), $value];
}
echo html_writer::tag('p', get_string('oauth_consent_intro', 'local_ubionai'));
echo html_writer::table($table);
echo html_writer::tag('p', get_string('oauth_consent_notice', 'local_ubionai'));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'approve',
    'value' => get_string('oauth_consent_allow', 'local_ubionai'), 'class' => 'btn btn-primary me-2']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'deny',
    'value' => get_string('oauth_consent_deny', 'local_ubionai'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
