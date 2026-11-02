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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for local_ubionai_enrol_course_creator.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\enrol_course_creator::class)]
final class enrol_course_creator_test extends \advanced_testcase {
    /** @var \core_course_category category the course creators hold their role in */
    private \core_course_category $category;

    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->category = $this->getDataGenerator()->create_category();
    }

    /**
     * Enable the standard log store with immediate writes.
     */
    private function enable_logstore(): void {
        $this->preventResetByRollback(); // Logging waits till the transaction gets committed.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * Create a user holding the coursecreator role in the test category.
     *
     * @return \stdClass user
     */
    private function create_coursecreator(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'coursecreator'], MUST_EXIST);
        role_assign($roleid, $user->id, \context_coursecat::instance($this->category->id));
        return $user;
    }

    /**
     * Create a course in the test category as the current user.
     *
     * @return \stdClass course
     */
    private function create_course(): \stdClass {
        return $this->getDataGenerator()->create_course(['category' => $this->category->id]);
    }

    /**
     * The creator (found in the standard log) is enrolled as editing teacher and can update the course.
     */
    public function test_creator_enrolled_via_log(): void {
        global $CFG, $DB;
        $this->enable_logstore();
        $creator = $this->create_coursecreator();
        $this->setUser($creator);
        $course = $this->create_course();
        $coursecontext = \context_course::instance($course->id);
        $this->assertFalse(has_capability('moodle/course:update', $coursecontext));

        $result = enrol_course_creator::execute((int) $course->id);
        $result = \core_external\external_api::clean_returnvalue(enrol_course_creator::execute_returns(), $result);

        $editingteacher = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->assertSame((int) $CFG->creatornewroleid, $editingteacher);
        $this->assertSame((int) $course->id, $result['courseid']);
        $this->assertSame($editingteacher, $result['roleid']);
        $this->assertTrue($result['enrolled']);
        $this->assertTrue(is_enrolled($coursecontext, $creator->id));
        $this->assertTrue(user_has_role_assignment($creator->id, $editingteacher, $coursecontext->id));
        $this->assertTrue(has_capability('moodle/course:update', $coursecontext, $creator));
    }

    /**
     * Without the standard log, a fresh course without other enrolments is accepted.
     */
    public function test_creator_enrolled_via_time_fallback(): void {
        set_config('enabled_stores', '', 'tool_log');
        $creator = $this->create_coursecreator();
        $this->setUser($creator);
        $course = $this->create_course();

        $result = enrol_course_creator::execute((int) $course->id);

        $this->assertTrue($result['enrolled']);
        $this->assertTrue(has_capability('moodle/course:update', \context_course::instance($course->id), $creator));
    }

    /**
     * A manual instance is added when the course has none.
     */
    public function test_adds_manual_instance(): void {
        global $DB;
        $this->enable_logstore();
        $creator = $this->create_coursecreator();
        $this->setUser($creator);
        $course = $this->create_course();
        $DB->delete_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);

        $result = enrol_course_creator::execute((int) $course->id);

        $this->assertTrue($result['enrolled']);
        $this->assertTrue($DB->record_exists('enrol', ['courseid' => $course->id, 'enrol' => 'manual']));
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $creator->id));
    }

    /**
     * Another course creator in the same category cannot enrol into someone else's course.
     */
    public function test_non_creator_rejected(): void {
        $this->enable_logstore();
        $creator = $this->create_coursecreator();
        $other = $this->create_coursecreator();
        $this->setUser($creator);
        $course = $this->create_course();

        $this->setUser($other);
        try {
            enrol_course_creator::execute((int) $course->id);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('notcoursecreator', $e->errorcode);
        }
        $this->assertFalse(is_enrolled(\context_course::instance($course->id), $other->id));
    }

    /**
     * Without the standard log, a course that already has other enrolments is rejected.
     */
    public function test_non_creator_rejected_time_fallback(): void {
        set_config('enabled_stores', '', 'tool_log');
        $creator = $this->create_coursecreator();
        $other = $this->create_coursecreator();
        $this->setUser($creator);
        $course = $this->create_course();
        $this->getDataGenerator()->enrol_user($creator->id, $course->id, 'editingteacher');

        $this->setUser($other);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notcoursecreator', 'local_ubionai'));
        enrol_course_creator::execute((int) $course->id);
    }

    /**
     * A user without moodle/course:create in the category is rejected.
     */
    public function test_requires_course_create(): void {
        $this->setAdminUser();
        $course = $this->create_course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        enrol_course_creator::execute((int) $course->id);
    }

    /**
     * Repeated calls do not duplicate the enrolment or role assignment.
     */
    public function test_idempotent(): void {
        global $DB;
        $this->enable_logstore();
        $creator = $this->create_coursecreator();
        $this->setUser($creator);
        $course = $this->create_course();
        $coursecontext = \context_course::instance($course->id);

        $first = enrol_course_creator::execute((int) $course->id);
        $second = enrol_course_creator::execute((int) $course->id);

        $this->assertTrue($first['enrolled']);
        $this->assertFalse($second['enrolled']);
        $this->assertSame($first['roleid'], $second['roleid']);
        $sql = "SELECT COUNT(1)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid = :userid";
        $this->assertSame(1, (int) $DB->count_records_sql($sql, ['courseid' => $course->id, 'userid' => $creator->id]));
        $this->assertSame(1, $DB->count_records('role_assignments',
            ['userid' => $creator->id, 'contextid' => $coursecontext->id, 'roleid' => $first['roleid']]));
    }
}
