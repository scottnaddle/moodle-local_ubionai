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
use stdClass;

/**
 * Define (or replace) the rubric of an activity.
 *
 * Advanced grading areas (assign submissions, forum whole-forum grading, ...) get a ready gradingform_rubric
 * definition; workshops using the rubric grading strategy get their assessment form replaced.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_rubric extends external_api {
    /** @var int Maximum number of criteria. */
    private const MAX_CRITERIA = 30;

    /** @var int Maximum number of levels per criterion. */
    private const MAX_LEVELS = 10;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_TEXT, 'Rubric name'),
            'criteria' => new external_value(PARAM_RAW,
                'JSON array: [{"description": "...", "levels": [{"definition": "...", "score": 0}]}]'),
            'description' => new external_value(PARAM_RAW, 'Rubric description (HTML)', VALUE_DEFAULT, ''),
            'areaname' => new external_value(PARAM_AREA, 'Grading area (ignored for workshops)', VALUE_DEFAULT,
                'submissions'),
        ]);
    }

    /**
     * Save the rubric.
     *
     * @param int $cmid course module id
     * @param string $name rubric name
     * @param string $criteria JSON criteria
     * @param string $description rubric description
     * @param string $areaname grading area
     * @return array
     */
    public static function execute(int $cmid, string $name, string $criteria, string $description = '',
            string $areaname = 'submissions'): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'name' => $name,
            'criteria' => $criteria,
            'description' => $description,
            'areaname' => $areaname,
        ]);
        $name = trim($params['name']);
        if ($name === '') {
            throw new invalid_parameter_exception('name must not be empty');
        }

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        if ($cm->modname === 'workshop') {
            $parsed = self::parse_criteria($params['criteria'], true);
            return self::save_workshop_rubric($cm, $context, $parsed);
        }

        $parsed = self::parse_criteria($params['criteria'], false);
        require_capability('moodle/grade:managegradingforms', $context);

        $manager = get_grading_manager($context, 'mod_' . $cm->modname, $params['areaname']);
        $areas = $manager->get_available_areas();
        if (!array_key_exists($params['areaname'], $areas)) {
            $known = $areas ? implode(', ', array_keys($areas)) : 'none';
            throw new invalid_parameter_exception(
                "Activity {$cm->id} ({$cm->modname}) has no grading area '{$params['areaname']}' (available: {$known})");
        }
        if (!array_key_exists('rubric', $manager->get_available_methods(false))) {
            throw new invalid_parameter_exception('The rubric grading method (gradingform_rubric) is not available');
        }

        // Refuse before switching the method, so a used rubric is never touched.
        $controller = $manager->get_controller('rubric');
        if ($controller->has_active_instances()) {
            throw new invalid_parameter_exception('The current rubric has already been used for grading; edit it in Moodle');
        }

        $newcriteria = [];
        foreach ($parsed as $i => [$criteriondescription, $levels]) {
            $newlevels = [];
            foreach ($levels as $j => [$definition, $score]) {
                $newlevels['NEWID' . ($j + 1)] = ['definition' => $definition, 'score' => $score];
            }
            $newcriteria['NEWID' . ($i + 1)] = [
                'sortorder' => $i + 1,
                'description' => $criteriondescription,
                'levels' => $newlevels,
            ];
        }
        $options = \gradingform_rubric_controller::get_default_options();
        // Show the rubric (with level scores) to students before and after grading.
        $options['alwaysshowdefinition'] = 1;
        $options['showdescriptionstudent'] = 1;
        $options['showscorestudent'] = 1;
        $options['showremarksstudent'] = 1;

        $definition = (object) [
            'name' => $name,
            // No draft item: the description is stored as it is.
            'description_editor' => ['text' => $params['description'], 'format' => FORMAT_HTML, 'itemid' => 0],
            'rubric' => ['criteria' => $newcriteria, 'options' => $options],
            'status' => \gradingform_controller::DEFINITION_STATUS_READY,
        ];

        $transaction = $DB->start_delegated_transaction();
        $manager->set_active_method('rubric');
        // Existing criteria are not in the new definition, so update_definition() deletes them.
        $controller->update_definition($definition);
        $transaction->allow_commit();
        $saved = $controller->get_definition(true);

        return [
            'definitionid' => (int) $saved->id,
            'method' => 'rubric',
            'criteria' => count($parsed),
            'maxscore' => (float) $controller->get_min_max_score()['maxscore'],
        ];
    }

    /**
     * Replace the assessment form of a workshop using the rubric grading strategy.
     *
     * @param stdClass $cm course module
     * @param \context_module $context module context
     * @param array $parsed list of [description, [[definition, score], ...]]
     * @return array
     */
    private static function save_workshop_rubric(stdClass $cm, \context_module $context, array $parsed): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/workshop/locallib.php');

        require_capability('mod/workshop:editdimensions', $context);
        $record = $DB->get_record('workshop', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($record->strategy !== 'rubric') {
            throw new invalid_parameter_exception(
                "Workshop {$cm->id} uses the '{$record->strategy}' grading strategy; set strategy to 'rubric' first");
        }
        $assessed = $DB->record_exists_sql(
            "SELECT 1
               FROM {workshop_assessments} a
               JOIN {workshop_submissions} s ON s.id = a.submissionid
              WHERE s.workshopid = :workshopid AND a.grade IS NOT NULL",
            ['workshopid' => $record->id]);
        if ($assessed) {
            throw new invalid_parameter_exception('This workshop already has assessments; edit its form in Moodle');
        }

        $workshop = new \workshop($record, $cm, get_course($cm->course), $context);
        $strategy = $workshop->grading_strategy_instance();

        // Shape the data like the strategy's edit form: new dimensions first, then the existing ones with an
        // empty description, which save_edit_strategy_form() deletes together with their levels.
        $raw = new stdClass();
        $raw->config_layout = 'grid';
        $i = 0;
        foreach ($parsed as [$description, $levels]) {
            $raw->{'dimensionid__idx_' . $i} = 0;
            $raw->{'description__idx_' . $i . '_editor'} = ['text' => $description, 'format' => FORMAT_HTML, 'itemid' => 0];
            // The strategy expects the levels ordered by grade.
            usort($levels, fn($a, $b) => $a[1] <=> $b[1]);
            foreach ($levels as $j => [$definition, $score]) {
                $raw->{'levelid__idx_' . $i . '__idy_' . $j} = 0;
                $raw->{'grade__idx_' . $i . '__idy_' . $j} = (int) $score;
                $raw->{'definition__idx_' . $i . '__idy_' . $j} = $definition;
            }
            $i++;
        }
        $existing = $DB->get_records('workshopform_rubric', ['workshopid' => $record->id], 'sort', 'id');
        foreach (array_keys($existing) as $dimensionid) {
            $raw->{'dimensionid__idx_' . $i} = $dimensionid;
            $raw->{'description__idx_' . $i . '_editor'} = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
            $levelids = $DB->get_fieldset_select('workshopform_rubric_levels', 'id', 'dimensionid = ?', [$dimensionid]);
            foreach (array_values($levelids) as $j => $levelid) {
                $raw->{'levelid__idx_' . $i . '__idy_' . $j} = $levelid;
                $raw->{'grade__idx_' . $i . '__idy_' . $j} = 0;
                $raw->{'definition__idx_' . $i . '__idy_' . $j} = '';
            }
            $i++;
        }
        $raw->norepeats = $i;

        $transaction = $DB->start_delegated_transaction();
        $strategy->save_edit_strategy_form($raw);
        $transaction->allow_commit();

        $maxscore = 0.0;
        foreach ($parsed as [, $levels]) {
            $maxscore += max(array_column($levels, 1));
        }

        return [
            'definitionid' => 0,
            'method' => 'workshopform_rubric',
            'criteria' => count($parsed),
            'maxscore' => $maxscore,
        ];
    }

    /**
     * Decode and validate the criteria JSON (mirrors the rubric editor validation).
     *
     * @param string $json criteria JSON
     * @param bool $workshop whether the rubric is a workshop assessment form (integer scores 0..100)
     * @return array list of [description, [[definition, score], ...]]
     * @throws invalid_parameter_exception
     */
    private static function parse_criteria(string $json, bool $workshop): array {
        $criteria = helper::decode_json_list($json, 'criteria');
        if (!$criteria) {
            throw new invalid_parameter_exception('criteria must not be empty');
        }
        if (count($criteria) > self::MAX_CRITERIA) {
            throw new invalid_parameter_exception('criteria must have at most ' . self::MAX_CRITERIA . ' items');
        }
        $parsed = [];
        $maxtotal = 0.0;
        $mintotal = 0.0;
        foreach ($criteria as $i => $criterion) {
            $where = "criteria[{$i}]";
            if (!is_array($criterion) || array_is_list($criterion)) {
                throw new invalid_parameter_exception("{$where} must be an object");
            }
            $description = trim((string) ($criterion['description'] ?? ''));
            if ($description === '') {
                throw new invalid_parameter_exception("{$where}.description must not be empty");
            }
            $levels = $criterion['levels'] ?? null;
            if (!is_array($levels) || !array_is_list($levels) || count($levels) < 2) {
                throw new invalid_parameter_exception("{$where}.levels must be a list with at least 2 levels");
            }
            if (count($levels) > self::MAX_LEVELS) {
                throw new invalid_parameter_exception("{$where}.levels must have at most " . self::MAX_LEVELS . ' items');
            }
            $parsedlevels = [];
            foreach ($levels as $j => $level) {
                $definition = is_array($level) ? trim((string) ($level['definition'] ?? '')) : '';
                if ($definition === '') {
                    throw new invalid_parameter_exception("{$where}.levels[{$j}].definition must not be empty");
                }
                $score = $level['score'] ?? null;
                if (is_bool($score) || !is_numeric($score) || $score < 0) {
                    throw new invalid_parameter_exception("{$where}.levels[{$j}].score must be a number >= 0");
                }
                $score = (float) $score;
                if ($workshop && ($score != (int) $score || $score > 100)) {
                    throw new invalid_parameter_exception(
                        "{$where}.levels[{$j}].score must be a whole number between 0 and 100 for a workshop");
                }
                $parsedlevels[] = [$definition, $score];
            }
            $scores = array_column($parsedlevels, 1);
            if (count(array_unique(array_map('strval', $scores))) !== count($scores)) {
                throw new invalid_parameter_exception("{$where}.levels must have different scores");
            }
            $maxtotal += max($scores);
            $mintotal += min($scores);
            $parsed[] = [$description, $parsedlevels];
        }
        if ($maxtotal <= 0 || $maxtotal <= $mintotal) {
            throw new invalid_parameter_exception('The rubric maximum score must be greater than 0 and than its minimum');
        }
        return $parsed;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'definitionid' => new external_value(PARAM_INT, 'grading_definitions id (0 for a workshop assessment form)'),
            'method' => new external_value(PARAM_ALPHANUMEXT, 'rubric or workshopform_rubric'),
            'criteria' => new external_value(PARAM_INT, 'Number of criteria saved'),
            'maxscore' => new external_value(PARAM_FLOAT, 'Sum of the highest level score of every criterion'),
        ]);
    }
}
