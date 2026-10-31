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

namespace local_grokbot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_grokbot\local\helper;

/**
 * Build weighted grade categories directly under the course category and move activity grade items into them.
 *
 * @package    local_grokbot
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_grade_categories extends external_api {
    /** @var string Source recorded in the grade history. */
    private const SOURCE = 'local_grokbot';

    /** @var int Maximum number of categories per call. */
    private const MAX_CATEGORIES = 30;

    /**
     * Supported aggregations.
     *
     * @return int[] name => GRADE_AGGREGATE_* constant
     */
    private static function aggregations(): array {
        return [
            'weighted_mean' => GRADE_AGGREGATE_WEIGHTED_MEAN,
            'natural' => GRADE_AGGREGATE_SUM,
            'mean' => GRADE_AGGREGATE_MEAN,
        ];
    }

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'categories' => new external_value(PARAM_RAW,
                'JSON array: [{"name": "...", "weight": 25, "cmids": [12, {"cmid": 13, "itemnumber": 1}]}]'),
            'aggregation' => new external_value(PARAM_ALPHAEXT, 'weighted_mean, natural or mean', VALUE_DEFAULT,
                'weighted_mean'),
        ]);
    }

    /**
     * Create / update the categories and move the grade items.
     *
     * @param int $courseid course id
     * @param string $categories JSON categories
     * @param string $aggregation aggregation of the course category (and of new child categories)
     * @return array
     */
    public static function execute(int $courseid, string $categories, string $aggregation = 'weighted_mean'): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'categories' => $categories,
            'aggregation' => $aggregation,
        ]);
        $aggregations = self::aggregations();
        if (!array_key_exists($params['aggregation'], $aggregations)) {
            throw new invalid_parameter_exception('aggregation must be one of: ' . implode(', ', array_keys($aggregations)));
        }
        $aggregationcode = $aggregations[$params['aggregation']];

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/grade:manage', $context);

        $specs = self::parse_categories($params['categories'], $aggregationcode !== GRADE_AGGREGATE_MEAN);
        // Resolve every grade item before changing anything.
        $modinfo = get_fast_modinfo($course);
        $seen = [];
        foreach ($specs as $index => &$spec) {
            $spec['items'] = [];
            foreach ($spec['refs'] as [$cmid, $itemnumber]) {
                foreach (self::resolve_grade_items($modinfo, $course->id, $cmid, $itemnumber, $index) as $item) {
                    if (isset($seen[$item->id])) {
                        throw new invalid_parameter_exception(
                            "Grade item {$item->id} (cmid {$cmid}) is listed in more than one category");
                    }
                    $seen[$item->id] = true;
                    $spec['items'][] = $item;
                }
            }
        }
        unset($spec);

        $transaction = $DB->start_delegated_transaction();

        $coursecategory = \grade_category::fetch_course_category($course->id);
        if ((int) $coursecategory->aggregation !== $aggregationcode) {
            // set_properties() also converts the weights of the existing children.
            \grade_category::set_properties($coursecategory, (object) ['aggregation' => $aggregationcode]);
            $coursecategory->update(self::SOURCE);
        }

        $result = [];
        foreach ($specs as $spec) {
            $category = self::find_child_category($course->id, $coursecategory->id, $spec['name']);
            $created = false;
            if (!$category) {
                $category = new \grade_category(['courseid' => $course->id], false);
                $category->apply_default_settings();
                \grade_category::set_properties($category, (object) [
                    'fullname' => $spec['name'],
                    'aggregation' => $aggregationcode,
                    'parent' => $coursecategory->id,
                ]);
                $category->apply_forced_settings();
                $category->insert(self::SOURCE);
                $created = true;
            }

            $categoryitem = $category->load_grade_item();
            if ($spec['weight'] !== null) {
                if ($aggregationcode === GRADE_AGGREGATE_WEIGHTED_MEAN) {
                    $categoryitem->aggregationcoef = $spec['weight'];
                } else if ($aggregationcode === GRADE_AGGREGATE_SUM) {
                    // Natural weights are stored as fractions of 1 and marked as overridden.
                    $categoryitem->aggregationcoef2 = $spec['weight'] / 100;
                    $categoryitem->weightoverride = 1;
                }
                $categoryitem->update(self::SOURCE);
            }

            $itemids = [];
            foreach ($spec['items'] as $item) {
                if ((int) $item->categoryid !== (int) $category->id) {
                    $item->set_parent($category->id);
                }
                $itemids[] = (int) $item->id;
            }

            $result[] = [
                'name' => $spec['name'],
                'categoryid' => (int) $category->id,
                'gradeitemid' => (int) $categoryitem->id,
                'weight' => $spec['weight'],
                'created' => $created,
                'itemids' => $itemids,
            ];
        }

        $transaction->allow_commit();

        // Grade items that still sit directly in the course category also take part in its aggregation.
        $uncategorised = $DB->count_records_select('grade_items',
            "courseid = :courseid AND categoryid = :categoryid AND itemtype <> 'course' AND itemtype <> 'category'",
            ['courseid' => $course->id, 'categoryid' => $coursecategory->id]);

        return [
            'coursecategoryid' => (int) $coursecategory->id,
            'aggregation' => $params['aggregation'],
            'categories' => $result,
            'uncategorised' => $uncategorised,
        ];
    }

    /**
     * Decode and validate the categories JSON.
     *
     * @param string $json categories JSON
     * @param bool $weighted whether a weight is required
     * @return array list of [name, weight, refs => [[cmid, itemnumber|null], ...]]
     * @throws invalid_parameter_exception
     */
    private static function parse_categories(string $json, bool $weighted): array {
        $categories = helper::decode_json_list($json, 'categories');
        if (!$categories) {
            throw new invalid_parameter_exception('categories must not be empty');
        }
        if (count($categories) > self::MAX_CATEGORIES) {
            throw new invalid_parameter_exception('categories must have at most ' . self::MAX_CATEGORIES . ' items');
        }
        $specs = [];
        $names = [];
        foreach ($categories as $i => $category) {
            $where = "categories[{$i}]";
            if (!is_array($category) || array_is_list($category)) {
                throw new invalid_parameter_exception("{$where} must be an object");
            }
            $name = clean_param(trim((string) ($category['name'] ?? '')), PARAM_TEXT);
            if ($name === '' || \core_text::strlen($name) > 255) {
                throw new invalid_parameter_exception("{$where}.name must be 1-255 characters");
            }
            if (isset($names[$name])) {
                throw new invalid_parameter_exception("{$where}.name '{$name}' is used twice");
            }
            $names[$name] = true;

            $weight = $category['weight'] ?? null;
            if ($weight === null) {
                if ($weighted) {
                    throw new invalid_parameter_exception("{$where}.weight is required");
                }
            } else if (is_bool($weight) || !is_numeric($weight) || $weight < 0) {
                throw new invalid_parameter_exception("{$where}.weight must be a number >= 0");
            } else {
                $weight = (float) $weight;
            }

            $cmids = $category['cmids'] ?? [];
            if (!is_array($cmids) || !array_is_list($cmids)) {
                throw new invalid_parameter_exception("{$where}.cmids must be a list");
            }
            $refs = [];
            foreach ($cmids as $j => $ref) {
                if (is_int($ref) && $ref > 0) {
                    $refs[] = [$ref, null];
                } else if (is_array($ref) && is_int($ref['cmid'] ?? null) && $ref['cmid'] > 0
                        && is_int($ref['itemnumber'] ?? null) && $ref['itemnumber'] >= 0) {
                    $refs[] = [$ref['cmid'], $ref['itemnumber']];
                } else {
                    throw new invalid_parameter_exception(
                        "{$where}.cmids[{$j}] must be a cmid or {\"cmid\": int, \"itemnumber\": int}");
                }
            }
            $specs[] = ['name' => $name, 'weight' => $weight, 'refs' => $refs];
        }
        return $specs;
    }

    /**
     * Load the grade items of an activity.
     *
     * @param \course_modinfo $modinfo course modinfo
     * @param int $courseid course id
     * @param int $cmid course module id
     * @param int|null $itemnumber only this item number (e.g. 1 = workshop assessment grade); null = all items
     * @param int $index category index, for error messages
     * @return \grade_item[]
     * @throws invalid_parameter_exception
     */
    private static function resolve_grade_items(\course_modinfo $modinfo, int $courseid, int $cmid, ?int $itemnumber,
            int $index): array {
        $cm = $modinfo->get_cms()[$cmid] ?? null;
        if (!$cm) {
            throw new invalid_parameter_exception("categories[{$index}]: cmid {$cmid} is not an activity of this course");
        }
        $items = \grade_item::fetch_all(['itemtype' => 'mod', 'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance, 'courseid' => $courseid]) ?: [];
        // Outcome items (itemnumber >= 1000) stay where they are.
        $items = array_filter($items, fn($item) => $item->itemnumber < 1000
            && ($itemnumber === null || (int) $item->itemnumber === $itemnumber));
        if (!$items) {
            $what = $itemnumber === null ? 'no grade item' : "no grade item number {$itemnumber}";
            throw new invalid_parameter_exception("categories[{$index}]: activity {$cmid} ({$cm->modname}) has {$what}");
        }
        usort($items, fn($a, $b) => $a->itemnumber <=> $b->itemnumber);
        return $items;
    }

    /**
     * Find an existing category by name directly under the course category.
     *
     * @param int $courseid course id
     * @param int $parentid course category id
     * @param string $name category name
     * @return \grade_category|null
     */
    private static function find_child_category(int $courseid, int $parentid, string $name): ?\grade_category {
        $children = \grade_category::fetch_all(['courseid' => $courseid, 'parent' => $parentid, 'fullname' => $name]);
        if (!$children) {
            return null;
        }
        ksort($children);
        return reset($children);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'coursecategoryid' => new external_value(PARAM_INT, 'Course grade category id'),
            'aggregation' => new external_value(PARAM_ALPHAEXT, 'Aggregation of the course category'),
            'categories' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Category name'),
                'categoryid' => new external_value(PARAM_INT, 'Grade category id'),
                'gradeitemid' => new external_value(PARAM_INT, 'Grade item id of the category total'),
                'weight' => new external_value(PARAM_FLOAT, 'Weight set on the category', VALUE_REQUIRED, null,
                    NULL_ALLOWED),
                'created' => new external_value(PARAM_BOOL, 'Whether the category was created by this call'),
                'itemids' => new external_multiple_structure(new external_value(PARAM_INT, 'Grade item id moved in')),
            ])),
            'uncategorised' => new external_value(PARAM_INT,
                'Activity grade items still directly in the course category (they count in its aggregation too)'),
        ]);
    }
}
