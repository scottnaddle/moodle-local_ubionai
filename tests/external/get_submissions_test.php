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
 * Tests for local_ubionai_get_submissions.
 *
 * @covers \local_ubionai\external\get_submissions
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\get_submissions::class)]
final class get_submissions_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The assignment settings and the submitted online text are returned as plain text.
     */
    public function test_returns_online_text_and_settings(): void {
        global $DB;

        [$course, $assign, $student] = $this->create_assign_with_student();
        $this->create_online_submission($assign, $student, '<p>Hello <b>essay</b> world.</p>');

        $result = get_submissions::execute((int) $course->id, (int) $assign->id);
        $result = external_api::clean_returnvalue(get_submissions::execute_returns(), $result);

        $this->assertSame((int) $assign->id, $result['assignid']);
        $this->assertSame('Essay assignment', $result['name']);
        $this->assertStringContainsString('Write an essay', $result['intro']);
        $this->assertStringNotContainsString('<p>', $result['intro']);
        $this->assertEqualsWithDelta(100.0, $result['maxgrade'], 0.00001);
        $this->assertSame('', $result['advancedgradingmethod']);

        $this->assertCount(1, $result['submissions']);
        $submission = $result['submissions'][0];
        $this->assertSame((int) $student->id, $submission['userid']);
        $this->assertSame(fullname($student), $submission['fullname']);
        $this->assertSame('submitted', $submission['status']);
        $this->assertStringContainsString('Hello', $submission['onlinetext']);
        $this->assertStringContainsString('world', $submission['onlinetext']);
        $this->assertStringNotContainsString('<b>', $submission['onlinetext']);
        $this->assertSame([], $submission['files']);
        $this->assertNull($submission['grade']);

        $stored = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        $this->assertSame((int) $stored->timemodified, $submission['timemodified']);
    }

    /**
     * Text files are extracted and binary files are listed without content.
     */
    public function test_extracts_file_text_and_lists_other_files(): void {
        global $DB;

        [$course, $assign, $student] = $this->create_assign_with_student();
        $this->create_online_submission($assign, $student, '<p>See attached.</p>');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        $context = \context_module::instance($assign->cmid);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'assignsubmission_file',
            'filearea' => ASSIGNSUBMISSION_FILE_FILEAREA,
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'notes.txt',
        ], 'The file body.');
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'assignsubmission_file',
            'filearea' => ASSIGNSUBMISSION_FILE_FILEAREA,
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => 'picture.png',
        ], "\x89PNG\r\n\x1a\nbinary");

        $result = get_submissions::execute((int) $course->id, (int) $assign->id);
        $result = external_api::clean_returnvalue(get_submissions::execute_returns(), $result);

        $files = $result['submissions'][0]['files'];
        $this->assertCount(2, $files);
        $byfilename = [];
        foreach ($files as $file) {
            $byfilename[$file['filename']] = $file;
        }
        $this->assertTrue($byfilename['notes.txt']['hastext']);
        $this->assertStringContainsString('The file body', $byfilename['notes.txt']['text']);
        $this->assertFalse($byfilename['picture.png']['hastext']);
        $this->assertSame('', $byfilename['picture.png']['text']);
    }

    /**
     * An assignment of another course is rejected.
     */
    public function test_assign_from_another_course_is_rejected(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $other->id,
            'name' => 'Other course assignment',
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        get_submissions::execute((int) $course->id, (int) $assign->id);
    }

    /**
     * A student without mod/assign:grade gets the standard capability exception.
     */
    public function test_student_cannot_read_submissions(): void {
        [$course, $assign, $student] = $this->create_assign_with_student();
        $this->create_online_submission($assign, $student, '<p>Private answer.</p>');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_submissions::execute((int) $course->id, (int) $assign->id);
    }

    /**
     * Create a course, a graded assignment and an enrolled student.
     *
     * @return array [course, assign, student]
     */
    private function create_assign_with_student(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $course->id, 'student');

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Essay assignment',
            'intro' => '<p>Write an essay.</p>',
            'introformat' => FORMAT_HTML,
            'grade' => 100,
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 0,
            'assignsubmission_file_filetypes' => '',
            'assignfeedback_comments_enabled' => 1,
        ]);

        return [$course, $assign, $student];
    }

    /**
     * Add an online text submission for a student.
     *
     * @param \stdClass $assign assignment module record
     * @param \stdClass $student user record
     * @param string $text submission HTML
     */
    private function create_online_submission(\stdClass $assign, \stdClass $student, string $text): void {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'onlinetext' => $text,
        ]);
    }
}
