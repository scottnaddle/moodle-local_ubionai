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

use local_ubionai\local\provisioner;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for local_ubionai_list_course_assignments.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\list_course_assignments::class)]
final class list_course_assignments_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Paging is stable, ordered by course id, and skips the site and assignment-free courses.
     */
    public function test_paging_and_ordering(): void {
        global $DB;

        $first = $this->getDataGenerator()->create_course(['fullname' => 'First']);
        $this->getDataGenerator()->create_module('assign', ['course' => $first->id, 'name' => 'A1']);
        $second = $this->getDataGenerator()->create_course(['fullname' => 'Second']);
        $this->getDataGenerator()->create_module('assign', ['course' => $second->id, 'name' => 'B1']);
        $third = $this->getDataGenerator()->create_course(['fullname' => 'Third', 'visible' => 0]);
        $this->getDataGenerator()->create_module('assign', ['course' => $third->id, 'name' => 'C1']);
        $noassign = $this->getDataGenerator()->create_course(['fullname' => 'No assignments']);

        $page1 = list_course_assignments::execute(0, 2);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(0, $page1['offset']);
        $this->assertSame(2, $page1['limit']);
        $this->assertCount(2, $page1['courses']);

        $page2 = list_course_assignments::execute(2, 2);
        $this->assertSame(3, $page2['total']);
        $this->assertCount(1, $page2['courses']);

        $ids1 = array_map(static fn(array $course): int => (int) $course['id'], $page1['courses']);
        $ids2 = array_map(static fn(array $course): int => (int) $course['id'], $page2['courses']);
        $this->assertSame([(int) $first->id, (int) $second->id], $ids1);
        $this->assertSame([(int) $third->id], $ids2);
        $this->assertLessThan($ids2[0], end($ids1));

        $all = array_merge($ids1, $ids2);
        $this->assertNotContains((int) SITEID, $all);
        $this->assertNotContains((int) $noassign->id, $all);
        $this->assertSame([], $page1['warnings']);
        $this->assertSame([], $page2['warnings']);

        $this->assertSame(1, $DB->count_records('assign', ['course' => $third->id]));
    }

    /**
     * A module hidden from the user is omitted, but its course stays in the listing.
     */
    public function test_hidden_assign_module_absent_course_still_listed(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Visible assignment',
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Hidden assignment',
            'visible' => 0,
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $course->id, 'student');
        $this->setUser($user);

        $result = list_course_assignments::execute(0, 10);

        $this->assertCount(1, $result['courses']);
        $this->assertSame((int) $course->id, (int) $result['courses'][0]['id']);
        $names = array_map(
            static fn(array $assignment): string => (string) $assignment['name'],
            $result['courses'][0]['assignments']
        );
        $this->assertContains('Visible assignment', $names);
        $this->assertNotContains('Hidden assignment', $names);
    }

    /**
     * A user without course access gets warnings, not an exception.
     *
     * validate_context(context_system) only requires a logged-in user. Per-course failures
     * are turned into warnings by validate_courses and by the per-course capability check;
     * require_login_exception would only occur when no user is set.
     */
    public function test_user_without_course_access_gets_warnings(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course1->id, 'name' => 'A']);
        $course2 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course2->id, 'name' => 'B']);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = list_course_assignments::execute(0, 10);

        $this->assertNull($result['total']);
        $this->assertSame([], $result['courses']);
        $this->assertCount(2, $result['warnings']);
        $warningids = array_map(
            static fn(array $warning): int => (int) $warning['itemid'],
            $result['warnings']
        );
        $this->assertContains((int) $course1->id, $warningids);
        $this->assertContains((int) $course2->id, $warningids);
    }

    /**
     * A caller without site-wide course access does not learn the site-wide total.
     */
    public function test_total_hidden_from_user_without_site_course_view(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course1->id, 'name' => 'A']);
        $course2 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course2->id, 'name' => 'B']);
        $course3 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course3->id, 'name' => 'C']);

        $this->assertSame(3, list_course_assignments::execute(0, 10)['total']);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $course2->id, 'student');
        $this->setUser($user);

        $result = list_course_assignments::execute(0, 10);

        $this->assertNull($result['total']);
        $this->assertCount(1, $result['courses']);
        $this->assertSame((int) $course2->id, (int) $result['courses'][0]['id']);
        $this->assertCount(2, $result['warnings']);

        $cleaned = \core_external\external_api::clean_returnvalue(list_course_assignments::execute_returns(), $result);
        $this->assertNull($cleaned['total']);
    }

    /**
     * The provisioned service user sees assignments without being enrolled.
     */
    public function test_service_user_not_enrolled(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Service course']);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Service assignment',
        ]);

        provisioner::provision();
        $serviceuser = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $this->assertFalse($DB->record_exists('user_enrolments', ['userid' => $serviceuser->id]));
        $this->setUser($serviceuser);

        $result = list_course_assignments::execute(0, 10);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['courses']);
        $this->assertSame((int) $course->id, (int) $result['courses'][0]['id']);
        $this->assertCount(1, $result['courses'][0]['assignments']);
    }

    /**
     * Offset and limit are clamped to the supported range.
     */
    public function test_offset_and_limit_are_clamped(): void {
        $result = list_course_assignments::execute(-5, 1000);
        $this->assertSame(0, $result['offset']);
        $this->assertSame(100, $result['limit']);

        $result = list_course_assignments::execute(0, 0);
        $this->assertSame(1, $result['limit']);
    }
}
