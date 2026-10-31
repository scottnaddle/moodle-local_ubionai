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

namespace local_ubionai\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Registration form shown on the connection page when the hub is configured.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connect_form extends \moodleform {
    /**
     * Define the form fields.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'action', 'connect');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('passwordunmask', 'regcode', get_string('regcode', 'local_ubionai'),
            ['autocomplete' => 'off']);
        $mform->setType('regcode', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('regcode', 'regcode', 'local_ubionai');

        $this->add_action_buttons(false, get_string('connect', 'local_ubionai'));
    }
}
