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
 * Profile page: the current user's Claude connections (ubionai_user tokens) with revoke.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ubionai\local\oauth;

require(__DIR__ . '/../../config.php');

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('Guests cannot manage connections');
}
$url = new moodle_url('/local/ubionai/connections.php');
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('standard');

$revoke = optional_param('revoke', 0, PARAM_INT);
if ($revoke && confirm_sesskey()) {
    oauth::revoke_token((int) $USER->id, $revoke);
    redirect($url, get_string('connections_revoked', 'local_ubionai'));
}

$title = get_string('connections', 'local_ubionai');
$PAGE->set_title($title);
$PAGE->set_heading(fullname($USER));
echo $OUTPUT->header();
echo $OUTPUT->heading($title);

$tokens = oauth::user_tokens((int) $USER->id);
if (!$tokens) {
    echo $OUTPUT->notification(get_string('connections_none', 'local_ubionai'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('connections_created', 'local_ubionai'),
        get_string('connections_lastused', 'local_ubionai'),
        get_string('connections_expires', 'local_ubionai'),
        '',
    ];
    foreach ($tokens as $token) {
        $table->data[] = [
            userdate($token->timecreated),
            $token->lastaccess ? userdate($token->lastaccess) : '-',
            $token->validuntil ? userdate($token->validuntil) : '-',
            $OUTPUT->single_button(
                new moodle_url($url, ['revoke' => $token->id, 'sesskey' => sesskey()]),
                get_string('connections_revoke', 'local_ubionai'),
                'post'
            ),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
