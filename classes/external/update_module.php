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
 * Update an existing activity.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_module extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_TEXT, 'New name; empty = unchanged', VALUE_DEFAULT, ''),
            'intro' => new external_value(PARAM_RAW, 'New description (HTML); empty = unchanged', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_INT, '1 = show, 0 = hide, -1 = unchanged', VALUE_DEFAULT, -1),
            'settings' => new external_value(PARAM_RAW, 'JSON object of module settings to change', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Update the module.
     *
     * @param int $cmid course module id
     * @param string $name new name
     * @param string $intro new intro
     * @param int $visible new visibility
     * @param string $settings JSON settings
     * @return array
     */
    public static function execute(int $cmid, string $name = '', string $intro = '', int $visible = -1,
            string $settings = '{}'): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'name' => $name,
            'intro' => $intro,
            'visible' => $visible,
            'settings' => $settings,
        ]);
        $settingsdata = helper::decode_json_object($params['settings'], 'settings');

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);
        if ($params['visible'] >= 0) {
            require_capability('moodle/course:activityvisibility', $context);
        }

        [$cm, $context, $module, $data, $cw] = get_moduleinfo_data($cm, $course);
        helper::preprocess_update_data($data, $cm, $course, $context);

        $data->update = $cm->id;
        if (trim($params['name']) !== '') {
            $data->name = trim($params['name']);
        }
        if ($params['intro'] !== '' && isset($data->introeditor)) {
            $data->introeditor['text'] = $params['intro'];
            $data->introeditor['format'] = FORMAT_HTML;
        }
        if ($params['visible'] >= 0) {
            $data->visible = $params['visible'] ? 1 : 0;
        }
        if (helper::touches_completion($settingsdata)) {
            $data->completionunlocked = 1;
        }

        $newquizgrade = null;
        if ($data->modulename === 'quiz' && array_key_exists('grade', $settingsdata)) {
            // quiz_update_instance() ignores the maximum grade; it is changed through the grade calculator.
            $newquizgrade = (float) $settingsdata['grade'];
            unset($settingsdata['grade']);
        }
        helper::apply_settings($data, $settingsdata, $data->modulename);
        helper::postprocess_update_data($data, $settingsdata);
        if (in_array($data->modulename, helper::SUPPORTED_MODULES, true)) {
            helper::validate_module_settings($data, $data->modulename, false);
        }

        update_moduleinfo($cm, $data, $course);

        if ($newquizgrade !== null) {
            \mod_quiz\quiz_settings::create((int) $cm->instance)->get_grade_calculator()
                ->update_quiz_maximum_grade($newquizgrade);
        }

        return [
            'cmid' => (int) $cm->id,
            'updated' => true,
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
            'updated' => new external_value(PARAM_BOOL, 'Whether the module was updated'),
        ]);
    }
}
