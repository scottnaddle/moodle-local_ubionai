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
use invalid_parameter_exception;
use local_ubionai\local\helper;

/**
 * Create an activity of one of the supported module types (see helper::SUPPORTED_MODULES).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_module extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'section' => new external_value(PARAM_INT, 'Section number (not id)'),
            'modname' => new external_value(PARAM_PLUGIN, 'Module type: assign, quiz, forum, page, resource, url, '
                . 'workshop, wiki, glossary, feedback, h5pactivity or openmaic'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'intro' => new external_value(PARAM_RAW, 'Description (HTML)', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_INT, '1 = visible, 0 = hidden', VALUE_DEFAULT, 1),
            'settings' => new external_value(PARAM_RAW, 'JSON object of module settings', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Create the module.
     *
     * @param int $courseid course id
     * @param int $section section number
     * @param string $modname module name
     * @param string $name activity name
     * @param string $intro description HTML
     * @param int $visible visibility
     * @param string $settings JSON settings
     * @return array
     */
    public static function execute(
        int $courseid,
        int $section,
        string $modname,
        string $name,
        string $intro = '',
        int $visible = 1,
        string $settings = '{}'
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'section' => $section,
            'modname' => $modname,
            'name' => $name,
            'intro' => $intro,
            'visible' => $visible,
            'settings' => $settings,
        ]);

        if (!in_array($params['modname'], helper::SUPPORTED_MODULES, true)) {
            throw new invalid_parameter_exception('modname must be one of: ' . implode(', ', helper::SUPPORTED_MODULES));
        }
        if (trim($params['name']) === '') {
            throw new invalid_parameter_exception('name must not be empty');
        }
        if ($params['section'] < 0) {
            throw new invalid_parameter_exception('section must be >= 0');
        }
        $settingsdata = helper::decode_json_object($params['settings'], 'settings');

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);
        $module = $DB->get_record('modules', ['name' => $params['modname']]);
        if (!$module || !$module->visible) {
            throw new invalid_parameter_exception("Module '{$params['modname']}' is not installed or is disabled on this site");
        }
        require_capability('mod/' . $params['modname'] . ':addinstance', $context);
        if (empty($params['visible'])) {
            require_capability('moodle/course:activityvisibility', $context);
        }

        $moduleinfo = (object) helper::module_defaults($params['modname']);
        $moduleinfo->modulename = $params['modname'];
        $moduleinfo->module = $module->id;
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $params['section'];
        $moduleinfo->name = trim($params['name']);
        $moduleinfo->visible = $params['visible'] ? 1 : 0;
        $moduleinfo->introeditor = [
            'text' => $params['intro'],
            'format' => FORMAT_HTML,
            'itemid' => IGNORE_FILE_MERGE,
        ];
        $moduleinfo->add = $params['modname'];
        helper::apply_settings($moduleinfo, $settingsdata, $params['modname']);
        helper::validate_module_settings($moduleinfo, $params['modname'], true);

        $moduleinfo = create_module($moduleinfo);

        return [
            'cmid' => (int) $moduleinfo->coursemodule,
            'instance' => (int) $moduleinfo->instance,
            'modname' => $params['modname'],
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'instance' => new external_value(PARAM_INT, 'Module instance id'),
            'modname' => new external_value(PARAM_PLUGIN, 'Module type'),
        ]);
    }
}
