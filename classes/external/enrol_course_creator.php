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

namespace local_ubionai\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Enrol the creator of a course into it, as course/edit.php does for UI-created courses.
 *
 * A course creator holds moodle/course:create only in the category, so after
 * core_course_create_courses they can neither access the new course nor call
 * enrol_manual_enrol_users on it. This function runs in the category context instead.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_course_creator extends external_api {
    /** Seconds after course creation during which the time-based creator check accepts the caller. */
    const CREATOR_WINDOW = 600;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Id of a course the caller has just created'),
        ]);
    }

    /**
     * Enrol the calling user into the course they created with the course creator role.
     *
     * @param int $courseid course id
     * @return array courseid, roleid, enrolled (true if this call added the enrolment or role)
     */
    public static function execute(int $courseid): array {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/enrollib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        if ((int) $course->id === SITEID) {
            throw new \invalid_parameter_exception('The site course cannot be used');
        }
        // The caller is not enrolled yet, so the course context is not accessible: check in the category.
        $categorycontext = \context_coursecat::instance($course->category);
        self::validate_context($categorycontext);
        require_capability('moodle/course:create', $categorycontext);

        $coursecontext = \context_course::instance($course->id);
        $roleid = self::creator_roleid();

        if (is_enrolled($coursecontext, $USER->id) && user_has_role_assignment($USER->id, $roleid, $coursecontext->id)) {
            return ['courseid' => (int) $course->id, 'roleid' => $roleid, 'enrolled' => false];
        }

        if (!self::is_creator($course, (int) $USER->id)) {
            throw new \moodle_exception('notcoursecreator', 'local_ubionai');
        }

        if (!enrol_is_enabled('manual')) {
            throw new \moodle_exception('manualenrolnotenabled', 'local_ubionai');
        }
        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id,
            'status' => ENROL_INSTANCE_ENABLED], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $instanceid = $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }
        $plugin->enrol_user($instance, $USER->id, $roleid);

        return ['courseid' => (int) $course->id, 'roleid' => $roleid, 'enrolled' => true];
    }

    /**
     * Role given to creators in their new course: $CFG->creatornewroleid, else the editingteacher role.
     *
     * @return int role id
     */
    protected static function creator_roleid(): int {
        global $CFG, $DB;

        if (!empty($CFG->creatornewroleid) && $DB->record_exists('role', ['id' => $CFG->creatornewroleid])) {
            return (int) $CFG->creatornewroleid;
        }
        $roles = get_archetype_roles('editingteacher');
        if (!$roles) {
            throw new \moodle_exception('nocreatorrole', 'local_ubionai');
        }
        return (int) reset($roles)->id;
    }

    /**
     * Whether the user created the course.
     *
     * Uses the standard log when it is enabled; otherwise accepts a course created within the
     * last CREATOR_WINDOW seconds that has no enrolments of other users.
     *
     * @param \stdClass $course course record
     * @param int $userid user id
     * @return bool
     */
    protected static function is_creator(\stdClass $course, int $userid): bool {
        global $DB;

        $stores = explode(',', (string) get_config('tool_log', 'enabled_stores'));
        if (in_array('logstore_standard', $stores, true)) {
            return $DB->record_exists('logstore_standard_log', [
                'eventname' => '\core\event\course_created',
                'courseid' => $course->id,
                'userid' => $userid,
            ]);
        }

        if (time() - (int) $course->timecreated > self::CREATOR_WINDOW) {
            return false;
        }
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid <> :userid";
        return !$DB->record_exists_sql($sql, ['courseid' => $course->id, 'userid' => $userid]);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'roleid' => new external_value(PARAM_INT, 'Role id held by the caller in the course'),
            'enrolled' => new external_value(PARAM_BOOL, 'True if this call enrolled the caller or assigned the role; '
                . 'false if they already had it'),
        ]);
    }
}
