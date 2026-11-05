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
 * List the quiz question slots that a teacher still has to grade manually.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_quiz_attempts_for_grading extends external_api {
    /** @var int Maximum number of characters returned for a question or response. */
    private const MAX_TEXT_LENGTH = 20000;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz instance id (not cmid)'),
        ]);
    }

    /**
     * Return every finished attempt's slots that need grading or were manually graded.
     *
     * @param int $courseid course id
     * @param int $quizid quiz instance id
     * @return array
     */
    public static function execute(int $courseid, int $quizid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'quizid' => $quizid,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, IGNORE_MISSING);
        if (empty($cm)) {
            throw new \invalid_parameter_exception(get_string('errorquiznotincourse', 'local_ubionai'));
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:grade', $context);

        $attempts = $DB->get_records(
            'quiz_attempts',
            ['quiz' => $quiz->id, 'preview' => 0, 'state' => \mod_quiz\quiz_attempt::FINISHED],
            'id ASC'
        );

        $slots = [];
        foreach ($attempts as $attempt) {
            $attemptobj = \mod_quiz\quiz_attempt::create((int) $attempt->id);
            if ((int) $attemptobj->get_quizid() !== (int) $quiz->id
                    || (int) $attemptobj->get_cmid() !== (int) $cm->id) {
                continue;
            }

            $user = $DB->get_record('user', ['id' => $attempt->userid], '*', IGNORE_MISSING);
            foreach ($attemptobj->get_slots() as $slot) {
                $qa = $attemptobj->get_question_attempt($slot);
                $state = $qa->get_state();
                if ($state !== question_state::$needsgrading && !$state->is_commented()) {
                    continue;
                }

                $mark = $qa->get_mark();
                $question = $qa->get_question();
                $questiontext = format_text(
                    (string) $question->questiontext,
                    (int) $question->questiontextformat,
                    ['context' => $context]
                );
                $response = (string) $qa->get_response_summary();
                if (trim($response) === '' && method_exists($question, 'summarise_response')) {
                    $response = (string) $question->summarise_response($qa->get_last_qt_data());
                }

                $slots[] = [
                    'attemptid' => (int) $attempt->id,
                    'userid' => (int) $attempt->userid,
                    'fullname' => $user ? fullname($user) : '',
                    'attemptnumber' => (int) $attempt->attempt,
                    'slot' => (int) $slot,
                    'questionname' => format_string($question->name),
                    'questiontext' => self::plain($questiontext),
                    'response' => self::plain($response),
                    'maxmark' => (float) $qa->get_max_mark(),
                    'mark' => $mark === null ? null : (float) $mark,
                    'state' => (string) $state,
                ];
            }
        }

        return [
            'quizid' => (int) $quiz->id,
            'name' => (string) $quiz->name,
            'slots' => $slots,
        ];
    }

    /**
     * Convert formatted HTML into compact plain text and cap its length.
     *
     * @param string $html formatted text
     * @return string
     */
    private static function plain(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $text = html_to_text($html, 0, false);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        if (\core_text::strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = \core_text::substr($text, 0, self::MAX_TEXT_LENGTH);
        }
        return $text;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'quizid' => new external_value(PARAM_INT, 'Quiz instance id'),
            'name' => new external_value(PARAM_TEXT, 'Quiz name'),
            'slots' => new external_multiple_structure(
                new external_single_structure([
                    'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'fullname' => new external_value(PARAM_TEXT, 'User full name'),
                    'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
                    'slot' => new external_value(PARAM_INT, 'Question slot in the attempt'),
                    'questionname' => new external_value(PARAM_TEXT, 'Question name'),
                    'questiontext' => new external_value(PARAM_RAW, 'Question text as plain text'),
                    'response' => new external_value(PARAM_RAW, 'Student response as plain text'),
                    'maxmark' => new external_value(PARAM_FLOAT, 'Maximum mark of the question'),
                    'mark' => new external_value(
                        PARAM_FLOAT,
                        'Current mark, null when not graded',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'state' => new external_value(PARAM_TEXT, 'Question state (needsgrading or a graded state)'),
                ])
            ),
        ]);
    }
}
