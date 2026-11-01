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
use grade_item;

/**
 * List assignments of a course with grade settings, without requiring enrolment.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_assignments extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Get the assignments of a course.
     *
     * @param int $courseid course id
     * @return array
     */
    public static function execute(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        // validate_context() already enforces course access; gate on the module
        // capability so enrolled students (not only managers) can use this.
        require_capability('mod/assign:view', $context);

        return [
            'courses' => [self::build_course($course)],
            'warnings' => [],
        ];
    }

    /**
     * Build the assignments entry for one course (modinfo-visible assigns only).
     *
     * @param \stdClass $course full course record
     * @return array course entry: id, fullname, shortname, assignments
     */
    public static function build_course(\stdClass $course): array {
        global $DB;

        $modinfo = get_fast_modinfo($course);
        $instanceids = [];
        $cms = [];
        foreach ($modinfo->get_instances_of('assign') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $instanceids[] = (int) $cm->instance;
            $cms[(int) $cm->instance] = $cm;
        }

        $assignments = [];
        if ($instanceids) {
            $records = $DB->get_records_list('assign', 'id', $instanceids);
            foreach ($records as $assign) {
                $cm = $cms[(int) $assign->id];

                $gradeitem = grade_item::fetch([
                    'itemtype' => 'mod',
                    'itemmodule' => 'assign',
                    'iteminstance' => (int) $assign->id,
                    'itemnumber' => 0,
                    'courseid' => (int) $course->id,
                ]);

                $gradetype = 'none';
                $grademax = 0.0;
                $scaleid = null;
                $gradepass = 0.0;
                if ($gradeitem) {
                    $gradetype = match ((int) $gradeitem->gradetype) {
                        GRADE_TYPE_VALUE => 'point',
                        GRADE_TYPE_SCALE => 'scale',
                        default => 'none',
                    };
                    $grademax = (float) $gradeitem->grademax;
                    // scaleid is nullable since Moodle 5.3; 0 means "no scale" for point grades.
                    $scaleid = (int) $gradeitem->scaleid;
                    $gradepass = (float) $gradeitem->gradepass;
                }

                $assignments[] = [
                    'id' => (int) $assign->id,
                    'cmid' => (int) $cm->id,
                    'course' => (int) $course->id,
                    'name' => $assign->name,
                    'visible' => (bool) $cm->visible,
                    'section' => (int) $cm->sectionnum,
                    'duedate' => (int) $assign->duedate,
                    'allowsubmissionsfromdate' => (int) $assign->allowsubmissionsfromdate,
                    'cutoffdate' => (int) $assign->cutoffdate,
                    'gradingduedate' => (int) $assign->gradingduedate,
                    'grade' => $assign->grade,
                    'gradetype' => $gradetype,
                    'grademax' => $grademax,
                    'scaleid' => $scaleid,
                    'gradepass' => $gradepass,
                    'markingworkflow' => (bool) $assign->markingworkflow,
                    'markingallocation' => (bool) $assign->markingallocation,
                    'teamsubmission' => (bool) $assign->teamsubmission,
                    'blindmarking' => (bool) $assign->blindmarking,
                    'attemptreopenmethod' => (string) $assign->attemptreopenmethod,
                    'maxattempts' => (int) $assign->maxattempts,
                ];
            }
        }

        return [
            'id' => (int) $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'assignments' => $assignments,
        ];
    }

    /**
     * External structure of one course entry (shared with list_course_assignments).
     *
     * @return external_single_structure
     */
    public static function course_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course id'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'assignments' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Assignment instance id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'course' => new external_value(PARAM_INT, 'Course id'),
                    'name' => new external_value(PARAM_TEXT, 'Assignment name'),
                    'visible' => new external_value(PARAM_BOOL, 'Module visible'),
                    'section' => new external_value(PARAM_INT, 'Section number'),
                    'duedate' => new external_value(PARAM_INT, 'Due date'),
                    'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Submissions open from'),
                    'cutoffdate' => new external_value(PARAM_INT, 'Cut-off date'),
                    'gradingduedate' => new external_value(PARAM_INT, 'Grading due date'),
                    'grade' => new external_value(PARAM_RAW, 'Raw grade setting'),
                    'gradetype' => new external_value(PARAM_ALPHA, 'point|scale|none'),
                    'grademax' => new external_value(PARAM_FLOAT, 'Grade max'),
                    'scaleid' => new external_value(PARAM_INT, 'Scale id', VALUE_OPTIONAL),
                    'gradepass' => new external_value(PARAM_FLOAT, 'Grade to pass'),
                    'markingworkflow' => new external_value(PARAM_BOOL, 'Marking workflow enabled'),
                    'markingallocation' => new external_value(PARAM_BOOL, 'Marking allocation enabled'),
                    'teamsubmission' => new external_value(PARAM_BOOL, 'Team submission enabled'),
                    'blindmarking' => new external_value(PARAM_BOOL, 'Blind marking enabled'),
                    'attemptreopenmethod' => new external_value(PARAM_ALPHA, 'Attempt reopen method'),
                    'maxattempts' => new external_value(PARAM_INT, 'Max attempts'),
                ])
            ),
        ]);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(self::course_structure()),
            'warnings' => new external_multiple_structure(
                new external_single_structure([]), VALUE_OPTIONAL
            ),
        ]);
    }
}
