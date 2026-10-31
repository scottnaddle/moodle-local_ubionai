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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_ubionai\local\helper;

/**
 * Append new sections to a course.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_sections extends external_api {
    /** @var int Maximum number of sections added per call. */
    private const MAX_SECTIONS = 50;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'names' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Section name; "" = default name')
            ),
            'summaries' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Section summary (HTML), matched to names by position'),
                'Optional summaries', VALUE_DEFAULT, []
            ),
        ]);
    }

    /**
     * Create the sections at the end of the course.
     *
     * @param int $courseid course id
     * @param array $names section names
     * @param array $summaries section summaries
     * @return array
     */
    public static function execute(int $courseid, array $names, array $summaries = []): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'names' => $names,
            'summaries' => $summaries,
        ]);
        $names = array_values($params['names']);
        $summaries = array_values($params['summaries']);
        if (!$names) {
            throw new invalid_parameter_exception('names must not be empty');
        }
        if (count($names) > self::MAX_SECTIONS) {
            throw new invalid_parameter_exception('At most ' . self::MAX_SECTIONS . ' sections can be added per call');
        }
        if (count($summaries) > count($names)) {
            throw new invalid_parameter_exception('summaries must not have more items than names');
        }

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);
        if (!course_get_format($course)->uses_sections()) {
            throw new invalid_parameter_exception("The format of course {$course->id} does not use sections");
        }

        // Validate every name before creating anything.
        $fields = [];
        foreach ($names as $i => $name) {
            $fields[] = helper::section_fields($name, $summaries[$i] ?? null);
        }

        $result = [];
        foreach ($fields as $data) {
            $section = course_create_section($course->id);
            if ($data) {
                course_update_section($course->id, $section, $data);
            }
            $result[] = [
                'id' => (int) $section->id,
                'section' => (int) $section->section,
            ];
        }

        return ['sections' => $result];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Section id'),
                    'section' => new external_value(PARAM_INT, 'Section number'),
                ])
            ),
        ]);
    }
}
