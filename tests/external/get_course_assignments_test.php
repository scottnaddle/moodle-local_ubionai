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
 * Tests for local_ubionai_get_course_assignments.
 *
 * @covers \local_ubionai\external\get_course_assignments
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ubionai\external\get_course_assignments::class)]
final class get_course_assignments_test extends \advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A point-graded assignment is returned for a course the user is not enrolled in.
     */
    public function test_point_assignment_without_enrolment(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Assignment course',
            'shortname' => 'ASG101',
        ]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Point assignment',
            'grade' => 100,
            'duedate' => 1700000000,
        ]);
        $cm = $DB->get_record('course_modules',
            ['instance' => $assign->id, 'course' => $course->id], '*', MUST_EXIST);

        $result = get_course_assignments::execute((int) $course->id);

        $this->assertCount(1, $result['courses']);
        $courseout = $result['courses'][0];
        $this->assertSame((int) $course->id, $courseout['id']);
        $this->assertSame('Assignment course', $courseout['fullname']);
        $this->assertSame('ASG101', $courseout['shortname']);
        $this->assertCount(1, $courseout['assignments']);

        $assignment = $courseout['assignments'][0];
        $this->assertSame((int) $assign->id, $assignment['id']);
        $this->assertSame((int) $cm->id, $assignment['cmid']);
        $this->assertSame((int) $course->id, $assignment['course']);
        $this->assertSame('Point assignment', $assignment['name']);
        $this->assertTrue($assignment['visible']);
        $this->assertSame(1700000000, $assignment['duedate']);
        $this->assertSame('point', $assignment['gradetype']);
        $this->assertEqualsWithDelta(100.0, $assignment['grademax'], 0.00001);
        $this->assertSame(0, $assignment['scaleid']);
        $this->assertEqualsWithDelta(0.0, $assignment['gradepass'], 0.00001);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * grade types come from gradelib, which must be loaded inside execute().
     *
     * The module generator loads mod/assign/locallib.php (and gradelib) in
     * PHPUnit, but REST requests do not, so guard the runtime require directly.
     */
    public function test_gradelib_is_required_for_grade_constants(): void {
        $source = file_get_contents(
            __DIR__ . '/../../classes/external/get_course_assignments.php'
        );
        $this->assertStringContainsString("'/gradelib.php'", $source);
        $this->assertStringNotContainsString("'/grade/grade_item.php'", $source);
    }

    /**
     * An ungraded assignment reports no grade type without undefined constants.
     */
    public function test_ungraded_assignment(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Ungraded assignment',
            'grade' => 0,
        ]);

        $result = get_course_assignments::execute((int) $course->id);

        $this->assertCount(1, $result['courses']);
        $this->assertCount(1, $result['courses'][0]['assignments']);
        $assignment = $result['courses'][0]['assignments'][0];
        $this->assertSame('none', $assignment['gradetype']);
    }

    /**
     * A scale-graded assignment reports its scale id.
     */
    public function test_scale_assignment(): void {
        $course = $this->getDataGenerator()->create_course();
        $scale = $this->getDataGenerator()->create_scale([
            'name' => 'Assignment scale',
            'scale' => 'Poor,Good,Excellent',
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Scale assignment',
            'grade' => -(int) $scale->id,
        ]);

        $result = get_course_assignments::execute((int) $course->id);

        $this->assertCount(1, $result['courses']);
        $this->assertCount(1, $result['courses'][0]['assignments']);
        $assignment = $result['courses'][0]['assignments'][0];
        $this->assertSame('scale', $assignment['gradetype']);
        $this->assertSame((int) $scale->id, $assignment['scaleid']);
        $this->assertLessThan(0, (int) $assignment['grade']);
    }

    /**
     * The provisioned service user sees assignments without being enrolled.
     */
    public function test_service_user_not_enrolled(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Service user assignment',
            'grade' => 50,
        ]);

        provisioner::provision();
        $serviceuser = $DB->get_record('user', ['username' => provisioner::USERNAME], '*', MUST_EXIST);
        $this->setUser($serviceuser);

        $result = get_course_assignments::execute((int) $course->id);

        $this->assertCount(1, $result['courses']);
        $this->assertCount(1, $result['courses'][0]['assignments']);
        $this->assertSame('Service user assignment', $result['courses'][0]['assignments'][0]['name']);
        $this->assertSame('point', $result['courses'][0]['assignments'][0]['gradetype']);
    }

    /**
     * A course without assignments returns an empty assignment list.
     */
    public function test_course_without_assignments(): void {
        $course = $this->getDataGenerator()->create_course();

        $result = get_course_assignments::execute((int) $course->id);

        $this->assertCount(1, $result['courses']);
        $this->assertSame([], $result['courses'][0]['assignments']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * A user lacking mod/assign:view gets a required_capability_exception.
     */
    public function test_missing_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'ubionai_viewonly']);
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid,
            \context_system::instance()->id, true);
        role_assign($roleid, (int) $user->id, \context_system::instance()->id);

        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_course_assignments::execute((int) $course->id);
    }
}
