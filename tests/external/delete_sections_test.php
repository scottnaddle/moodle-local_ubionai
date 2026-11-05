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
 * Tests for local_ubionai_delete_sections.
 *
 * @covers \local_ubionai\external\delete_sections
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\delete_sections::class)]
final class delete_sections_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A non-empty section is deleted together with its modules.
     */
    public function test_deletes_non_empty_section_with_modules(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $DB->set_field('course_sections', 'name', 'Week 1', [
            'course' => $course->id,
            'section' => 1,
        ]);
        $sectionid = (int) $DB->get_field('course_sections', 'id', [
            'course' => $course->id,
            'section' => 1,
        ], MUST_EXIST);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Section assign',
        ]);
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Section forum',
        ]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id, $course->id, 0, MUST_EXIST);
        $forumcm = get_coursemodule_from_instance('forum', $forum->id, $course->id, 0, MUST_EXIST);

        $result = delete_sections::execute((int) $course->id, [1]);
        $result = external_api::clean_returnvalue(delete_sections::execute_returns(), $result);

        $this->assertSame([], $result['failed']);
        $this->assertCount(1, $result['deleted']);
        $this->assertSame(1, $result['deleted'][0]['sectionnum']);
        $this->assertSame('Week 1', $result['deleted'][0]['name']);

        $cmids = array_map(
            static fn(array $module): int => (int) $module['cmid'],
            $result['deleted'][0]['modules']
        );
        sort($cmids);
        $expected = [(int) $assigncm->id, (int) $forumcm->id];
        sort($expected);
        $this->assertSame($expected, $cmids);

        $this->run_adhoc_tasks();

        $this->assertFalse($DB->record_exists('course_sections', ['id' => $sectionid]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $assigncm->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $forumcm->id]));
    }

    /**
     * Sections are deleted from the highest number down.
     */
    public function test_deletes_from_highest_section_number(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $sectionids = [];
        foreach ([1, 2] as $sectionnum) {
            $sectionids[$sectionnum] = (int) $DB->get_field('course_sections', 'id', [
                'course' => $course->id,
                'section' => $sectionnum,
            ], MUST_EXIST);
            $this->getDataGenerator()->create_module('assign', [
                'course' => $course->id,
                'section' => $sectionnum,
                'name' => "Section {$sectionnum} activity",
            ]);
        }

        $result = delete_sections::execute((int) $course->id, [1, 2]);
        $result = external_api::clean_returnvalue(delete_sections::execute_returns(), $result);

        $this->assertSame([], $result['failed']);
        $this->assertSame([2, 1], array_column($result['deleted'], 'sectionnum'));

        $this->run_adhoc_tasks();

        foreach ($sectionids as $sectionid) {
            $this->assertFalse($DB->record_exists('course_sections', ['id' => $sectionid]));
        }
    }

    /**
     * Section 0 is always rejected and nothing is deleted.
     */
    public function test_section_zero_is_rejected(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 0,
            'name' => 'General section activity',
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, 0, MUST_EXIST);

        $result = delete_sections::execute((int) $course->id, [0]);
        $result = external_api::clean_returnvalue(delete_sections::execute_returns(), $result);

        $this->assertSame([], $result['deleted']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(0, $result['failed'][0]['sectionnum']);
        $this->assertStringContainsString('general section', $result['failed'][0]['reason']);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cm->id]));
    }

    /**
     * A section number that does not belong to the course is a per-item failure.
     */
    public function test_nonexistent_section_fails(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);

        $result = delete_sections::execute((int) $course->id, [99]);
        $result = external_api::clean_returnvalue(delete_sections::execute_returns(), $result);

        $this->assertSame([], $result['deleted']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(99, $result['failed'][0]['sectionnum']);
        $this->assertStringContainsString('does not exist', $result['failed'][0]['reason']);
    }

    /**
     * A user without the course capabilities gets the standard exception.
     */
    public function test_missing_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $course->id, 'student');
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        delete_sections::execute((int) $course->id, []);
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
