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
 * Delete one or more course sections.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_sections extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionnums' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Section number (not id)')
            ),
        ]);
    }

    /**
     * Delete the sections, reporting failures per section instead of aborting the batch.
     *
     * @param int $courseid course id
     * @param array $sectionnums section numbers
     * @return array
     */
    public static function execute(int $courseid, array $sectionnums): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnums' => $sectionnums,
        ]);

        $course = get_course($params['courseid']);
        $coursecontext = \context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('moodle/course:update', $coursecontext);
        require_capability('moodle/course:manageactivities', $coursecontext);

        $sectionnums = array_values(array_unique(array_map('intval', $params['sectionnums'])));
        // Highest first: deleting a lower section renumbers the sections above it.
        rsort($sectionnums);

        $deleted = [];
        $failed = [];
        foreach ($sectionnums as $sectionnum) {
            if ($sectionnum === 0) {
                $failed[] = [
                    'sectionnum' => 0,
                    'reason' => 'The general section (0) cannot be deleted',
                ];
                continue;
            }

            $section = $DB->get_record('course_sections', [
                'course' => $course->id,
                'section' => $sectionnum,
            ]);
            if (!$section) {
                $failed[] = [
                    'sectionnum' => $sectionnum,
                    'reason' => "Section {$sectionnum} does not exist in course {$course->id}",
                ];
                continue;
            }

            $name = (string) $section->name;
            if ($name === '') {
                $name = course_get_format($course)->get_section_name($sectionnum);
            }

            $modules = [];
            $result = false;
            try {
                $modinfo = get_fast_modinfo($course);
                foreach ($modinfo->get_cms() as $cm) {
                    if ((int) $cm->sectionnum !== $sectionnum || $cm->deletioninprogress) {
                        continue;
                    }
                    require_capability('moodle/course:manageactivities', \context_module::instance($cm->id));
                    $modules[] = [
                        'cmid' => (int) $cm->id,
                        'name' => (string) $cm->name,
                        'modname' => (string) $cm->modname,
                    ];
                }

                // Async deletion moves the modules to section 0 and queues their removal.
                $result = course_delete_section($course, $sectionnum, true, true);
            } catch (\Throwable $e) {
                $failed[] = [
                    'sectionnum' => $sectionnum,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            if (!$result) {
                $failed[] = [
                    'sectionnum' => $sectionnum,
                    'reason' => 'The course format did not delete the section',
                ];
                continue;
            }

            $deleted[] = [
                'sectionnum' => $sectionnum,
                'name' => $name,
                'modules' => $modules,
            ];
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
                    'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                    'name' => new external_value(PARAM_TEXT, 'Section name'),
                    'modules' => new external_multiple_structure(
                        new external_single_structure([
                            'cmid' => new external_value(PARAM_INT, 'Course module id'),
                            'name' => new external_value(PARAM_TEXT, 'Activity name'),
                            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
                        ])
                    ),
                ]),
                'Sections that were deleted'
            ),
            'failed' => new external_multiple_structure(
                new external_single_structure([
                    'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                    'reason' => new external_value(PARAM_TEXT, 'Why the section was not deleted'),
                ]),
                'Sections that were not deleted'
            ),
        ]);
    }
}
