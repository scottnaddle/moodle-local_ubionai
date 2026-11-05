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

namespace local_ubionai\task;

/**
 * Adhoc retry of the hub self-registration (1 min, 5 min, 30 min, 2 h, 6 h; stops after 24 h).
 *
 * The schedule lives in plugin config; this task only runs the due step.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retry_registration extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_retry_registration', 'local_ubionai');
    }

    /**
     * Run the due registration step.
     *
     * @return void
     */
    public function execute() {
        $state = \local_ubionai\registration\registrar::run_scheduled();
        mtrace('local_ubionai registration state: ' . $state);
    }
}
