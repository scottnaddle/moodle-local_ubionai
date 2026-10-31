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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

/**
 * Paged list of courses that contain assignments, without requiring enrolment.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_course_assignments extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'offset' => new external_value(PARAM_INT, 'Result offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Courses per page, 1..100', VALUE_DEFAULT, 25),
        ]);
    }

    /**
     * List courses that contain assignments, with their assignments.
     *
     * @param int $offset result offset
     * @param int $limit courses per page, clamped to 1..100
     * @return array
     */
    public static function execute(int $offset = 0, int $limit = 25): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(),
            ['offset' => $offset, 'limit' => $limit]);
        $offset = max(0, (int) $params['offset']);
        $limit = min(100, max(1, (int) $params['limit']));

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);

        $fromwhere = "FROM {course} c
                 WHERE c.id <> :siteid
                   AND EXISTS (SELECT 1 FROM {assign} a WHERE a.course = c.id)";
        $total = (int) $DB->count_records_sql("SELECT COUNT(1) {$fromwhere}", ['siteid' => SITEID]);

        // Paged, stable order; c.* because build_course() needs the full record.
        $courses = $DB->get_records_sql(
            "SELECT c.* {$fromwhere} ORDER BY c.id",
            ['siteid' => SITEID], $offset, $limit
        );

        [$validcourses, $warnings] = \core_external\util::validate_courses(array_keys($courses), $courses);

        $out = [];
        foreach ($validcourses as $course) {
            $context = \context_course::instance((int) $course->id);
            if (!has_capability('moodle/course:view', $context)
                    || !has_capability('mod/assign:view', $context)) {
                $warnings[] = [
                    'item' => 'course',
                    'itemid' => (int) $course->id,
                    'warningcode' => 'nopermission',
                    'message' => 'Missing moodle/course:view or mod/assign:view',
                ];
                continue;
            }
            $out[] = get_course_assignments::build_course($course);
        }
        // Courses whose assigns are all hidden to this user keep assignments: []
        // via build_course's uservisible filter; they stay in $out for paging stability.

        return [
            'courses' => $out,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'warnings' => $warnings,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(get_course_assignments::course_structure()),
            'total' => new external_value(PARAM_INT, 'Total courses containing assignments'),
            'offset' => new external_value(PARAM_INT, 'Offset used'),
            'limit' => new external_value(PARAM_INT, 'Limit used'),
            'warnings' => new external_warnings(),
        ]);
    }
}
