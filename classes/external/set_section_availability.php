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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ubionai\local\helper;

/**
 * Set the access restrictions (availability tree) of a course section.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_section_availability extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'section' => new external_value(PARAM_INT, 'Section number (not id)'),
            'availability' => new external_value(PARAM_RAW, 'core_availability tree as JSON; "" = no restrictions'),
        ]);
    }

    /**
     * Update the section availability.
     *
     * @param int $courseid course id
     * @param int $section section number
     * @param string $availability availability JSON
     * @return array
     */
    public static function execute(int $courseid, int $section, string $availability): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'section' => $section,
            'availability' => $availability,
        ]);
        $json = helper::normalise_availability($params['availability'], 'availability');

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);

        $sectioninfo = helper::get_section($course, $params['section']);
        course_update_section($course, (object) ['id' => $sectioninfo->id], ['availability' => $json]);

        return [
            'sectionid' => (int) $sectioninfo->id,
            'availability' => $json,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sectionid' => new external_value(PARAM_INT, 'Section id'),
            'availability' => new external_value(PARAM_RAW, 'Stored availability JSON, null when unrestricted',
                VALUE_REQUIRED, null, NULL_ALLOWED),
        ]);
    }
}
