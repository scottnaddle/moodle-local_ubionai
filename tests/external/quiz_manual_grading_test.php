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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the quiz manual grading functions.
 *
 * @covers \local_ubionai\external\get_quiz_attempts_for_grading
 * @covers \local_ubionai\external\save_quiz_grades
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\get_quiz_attempts_for_grading::class)]
#[CoversClass(\local_ubionai\external\save_quiz_grades::class)]
final class quiz_manual_grading_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Needs-grading slots are returned and a manual mark is saved and reflected in the grade.
     */
    public function test_get_and_save_manual_grade(): void {
        global $DB;

        [$course, $quiz, $cm, $student] = $this->create_quiz_with_student();
        $attempt = $this->create_essay_attempt($quiz, $student);
        $result = get_quiz_attempts_for_grading::execute((int) $course->id, (int) $quiz->id);
        $result = external_api::clean_returnvalue(
            get_quiz_attempts_for_grading::execute_returns(),
            $result
        );

        $this->assertSame((int) $quiz->id, $result['quizid']);
        $this->assertSame('Essay quiz', $result['name']);
        $this->assertCount(1, $result['slots']);
        $slot = $result['slots'][0];
        $this->assertSame((int) $attempt->id, $slot['attemptid']);
        $this->assertSame((int) $student->id, $slot['userid']);
        $this->assertSame(fullname($student), $slot['fullname']);
        $this->assertSame(1, $slot['slot']);
        $this->assertSame('Explain the concept', $slot['questionname']);
        $this->assertStringContainsString('Explain the concept', $slot['questiontext']);
        $this->assertStringContainsString('My essay answer', $slot['response']);
        $this->assertEqualsWithDelta(10.0, $slot['maxmark'], 0.00001);
        $this->assertNull($slot['mark']);
        $this->assertSame('needsgrading', $slot['state']);

        $saved = save_quiz_grades::execute((int) $course->id, (int) $quiz->id, json_encode([[
            'attemptid' => (int) $attempt->id,
            'slot' => 1,
            'mark' => 7,
            'comment' => 'Good answer.',
        ]]));
        $saved = external_api::clean_returnvalue(save_quiz_grades::execute_returns(), $saved);

        $this->assertSame('saved', $saved['results'][0]['status'], $saved['results'][0]['reason']);
        $this->assertEqualsWithDelta(7.0, $saved['results'][0]['mark'], 0.00001);

        $this->assertEqualsWithDelta(
            7.0,
            (float) $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]),
            0.00001
        );
        $quizgrade = $DB->get_record('quiz_grades', [
            'quiz' => $quiz->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(7.0, (float) $quizgrade->grade, 0.00001);

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $attempt->id);
        $qa = $attemptobj->get_question_attempt(1);
        $this->assertTrue($qa->has_manual_comment());
        $comment = $qa->get_manual_comment();
        $this->assertStringContainsString('Good answer', (string) $comment[0]);

        $after = get_quiz_attempts_for_grading::execute((int) $course->id, (int) $quiz->id);
        $after = external_api::clean_returnvalue(
            get_quiz_attempts_for_grading::execute_returns(),
            $after
        );
        $this->assertCount(1, $after['slots']);
        $this->assertNotSame('needsgrading', $after['slots'][0]['state']);
        $this->assertEqualsWithDelta(7.0, $after['slots'][0]['mark'], 0.00001);
    }

    /**
     * A mark above the question maximum is rejected and nothing is graded.
     */
    public function test_out_of_range_mark_is_rejected(): void {
        global $DB;

        [$course, $quiz, $cm, $student] = $this->create_quiz_with_student();
        $attempt = $this->create_essay_attempt($quiz, $student);

        $result = save_quiz_grades::execute((int) $course->id, (int) $quiz->id, json_encode([[
            'attemptid' => (int) $attempt->id,
            'slot' => 1,
            'mark' => 11,
            'comment' => '',
        ]]));
        $result = external_api::clean_returnvalue(save_quiz_grades::execute_returns(), $result);

        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsString('outside', $result['results'][0]['reason']);
        $this->assertFalse($DB->record_exists('quiz_grades', [
            'quiz' => $quiz->id,
            'userid' => $student->id,
        ]));
    }

    /**
     * An attempt of another quiz is rejected.
     */
    public function test_attempt_of_another_quiz_is_rejected(): void {
        [$course, $quiz, $cm, $student] = $this->create_quiz_with_student();
        $this->create_essay_attempt($quiz, $student);

        [$otherquiz] = $this->create_quiz($course);
        $otherattempt = $this->create_essay_attempt($otherquiz, $student);

        $result = save_quiz_grades::execute((int) $course->id, (int) $quiz->id, json_encode([[
            'attemptid' => (int) $otherattempt->id,
            'slot' => 1,
            'mark' => 5,
            'comment' => '',
        ]]));
        $result = external_api::clean_returnvalue(save_quiz_grades::execute_returns(), $result);

        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsString('does not belong', $result['results'][0]['reason']);
    }

    /**
     * A quiz of another course is rejected.
     */
    public function test_quiz_from_another_course_is_rejected(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        [$quiz] = $this->create_quiz($other);

        $this->expectException(\invalid_parameter_exception::class);
        get_quiz_attempts_for_grading::execute((int) $course->id, (int) $quiz->id);
    }

    /**
     * A student without mod/quiz:grade gets the standard capability exception.
     */
    public function test_student_cannot_grade(): void {
        [$course, $quiz, $cm, $student] = $this->create_quiz_with_student();
        $attempt = $this->create_essay_attempt($quiz, $student);
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_quiz_attempts_for_grading::execute((int) $course->id, (int) $quiz->id);
    }

    /**
     * Create a course, an essay quiz and an enrolled student.
     *
     * @return array [course, quiz, cm, student]
     */
    private function create_quiz_with_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $course->id, 'student');
        [$quiz, $cm] = $this->create_quiz($course);

        return [$course, $quiz, $cm, $student];
    }

    /**
     * Create a quiz with a single 10-mark essay question.
     *
     * @param \stdClass $course course record
     * @return array [quiz, cm]
     */
    private function create_quiz(\stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Essay quiz',
            'grade' => 10,
            'attempts' => 1,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category([
            'contextid' => \context_module::instance($cm->id)->id,
        ]);
        $question = $qgen->create_question('essay', null, [
            'category' => $category->id,
            'name' => 'Explain the concept',
            'questiontext' => [
                'text' => '<p>Explain the concept.</p>',
                'format' => FORMAT_HTML,
            ],
            'defaultmark' => 10,
        ]);
        quiz_add_quiz_question($question->id, $quiz);
        $DB->set_field('quiz', 'sumgrades', 10, ['id' => $quiz->id]);

        return [$quiz, $cm];
    }

    /**
     * Start, answer and finish an essay attempt as the student.
     *
     * @param \stdClass $quiz quiz record
     * @param \stdClass $student user record
     * @return \stdClass attempt record as returned by the generator
     */
    private function create_essay_attempt(\stdClass $quiz, \stdClass $student): \stdClass {
        $this->setUser($student);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $generator->create_attempt((int) $quiz->id, (int) $student->id);
        $generator->submit_responses(
            (int) $attempt->id,
            [1 => 'My essay answer.'],
            false,
            true
        );
        $this->setAdminUser();

        return $attempt;
    }
}
