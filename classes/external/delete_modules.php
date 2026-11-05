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

/**
 * Delete one or more activities from a course.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_modules extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course module id')
            ),
        ]);
    }

    /**
     * Delete the modules, reporting failures per module instead of aborting the batch.
     *
     * @param int $courseid course id
     * @param array $cmids course module ids
     * @return array
     */
    public static function execute(int $courseid, array $cmids): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmids' => $cmids,
        ]);

        $course = get_course($params['courseid']);
        $coursecontext = \context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('moodle/course:manageactivities', $coursecontext);

        $deleted = [];
        $failed = [];
        $seen = [];
        foreach ($params['cmids'] as $cmid) {
            $cmid = (int) $cmid;
            if (isset($seen[$cmid])) {
                continue;
            }
            $seen[$cmid] = true;

            // No course restriction: a module of another course must become a per-item failure.
            $cm = get_coursemodule_from_id('', $cmid);
            if (!$cm) {
                $failed[] = [
                    'cmid' => $cmid,
                    'reason' => "Course module {$cmid} does not exist",
                ];
                continue;
            }
            if ((int) $cm->course !== (int) $course->id) {
                $failed[] = [
                    'cmid' => $cmid,
                    'reason' => "Course module {$cmid} does not belong to course {$course->id}",
                ];
                continue;
            }

            try {
                require_capability('moodle/course:manageactivities', \context_module::instance($cm->id));
                // Async deletion uses the recycle bin when it is enabled.
                course_delete_module($cm->id, true);
                $deleted[] = [
                    'cmid' => (int) $cm->id,
                    'name' => (string) $cm->name,
                    'modname' => (string) $cm->modname,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'cmid' => $cmid,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_TEXT, 'Activity name'),
                    'modname' => new external_value(PARAM_TEXT, 'Activity type'),
                ]),
                'Modules that were deleted'
            ),
            'failed' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'reason' => new external_value(PARAM_TEXT, 'Why the module was not deleted'),
                ]),
                'Modules that were not deleted'
            ),
        ]);
    }
}
