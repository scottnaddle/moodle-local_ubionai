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

namespace local_grokbot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_grokbot\local\helper;
use stdClass;

/**
 * Create questions in a quiz's question bank and add them to the quiz.
 *
 * @package    local_grokbot
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_quiz_questions extends external_api {
    /** @var string[] Supported question types. */
    private const QTYPES = ['multichoice', 'truefalse', 'shortanswer', 'essay'];

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz instance id (not cmid)'),
            'questions' => new external_value(PARAM_RAW, 'JSON array of question definitions'),
        ]);
    }

    /**
     * Create and add the questions.
     *
     * @param int $quizid quiz instance id
     * @param string $questions JSON array of questions
     * @return array
     */
    public static function execute(int $quizid, string $questions): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'questions' => $questions,
        ]);
        $definitions = helper::decode_json_list($params['questions'], 'questions');
        if (!$definitions) {
            throw new invalid_parameter_exception('questions must not be empty');
        }

        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:manage', $context);
        require_capability('moodle/question:add', $context);

        if (quiz_has_attempts($quiz->id)) {
            throw new invalid_parameter_exception('Cannot add questions: this quiz already has attempts');
        }

        // Validate and build every question before touching the database.
        $forms = [];
        foreach ($definitions as $index => $definition) {
            $forms[] = self::build_form_data($definition, $index);
        }

        $category = helper::get_or_create_question_category($context);
        $quiz->cmid = $cm->id;

        $transaction = $DB->start_delegated_transaction();
        $questionids = [];
        foreach ($forms as [$qtype, $fromform, $mark]) {
            $fromform->category = $category->id . ',' . $context->id;

            $question = new stdClass();
            $question->category = $category->id;
            $question->qtype = $qtype;
            $question->createdby = 0;
            $question->idnumber = null;

            $saved = \question_bank::get_qtype($qtype)->save_question($question, $fromform);
            quiz_add_quiz_question($saved->id, $quiz, 0, $mark);
            $questionids[] = (int) $saved->id;
        }

        $quizobj = \mod_quiz\quiz_settings::create((int) $quiz->id);
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
        $transaction->allow_commit();

        $sumgrades = (float) $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]);

        return [
            'questionids' => $questionids,
            'sumgrades' => $sumgrades,
        ];
    }

    /**
     * Build the editing-form shaped data that question_type::save_question() expects.
     *
     * @param mixed $q decoded question definition
     * @param int $index position in the list, for error messages
     * @return array [qtype, fromform, mark]
     */
    private static function build_form_data($q, int $index): array {
        $where = "questions[{$index}]";
        if (!is_array($q) || array_is_list($q)) {
            throw new invalid_parameter_exception("{$where} must be an object");
        }
        $qtype = $q['type'] ?? '';
        if (!in_array($qtype, self::QTYPES, true)) {
            throw new invalid_parameter_exception("{$where}.type must be one of: " . implode(', ', self::QTYPES));
        }
        $text = (string) ($q['text'] ?? '');
        if (trim($text) === '') {
            throw new invalid_parameter_exception("{$where}.text must not be empty");
        }
        $mark = isset($q['mark']) ? (float) $q['mark'] : 1.0;
        if ($mark < 0) {
            throw new invalid_parameter_exception("{$where}.mark must be >= 0");
        }

        $form = new stdClass();
        $form->name = clean_param(trim((string) ($q['name'] ?? '')), PARAM_TEXT);
        if ($form->name === '') {
            $form->name = shorten_text(trim(html_to_text($text, 0, false)), 60);
        }
        $form->questiontext = ['text' => $text, 'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => (string) ($q['generalfeedback'] ?? ''), 'format' => FORMAT_HTML];
        $form->defaultmark = $mark > 0 ? $mark : 1;
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $form->idnumber = '';

        switch ($qtype) {
            case 'multichoice':
                self::fill_multichoice($form, $q, $where);
                break;
            case 'truefalse':
                $form->penalty = 1;
                $form->correctanswer = !empty($q['correct']) ? '1' : '0';
                $form->feedbacktrue = ['text' => (string) ($q['feedbacktrue'] ?? ''), 'format' => FORMAT_HTML];
                $form->feedbackfalse = ['text' => (string) ($q['feedbackfalse'] ?? ''), 'format' => FORMAT_HTML];
                $form->showstandardinstruction = 0;
                break;
            case 'shortanswer':
                self::fill_shortanswer($form, $q, $where);
                break;
            case 'essay':
                $formats = ['editor', 'editorfilepicker', 'plain', 'monospaced', 'noinline'];
                $responseformat = (string) ($q['responseformat'] ?? 'editor');
                if (!in_array($responseformat, $formats, true)) {
                    throw new invalid_parameter_exception("{$where}.responseformat must be one of: " . implode(', ', $formats));
                }
                $form->penalty = 0;
                $form->responseformat = $responseformat;
                $form->responserequired = $responseformat === 'noinline' ? 0 : 1;
                $form->responsefieldlines = max(1, (int) ($q['responsefieldlines'] ?? 10));
                $form->attachments = $responseformat === 'noinline' ? 1 : 0;
                $form->attachmentsrequired = $responseformat === 'noinline' ? 1 : 0;
                $form->maxbytes = 0;
                $form->filetypeslist = '';
                $form->graderinfo = ['text' => (string) ($q['graderinfo'] ?? ''), 'format' => FORMAT_HTML];
                $form->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
                break;
        }

        return [$qtype, $form, $mark];
    }

    /**
     * Validate and normalise the answers list of a question.
     *
     * @param array $q question definition
     * @param string $where error prefix
     * @param int $min minimum number of answers
     * @return array list of [text, fraction, feedback]
     */
    private static function parse_answers(array $q, string $where, int $min): array {
        $answers = $q['answers'] ?? null;
        if (!is_array($answers) || !array_is_list($answers) || count($answers) < $min) {
            throw new invalid_parameter_exception("{$where}.answers must be a list with at least {$min} item(s)");
        }
        $result = [];
        foreach ($answers as $i => $answer) {
            if (!is_array($answer) || !isset($answer['text']) || trim((string) $answer['text']) === '') {
                throw new invalid_parameter_exception("{$where}.answers[{$i}].text must not be empty");
            }
            $fraction = (float) ($answer['fraction'] ?? 0);
            if ($fraction < -1 || $fraction > 1) {
                throw new invalid_parameter_exception("{$where}.answers[{$i}].fraction must be between -1 and 1");
            }
            $result[] = [(string) $answer['text'], $fraction, (string) ($answer['feedback'] ?? '')];
        }
        return $result;
    }

    /**
     * Fill multichoice-specific form fields.
     *
     * @param stdClass $form form data
     * @param array $q question definition
     * @param string $where error prefix
     */
    private static function fill_multichoice(stdClass $form, array $q, string $where): void {
        $single = !array_key_exists('single', $q) || !empty($q['single']);
        $answers = self::parse_answers($q, $where, 2);

        $fractions = array_column($answers, 1);
        if ($single) {
            if (max($fractions) < 0.9999999) {
                throw new invalid_parameter_exception("{$where}: a single-answer question needs one answer with fraction 1");
            }
        } else {
            $positive = array_sum(array_filter($fractions, fn($f) => $f > 0));
            if (abs($positive - 1) > 0.0001) {
                throw new invalid_parameter_exception("{$where}: positive fractions of a multi-answer question must sum to 1");
            }
        }

        $form->penalty = 0.3333333;
        $form->single = $single ? '1' : '0';
        $form->shuffleanswers = array_key_exists('shuffleanswers', $q) ? (int) !empty($q['shuffleanswers']) : 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->noanswers = count($answers);
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach ($answers as [$text, $fraction, $feedback]) {
            $form->answer[] = ['text' => $text, 'format' => FORMAT_HTML];
            $form->fraction[] = (string) $fraction;
            $form->feedback[] = ['text' => $feedback, 'format' => FORMAT_HTML];
        }
        $form->correctfeedback = ['text' => get_string('correctfeedbackdefault', 'question'), 'format' => FORMAT_HTML];
        $form->partiallycorrectfeedback = ['text' => get_string('partiallycorrectfeedbackdefault', 'question'),
            'format' => FORMAT_HTML];
        $form->incorrectfeedback = ['text' => get_string('incorrectfeedbackdefault', 'question'), 'format' => FORMAT_HTML];
        $form->shownumcorrect = $single ? 0 : 1;
    }

    /**
     * Fill shortanswer-specific form fields.
     *
     * @param stdClass $form form data
     * @param array $q question definition
     * @param string $where error prefix
     */
    private static function fill_shortanswer(stdClass $form, array $q, string $where): void {
        $answers = self::parse_answers($q, $where, 1);
        if (max(array_column($answers, 1)) < 0.9999999) {
            throw new invalid_parameter_exception("{$where}: a shortanswer question needs one answer with fraction 1");
        }
        $form->penalty = 0.3333333;
        $form->usecase = !empty($q['usecase']) ? 1 : 0;
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach ($answers as [$text, $fraction, $feedback]) {
            $form->answer[] = $text;
            $form->fraction[] = (string) max(0.0, $fraction);
            $form->feedback[] = ['text' => $feedback, 'format' => FORMAT_HTML];
        }
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Created question id')
            ),
            'sumgrades' => new external_value(PARAM_FLOAT, 'Total of the quiz slot marks after adding the questions'),
        ]);
    }
}
