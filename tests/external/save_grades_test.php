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
 * Tests for local_ubionai_save_grades.
 *
 * @covers \local_ubionai\external\save_grades
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\save_grades::class)]
final class save_grades_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A grade and its feedback comment are written through assign::save_grade().
     */
    public function test_saves_grade_and_feedback(): void {
        global $DB;

        [$course, $assign, $student] = $this->create_assign_with_student();
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'onlinetext' => '<p>My answer.</p>',
        ]);

        $result = save_grades::execute((int) $course->id, (int) $assign->id, json_encode([[
            'userid' => (int) $student->id,
            'grade' => 85.5,
            'feedback' => '<p>Well done.</p>',
        ]]));
        $result = external_api::clean_returnvalue(save_grades::execute_returns(), $result);

        $this->assertCount(1, $result['results']);
        $this->assertSame('saved', $result['results'][0]['status']);
        $this->assertSame((int) $student->id, $result['results'][0]['userid']);
        $this->assertEqualsWithDelta(85.5, $result['results'][0]['grade'], 0.00001);
        $this->assertSame('', $result['results'][0]['reason']);

        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(85.5, (float) $grade->grade, 0.00001);

        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertStringContainsString('Well done', $feedback->commenttext);

        $read = get_submissions::execute((int) $course->id, (int) $assign->id);
        $read = external_api::clean_returnvalue(get_submissions::execute_returns(), $read);
        $this->assertCount(1, $read['submissions']);
        $this->assertEqualsWithDelta(85.5, $read['submissions'][0]['grade'], 0.00001);
    }

    /**
     * A grade above the assignment maximum is rejected and nothing is written.
     */
    public function test_out_of_range_grade_is_rejected(): void {
        global $DB;

        [$course, $assign, $student] = $this->create_assign_with_student();

        $result = save_grades::execute((int) $course->id, (int) $assign->id, json_encode([[
            'userid' => (int) $student->id,
            'grade' => 150,
            'feedback' => '',
        ]]));
        $result = external_api::clean_returnvalue(save_grades::execute_returns(), $result);

        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsString('outside', $result['results'][0]['reason']);
        $this->assertFalse($DB->record_exists('assign_grades', [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ]));
    }

    /**
     * A user who is neither enrolled nor has a submission is rejected.
     */
    public function test_unknown_user_is_rejected(): void {
        global $DB;

        [$course, $assign] = $this->create_assign_with_student();
        $stranger = $this->getDataGenerator()->create_user();

        $result = save_grades::execute((int) $course->id, (int) $assign->id, json_encode([[
            'userid' => (int) $stranger->id,
            'grade' => 10,
            'feedback' => '',
        ]]));

        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsString('not enrolled', $result['results'][0]['reason']);
        $this->assertFalse($DB->record_exists('assign_grades', [
            'assignment' => $assign->id,
            'userid' => $stranger->id,
        ]));
    }

    /**
     * An assignment of another course is rejected before any grade is written.
     */
    public function test_assign_from_another_course_is_rejected(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $other->id,
            'name' => 'Other course assignment',
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        save_grades::execute((int) $course->id, (int) $assign->id, '[]');
    }

    /**
     * A student without mod/assign:grade gets the standard capability exception.
     */
    public function test_student_cannot_save_grades(): void {
        [$course, $assign, $student] = $this->create_assign_with_student();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        save_grades::execute((int) $course->id, (int) $assign->id, json_encode([[
            'userid' => (int) $student->id,
            'grade' => 50,
            'feedback' => '',
        ]]));
    }

    /**
     * Invalid JSON is rejected.
     */
    public function test_invalid_json_is_rejected(): void {
        [$course, $assign] = $this->create_assign_with_student();

        $this->expectException(\invalid_parameter_exception::class);
        save_grades::execute((int) $course->id, (int) $assign->id, 'not json');
    }

    /**
     * Create a course, a graded assignment and an enrolled student.
     *
     * @return array [course, assign, student]
     */
    private function create_assign_with_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $course->id, 'student');

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Essay assignment',
            'grade' => 100,
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);

        return [$course, $assign, $student];
    }
}
