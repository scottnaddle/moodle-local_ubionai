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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use grade_item;

/**
 * Save assignment grades and feedback comments for a batch of users.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_grades extends external_api {
    /** @var float Tolerance used when comparing a grade with the assignment maximum. */
    private const GRADE_TOLERANCE = 0.00001;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id (not cmid)'),
            'grades' => new external_value(
                PARAM_RAW,
                'JSON array of {"userid": int, "grade": float, "feedback": string}'
            ),
        ]);
    }

    /**
     * Save each grade through assign::save_grade(), reporting success per user.
     *
     * @param int $courseid course id
     * @param int $assignid assignment instance id
     * @param string|array $grades JSON array (or array when called internally)
     * @return array
     */
    public static function execute(int $courseid, int $assignid, $grades): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/enrollib.php');

        if (is_array($grades) || is_object($grades)) {
            $grades = json_encode($grades);
        }

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'assignid' => $assignid,
            'grades' => $grades,
        ]);

        $items = json_decode((string) $params['grades'], true);
        if (!is_array($items)) {
            throw new \invalid_parameter_exception(get_string('errorinvalidgradesjson', 'local_ubionai'));
        }

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, IGNORE_MISSING);
        if (empty($cm)) {
            throw new \invalid_parameter_exception(get_string('errorassignnotincourse', 'local_ubionai'));
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $maxgrade = self::max_grade((int) $assign->id, (int) $course->id);
        $assignment = new \assign($context, $cm, $course);
        $results = [];

        foreach ($items as $item) {
            $results[] = self::save_one($assignment, $context, (int) $assign->id, $maxgrade, $item);
        }

        return ['results' => $results];
    }

    /**
     * Maximum grade of the assignment's grade item.
     *
     * @param int $assignid assignment instance id
     * @param int $courseid course id
     * @return float
     */
    private static function max_grade(int $assignid, int $courseid): float {
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'itemnumber' => 0,
            'courseid' => $courseid,
        ]);
        if (!$gradeitem || (int) $gradeitem->gradetype === GRADE_TYPE_NONE) {
            throw new \invalid_parameter_exception(get_string('errorassignnograde', 'local_ubionai'));
        }
        return (float) $gradeitem->grademax;
    }

    /**
     * Validate and save one grade entry.
     *
     * @param \assign $assignment assignment
     * @param \context_module $context module context
     * @param int $assignid assignment instance id
     * @param float $maxgrade maximum grade
     * @param mixed $item one decoded grades entry
     * @return array per-user result
     */
    private static function save_one(
        \assign $assignment,
        \context_module $context,
        int $assignid,
        float $maxgrade,
        $item
    ): array {
        global $DB;

        $userid = is_array($item) && isset($item['userid']) ? clean_param($item['userid'], PARAM_INT) : 0;
        $failure = static function (string $reason, int $userid = 0): array {
            return [
                'userid' => $userid,
                'status' => 'failed',
                'grade' => null,
                'reason' => $reason,
            ];
        };

        if (!$userid) {
            return $failure(get_string('errorinvaliduserid', 'local_ubionai'));
        }
        if (!array_key_exists('grade', $item) || !is_numeric($item['grade'])) {
            return $failure(get_string('errorinvalidgrade', 'local_ubionai'), $userid);
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user) {
            return $failure(get_string('errorinvaliduserid', 'local_ubionai'), $userid);
        }

        $hassubmission = $DB->record_exists('assign_submission', [
            'assignment' => $assignid,
            'userid' => $userid,
            'groupid' => 0,
        ]);
        if (!$hassubmission && !is_enrolled($context, $userid, '', false)) {
            return $failure(get_string('errorusernotenrolled', 'local_ubionai'), $userid);
        }

        $grade = (float) $item['grade'];
        if ($grade < 0 || $grade > $maxgrade + self::GRADE_TOLERANCE) {
            return $failure(get_string('errorgradeoutofrange', 'local_ubionai', [
                'grade' => $grade,
                'max' => $maxgrade,
            ]), $userid);
        }

        $data = new \stdClass();
        $data->grade = $grade;
        $data->attemptnumber = -1;
        $data->applytoall = 0;
        $data->addattempt = 0;
        $data->workflowstate = '';
        $data->assignfeedbackcomments_editor = [
            'text' => isset($item['feedback']) ? (string) $item['feedback'] : '',
            'format' => FORMAT_HTML,
        ];

        try {
            $assignment->save_grade($userid, $data);
        } catch (\Throwable $e) {
            return $failure($e->getMessage(), $userid);
        }

        return [
            'userid' => $userid,
            'status' => 'saved',
            'grade' => $grade,
            'reason' => '',
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id, 0 when the entry had none'),
                    'status' => new external_value(PARAM_ALPHA, 'saved or failed'),
                    'grade' => new external_value(
                        PARAM_FLOAT,
                        'Saved grade, null when it was not saved',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'reason' => new external_value(PARAM_RAW, 'Failure reason, empty on success'),
                ])
            ),
        ]);
    }
}
