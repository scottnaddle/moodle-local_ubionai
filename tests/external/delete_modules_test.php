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
 * Tests for local_ubionai_delete_modules.
 *
 * @covers \local_ubionai\external\delete_modules
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\delete_modules::class)]
final class delete_modules_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Two modules are deleted; a repeated id is processed only once.
     */
    public function test_deletes_two_modules_and_skips_duplicates(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Delete me',
        ]);
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'name' => 'Delete me too',
        ]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id, $course->id, 0, MUST_EXIST);
        $forumcm = get_coursemodule_from_instance('forum', $forum->id, $course->id, 0, MUST_EXIST);

        $result = delete_modules::execute((int) $course->id, [
            (int) $assigncm->id,
            (int) $assigncm->id,
            (int) $forumcm->id,
        ]);
        $result = external_api::clean_returnvalue(delete_modules::execute_returns(), $result);

        $this->assertSame([], $result['failed']);
        $this->assertCount(2, $result['deleted']);
        $this->assertSame(
            [(int) $assigncm->id, (int) $forumcm->id],
            array_column($result['deleted'], 'cmid')
        );
        $this->assertSame('Delete me', $result['deleted'][0]['name']);
        $this->assertSame('assign', $result['deleted'][0]['modname']);
        $this->assertSame('Delete me too', $result['deleted'][1]['name']);
        $this->assertSame('forum', $result['deleted'][1]['modname']);

        $this->run_adhoc_tasks();

        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assigncm->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $forumcm->id]));
    }

    /**
     * A module of another course is a per-item failure and is not deleted.
     */
    public function test_module_from_another_course_fails(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $other->id,
            'name' => 'Other course module',
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $other->id, 0, MUST_EXIST);

        $result = delete_modules::execute((int) $course->id, [(int) $cm->id]);
        $result = external_api::clean_returnvalue(delete_modules::execute_returns(), $result);

        $this->assertSame([], $result['deleted']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame((int) $cm->id, $result['failed'][0]['cmid']);
        $this->assertStringContainsString('does not belong', $result['failed'][0]['reason']);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cm->id]));
    }

    /**
     * A cmid that does not exist is a per-item failure, not an exception.
     */
    public function test_nonexistent_module_fails(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $maxid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {course_modules}');
        $cmid = $maxid + 1000;

        $result = delete_modules::execute((int) $course->id, [$cmid]);
        $result = external_api::clean_returnvalue(delete_modules::execute_returns(), $result);

        $this->assertSame([], $result['deleted']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($cmid, $result['failed'][0]['cmid']);
        $this->assertStringContainsString('does not exist', $result['failed'][0]['reason']);
    }

    /**
     * A user without moodle/course:manageactivities gets the standard exception.
     */
    public function test_missing_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $course->id, 'student');
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        delete_modules::execute((int) $course->id, []);
    }

    /**
     * Execute the queued async deletion tasks.
     */
    private function run_adhoc_tasks(): void {
        $now = time();
        while ($task = \core\task\manager::get_next_adhoc_task($now)) {
            $task->execute();
            \core\task\manager::adhoc_task_complete($task);
        }
    }
}
