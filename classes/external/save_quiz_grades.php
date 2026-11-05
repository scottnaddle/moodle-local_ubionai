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
use question_state;

/**
 * Manually grade question slots of finished quiz attempts.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_quiz_grades extends external_api {
    /** @var float Tolerance used when comparing a mark with the question maximum. */
    private const MARK_TOLERANCE = 0.00001;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz instance id (not cmid)'),
            'grades' => new external_value(
                PARAM_RAW,
                'JSON array of {"attemptid": int, "slot": int, "mark": float, "comment": string}'
            ),
        ]);
    }

    /**
     * Apply manual question marks and recompute each attempt's quiz grade.
     *
     * @param int $courseid course id
     * @param int $quizid quiz instance id
     * @param string|array $grades JSON array (or array when called internally)
     * @return array
     */
    public static function execute(int $courseid, int $quizid, $grades): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        if (is_array($grades) || is_object($grades)) {
            $grades = json_encode($grades);
        }

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'quizid' => $quizid,
            'grades' => $grades,
        ]);

        $items = json_decode((string) $params['grades'], true);
        if (!is_array($items)) {
            throw new \invalid_parameter_exception(get_string('errorinvalidgradesjson', 'local_ubionai'));
        }

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, IGNORE_MISSING);
        if (empty($cm)) {
            throw new \invalid_parameter_exception(get_string('errorquiznotincourse', 'local_ubionai'));
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:grade', $context);

        $results = [];
        foreach ($items as $item) {
            $results[] = self::save_one($quiz, $cm, $context, $item);
        }

        return ['results' => $results];
    }

    /**
     * Validate and save one manual grade entry.
     *
     * @param \stdClass $quiz quiz record
     * @param \stdClass $cm course module record
     * @param \context_module $context module context
     * @param mixed $item one decoded grades entry
     * @return array per-item result
     */
    private static function save_one(\stdClass $quiz, \stdClass $cm, \context_module $context, $item): array {
        global $DB;

        $attemptid = is_array($item) && isset($item['attemptid']) ? clean_param($item['attemptid'], PARAM_INT) : 0;
        $slot = is_array($item) && isset($item['slot']) ? clean_param($item['slot'], PARAM_INT) : 0;
        $failure = static function (string $reason, int $attemptid, int $slot): array {
            return [
                'attemptid' => $attemptid,
                'slot' => $slot,
                'status' => 'failed',
                'mark' => null,
                'reason' => $reason,
            ];
        };

        if (!$attemptid) {
            return $failure(get_string('errorinvalidattempt', 'local_ubionai'), 0, $slot);
        }
        if (!$slot) {
            return $failure(get_string('errorinvalidslot', 'local_ubionai'), $attemptid, 0);
        }
        if (!array_key_exists('mark', $item) || !is_numeric($item['mark'])) {
            return $failure(get_string('errorinvalidmark', 'local_ubionai'), $attemptid, $slot);
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
        if (!$attempt || (int) $attempt->quiz !== (int) $quiz->id) {
            return $failure(get_string('errorattemptnotinquiz', 'local_ubionai'), $attemptid, $slot);
        }

        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        if ((int) $attemptobj->get_cmid() !== (int) $cm->id || !$attemptobj->is_finished()) {
            return $failure(get_string('errorattemptnotfinished', 'local_ubionai'), $attemptid, $slot);
        }

        $slots = array_map('intval', $attemptobj->get_slots());
        if (!in_array($slot, $slots, true)) {
            return $failure(get_string('errorinvalidslot', 'local_ubionai'), $attemptid, $slot);
        }

        $qa = $attemptobj->get_question_attempt($slot);
        $state = $qa->get_state();
        if ($state !== question_state::$needsgrading && !$state->is_commented()) {
            return $failure(get_string('errorquestionnotgradable', 'local_ubionai'), $attemptid, $slot);
        }

        $maxmark = (float) $qa->get_max_mark();
        $mark = (float) $item['mark'];
        if ($mark < 0 || $mark > $maxmark + self::MARK_TOLERANCE) {
            return $failure(get_string('errormarkoutofrange', 'local_ubionai', [
                'mark' => $mark,
                'max' => $maxmark,
            ]), $attemptid, $slot);
        }

        $comment = isset($item['comment']) ? (string) $item['comment'] : '';
        $transaction = $DB->start_delegated_transaction();
        try {
            $quba = $attemptobj->get_question_usage();
            $quba->manual_grade($slot, $comment, $mark, FORMAT_HTML);
            \question_engine::save_questions_usage_by_activity($quba);

            $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
            $attemptrecord->sumgrades = (float) $quba->get_total_mark();
            $attemptrecord->timemodified = time();
            $DB->update_record('quiz_attempts', $attemptrecord);

            $attemptobj->get_quizobj()->get_grade_calculator()->recompute_final_grade((int) $attemptrecord->userid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                // The transaction was already disposed; keep the original failure reason.
            }
            return $failure($e->getMessage(), $attemptid, $slot);
        }

        $params = [
            'objectid' => $qa->get_question_id(),
            'courseid' => $attemptobj->get_courseid(),
            'context' => $context,
            'other' => [
                'quizid' => $attemptobj->get_quizid(),
                'attemptid' => $attemptid,
                'slot' => $slot,
            ],
        ];
        $event = \mod_quiz\event\question_manually_graded::create($params);
        $event->trigger();

        return [
            'attemptid' => $attemptid,
            'slot' => $slot,
            'status' => 'saved',
            'mark' => $mark,
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
                    'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id, 0 when the entry had none'),
                    'slot' => new external_value(PARAM_INT, 'Question slot, 0 when the entry had none'),
                    'status' => new external_value(PARAM_ALPHA, 'saved or failed'),
                    'mark' => new external_value(
                        PARAM_FLOAT,
                        'Saved mark, null when it was not saved',
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
