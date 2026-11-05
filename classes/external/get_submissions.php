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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use grade_item;

/**
 * Read the submitted attempts of an assignment, with their text and current grade.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submissions extends external_api {
    /** @var int Maximum number of characters returned from one text source. */
    private const MAX_TEXT_LENGTH = 20000;

    /** @var int Files larger than this are listed but not read. */
    private const MAX_TEXT_FILE_BYTES = 5242880;

    /** @var string[] File extensions whose content is returned as text. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'html', 'htm'];

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id (not cmid)'),
        ]);
    }

    /**
     * Return the assignment settings and the latest submitted attempt of each user.
     *
     * @param int $courseid course id
     * @param int $assignid assignment instance id
     * @return array
     */
    public static function execute(int $courseid, int $assignid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'assignid' => $assignid,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, IGNORE_MISSING);
        if (empty($cm)) {
            throw new \invalid_parameter_exception(get_string('errorassignnotincourse', 'local_ubionai'));
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        [$maxgrade, $advancedgradingmethod, $advancedgradingdefinition] = self::grading_settings(
            (int) $assign->id,
            $context
        );

        return [
            'assignid' => (int) $assign->id,
            'name' => (string) $assign->name,
            'intro' => self::to_plain_text(format_text($assign->intro, $assign->introformat, ['context' => $context])),
            'maxgrade' => $maxgrade,
            'advancedgradingmethod' => $advancedgradingmethod,
            'advancedgradingdefinition' => $advancedgradingdefinition,
            'submissions' => self::get_latest_submissions($assign, $context),
        ];
    }

    /**
     * Resolve the grade maximum and the active advanced grading definition as plain text.
     *
     * @param int $assignid assignment instance id
     * @param \context_module $context module context
     * @return array [float maxgrade, string method, string definition]
     */
    private static function grading_settings(int $assignid, \context_module $context): array {
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'itemnumber' => 0,
            'courseid' => $context->get_course_context()->instanceid,
        ]);

        $maxgrade = 0.0;
        if ($gradeitem && (int) $gradeitem->gradetype !== GRADE_TYPE_NONE) {
            $maxgrade = (float) $gradeitem->grademax;
        }

        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        $method = (string) $manager->get_active_method();
        $definitiontext = '';
        if ($method === 'rubric' || $method === 'guide') {
            $controller = $manager->get_controller($method);
            if ($controller->is_form_defined()) {
                $definitiontext = self::definition_to_text($method, $controller->get_definition(), $context);
            }
        }

        return [$maxgrade, $method, $definitiontext];
    }

    /**
     * Flatten a rubric or grading-guide definition into readable text.
     *
     * @param string $method rubric or guide
     * @param \stdClass|false $definition grading definition
     * @param \context_module $context module context
     * @return string
     */
    private static function definition_to_text(string $method, $definition, \context_module $context): string {
        if (empty($definition)) {
            return '';
        }

        $lines = [];
        $criteria = $method === 'rubric'
            ? (self::field($definition, 'rubric_criteria', []) ?: [])
            : (self::field($definition, 'guide_criteria', []) ?: []);

        foreach ($criteria as $criterion) {
            $description = self::to_plain_text(format_text(
                (string) self::field($criterion, 'description', ''),
                (int) self::field($criterion, 'descriptionformat', FORMAT_HTML),
                ['context' => $context]
            ));
            if ($description === '') {
                $description = self::field($criterion, 'shortname', '');
            }

            if ($method === 'rubric') {
                $levels = [];
                foreach (self::field($criterion, 'levels', []) ?: [] as $level) {
                    $leveltext = self::to_plain_text(format_text(
                        (string) self::field($level, 'definition', ''),
                        (int) self::field($level, 'definitionformat', FORMAT_HTML),
                        ['context' => $context]
                    ));
                    $levels[] = $leveltext . ' (' . (float) self::field($level, 'score', 0) . ')';
                }
                $lines[] = $description . ': ' . implode(' | ', $levels);
            } else {
                $markers = self::to_plain_text(format_text(
                    (string) self::field($criterion, 'descriptionmarkers', ''),
                    (int) self::field($criterion, 'descriptionmarkersformat', FORMAT_HTML),
                    ['context' => $context]
                ));
                $lines[] = $description . ' (max ' . (float) self::field($criterion, 'maxscore', 0) . ')'
                    . ($markers !== '' ? ': ' . $markers : '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Read a property from an array or an object.
     *
     * @param mixed $item array or object
     * @param string $name property name
     * @param mixed $default fallback value
     * @return mixed
     */
    private static function field($item, string $name, $default = '') {
        if (is_array($item)) {
            return array_key_exists($name, $item) ? $item[$name] : $default;
        }
        return isset($item->$name) ? $item->$name : $default;
    }

    /**
     * Return the latest submitted attempt of every user, with text, files and grade.
     *
     * @param \stdClass $assign assignment record
     * @param \context_module $context module context
     * @return array
     */
    private static function get_latest_submissions(\stdClass $assign, \context_module $context): array {
        global $DB;

        $sql = "SELECT s.*
                  FROM {assign_submission} s
                  JOIN (SELECT userid, MAX(attemptnumber) AS maxattempt
                          FROM {assign_submission}
                         WHERE assignment = :assignment AND groupid = 0
                      GROUP BY userid) latest
                    ON latest.userid = s.userid AND latest.maxattempt = s.attemptnumber
                 WHERE s.assignment = :assignmenttwo AND s.groupid = 0 AND s.status = :status
              ORDER BY s.userid";
        $submissions = $DB->get_records_sql($sql, [
            'assignment' => $assign->id,
            'assignmenttwo' => $assign->id,
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
        ]);
        if (!$submissions) {
            return [];
        }

        $submissionids = array_keys($submissions);
        $userids = [];
        foreach ($submissions as $submission) {
            $userids[] = (int) $submission->userid;
        }
        $users = $DB->get_records_list('user', 'id', $userids);

        $onlinetexts = [];
        foreach ($DB->get_records_list('assignsubmission_onlinetext', 'submission', $submissionids) as $record) {
            $onlinetexts[(int) $record->submission] = $record;
        }

        $grades = self::latest_grades((int) $assign->id);
        $fs = get_file_storage();
        $result = [];

        foreach ($submissions as $submission) {
            $user = $users[(int) $submission->userid] ?? null;
            if (!$user) {
                continue;
            }

            $onlinetext = '';
            if (isset($onlinetexts[(int) $submission->id])) {
                $textrecord = $onlinetexts[(int) $submission->id];
                $onlinetext = self::to_plain_text(format_text(
                    (string) $textrecord->onlinetext,
                    (int) $textrecord->onlineformat,
                    ['context' => $context]
                ));
            }

            $files = [];
            $areas = [
                ['assignsubmission_file', ASSIGNSUBMISSION_FILE_FILEAREA],
                ['assignsubmission_onlinetext', ASSIGNSUBMISSION_ONLINETEXT_FILEAREA],
            ];
            foreach ($areas as [$component, $filearea]) {
                $storedfiles = $fs->get_area_files(
                    $context->id,
                    $component,
                    $filearea,
                    (int) $submission->id,
                    'id',
                    false
                );
                foreach ($storedfiles as $file) {
                    $files[] = self::file_to_array($file);
                }
            }

            $grade = $grades[(int) $submission->userid] ?? null;
            $result[] = [
                'userid' => (int) $submission->userid,
                'fullname' => fullname($user),
                'status' => (string) $submission->status,
                'attemptnumber' => (int) $submission->attemptnumber,
                'timemodified' => (int) $submission->timemodified,
                'onlinetext' => $onlinetext,
                'files' => $files,
                'grade' => $grade === null ? null : (float) $grade->grade,
                'gradetimemodified' => $grade === null ? null : (int) $grade->timemodified,
            ];
        }

        return $result;
    }

    /**
     * Latest grade record of every user in one assignment.
     *
     * @param int $assignid assignment instance id
     * @return \stdClass[] keyed by userid
     */
    private static function latest_grades(int $assignid): array {
        global $DB;

        $sql = "SELECT g.*
                  FROM {assign_grades} g
                  JOIN (SELECT userid, MAX(attemptnumber) AS maxattempt
                          FROM {assign_grades}
                         WHERE assignment = :assignment
                      GROUP BY userid) latest
                    ON latest.userid = g.userid AND latest.maxattempt = g.attemptnumber
                 WHERE g.assignment = :assignmenttwo";
        $grades = [];
        foreach ($DB->get_records_sql($sql, ['assignment' => $assignid, 'assignmenttwo' => $assignid]) as $grade) {
            $grades[(int) $grade->userid] = $grade;
        }
        return $grades;
    }

    /**
     * Describe one stored file, extracting text for simple text formats.
     *
     * @param \stored_file $file stored file
     * @return array
     */
    private static function file_to_array(\stored_file $file): array {
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $hastext = in_array($extension, self::TEXT_EXTENSIONS, true)
            && $file->get_filesize() <= self::MAX_TEXT_FILE_BYTES;
        $text = '';

        if ($hastext) {
            $content = (string) $file->get_content();
            if ($extension === 'html' || $extension === 'htm') {
                $content = self::to_plain_text($content);
            }
            $text = \core_text::substr($content, 0, self::MAX_TEXT_LENGTH);
        }

        return [
            'filename' => $file->get_filename(),
            'filesize' => (int) $file->get_filesize(),
            'hastext' => $hastext,
            'text' => $text,
        ];
    }

    /**
     * Convert formatted HTML into compact plain text.
     *
     * @param string $html formatted text
     * @return string
     */
    private static function to_plain_text(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $text = html_to_text($html, 0, false);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id'),
            'name' => new external_value(PARAM_TEXT, 'Assignment name'),
            'intro' => new external_value(PARAM_RAW, 'Assignment introduction as plain text'),
            'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade of the assignment (scale max for scales)'),
            'advancedgradingmethod' => new external_value(PARAM_TEXT, 'Active advanced grading method, empty if none'),
            'advancedgradingdefinition' => new external_value(PARAM_RAW, 'Rubric/guide definition as plain text'),
            'submissions' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'fullname' => new external_value(PARAM_TEXT, 'User full name'),
                    'status' => new external_value(PARAM_ALPHA, 'Submission status'),
                    'attemptnumber' => new external_value(PARAM_INT, 'Submission attempt number'),
                    'timemodified' => new external_value(PARAM_INT, 'Last modification time'),
                    'onlinetext' => new external_value(PARAM_RAW, 'Online text submission as plain text'),
                    'files' => new external_multiple_structure(
                        new external_single_structure([
                            'filename' => new external_value(PARAM_TEXT, 'File name'),
                            'filesize' => new external_value(PARAM_INT, 'File size in bytes'),
                            'hastext' => new external_value(PARAM_BOOL, 'Whether the content was extracted'),
                            'text' => new external_value(PARAM_RAW, 'Extracted text, empty when not extracted'),
                        ])
                    ),
                    'grade' => new external_value(
                        PARAM_FLOAT,
                        'Current grade, null when not graded',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'gradetimemodified' => new external_value(
                        PARAM_INT,
                        'Grade modification time, null when not graded',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                ])
            ),
        ]);
    }
}
