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

namespace local_grokbot\local;

use invalid_parameter_exception;
use stdClass;

/**
 * Shared helpers for the local_grokbot external functions.
 *
 * @package    local_grokbot
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** @var string[] Module types supported by create_module. */
    public const SUPPORTED_MODULES = ['assign', 'quiz', 'forum'];

    /** @var string[] moduleinfo keys that callers may never override through the settings JSON. */
    private const PROTECTED_KEYS = [
        'id', 'course', 'module', 'modulename', 'coursemodule', 'instance', 'section', 'add', 'update',
        'introeditor', 'intro', 'introformat', 'name', 'visible', 'return', 'sr',
    ];

    /** @var string[] Quiz review option field names (without the timing suffix). */
    private const QUIZ_REVIEW_FIELDS = [
        'attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer',
        'overallfeedback',
    ];

    /** @var string[] Completion rule keys that imply automatic completion tracking when set. */
    private const COMPLETION_RULE_KEYS = [
        'completionsubmit', 'completionposts', 'completiondiscussions', 'completionreplies', 'completionusegrade',
        'completionpassgrade', 'completionattemptsexhausted', 'completionminattempts', 'completionview',
    ];

    /**
     * Decode a JSON object string into an associative array.
     *
     * @param string $json the JSON text
     * @param string $param parameter name, used in error messages
     * @return array
     * @throws invalid_parameter_exception
     */
    public static function decode_json_object(string $json, string $param): array {
        if (trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new invalid_parameter_exception("{$param} must be a JSON object");
        }
        return $data;
    }

    /**
     * Decode a JSON array string into a list.
     *
     * @param string $json the JSON text
     * @param string $param parameter name, used in error messages
     * @return array
     * @throws invalid_parameter_exception
     */
    public static function decode_json_list(string $json, string $param): array {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !array_is_list($data)) {
            throw new invalid_parameter_exception("{$param} must be a JSON array");
        }
        return $data;
    }

    /**
     * Default moduleinfo fields for a new module of the given type.
     *
     * Mirrors the test generators and mod_form defaults so add_moduleinfo() never trips over missing fields.
     *
     * @param string $modname assign, quiz or forum
     * @return array
     */
    public static function module_defaults(string $modname): array {
        $common = [
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'availability' => null,
            'completion' => 0,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
            'showdescription' => 0,
            'conditiongradegroup' => [],
            'conditionfieldgroup' => [],
            'conditioncompletiongroup' => [],
        ];

        switch ($modname) {
            case 'assign':
                $defaults = [
                    'alwaysshowdescription' => 1,
                    'submissiondrafts' => 0,
                    'requiresubmissionstatement' => 0,
                    'sendnotifications' => 0,
                    'sendstudentnotifications' => 1,
                    'sendlatenotifications' => 0,
                    'duedate' => 0,
                    'allowsubmissionsfromdate' => 0,
                    'grade' => 100,
                    'cutoffdate' => 0,
                    'gradingduedate' => 0,
                    'teamsubmission' => 0,
                    'requireallteammemberssubmit' => 0,
                    'teamsubmissiongroupingid' => 0,
                    'preventsubmissionnotingroup' => 0,
                    'blindmarking' => 0,
                    'hidegrader' => 0,
                    'attemptreopenmethod' => 'untilpass',
                    'maxattempts' => 1,
                    'markingworkflow' => 0,
                    'markingallocation' => 0,
                    'markinganonymous' => 0,
                    'activityformat' => FORMAT_HTML,
                    'timelimit' => 0,
                    'submissionattachments' => 0,
                    'completionsubmit' => 0,
                ];
                // Enable submission / feedback plugins according to their site-wide defaults.
                foreach (['assignsubmission', 'assignfeedback'] as $subtype) {
                    foreach (array_keys(\core_component::get_plugin_list($subtype)) as $plugin) {
                        $defaults["{$subtype}_{$plugin}_enabled"] = (int) !empty(get_config("{$subtype}_{$plugin}", 'default'));
                    }
                }
                $defaults['assignsubmission_onlinetext_enabled'] = 1;
                $defaults['assignsubmission_onlinetext_wordlimit'] = 0;
                $defaults['assignsubmission_onlinetext_wordlimit_enabled'] = 0;
                $defaults['assignsubmission_file_maxfiles'] = 1;
                $defaults['assignsubmission_file_maxsizebytes'] = 0;
                $defaults['assignsubmission_file_filetypes'] = '';
                $defaults['assignfeedback_comments_commentinline'] = 0;
                break;

            case 'quiz':
                $defaults = [
                    'timeopen' => 0,
                    'timeclose' => 0,
                    'duedate' => 0,
                    'timelimit' => 0,
                    'overduehandling' => 'autosubmit',
                    'graceperiod' => 0,
                    'preferredbehaviour' => 'deferredfeedback',
                    'canredoquestions' => 0,
                    'attempts' => 0,
                    'attemptonlast' => 0,
                    'grademethod' => 1, // QUIZ_GRADEHIGHEST.
                    'decimalpoints' => 2,
                    'questiondecimalpoints' => -1,
                    'questionsperpage' => 1,
                    'navmethod' => 'free',
                    'shuffleanswers' => 1,
                    'sumgrades' => 0,
                    'grade' => 10,
                    'quizpassword' => '',
                    'subnet' => '',
                    'browsersecurity' => '-',
                    'delay1' => 0,
                    'delay2' => 0,
                    'showuserpicture' => 0,
                    'showblocks' => 0,
                    'completionattemptsexhausted' => 0,
                    'completionminattempts' => 0,
                ];
                foreach (self::QUIZ_REVIEW_FIELDS as $field) {
                    foreach (['during', 'immediately', 'open', 'closed'] as $when) {
                        $defaults[$field . $when] = 1;
                    }
                }
                $defaults['overallfeedbackduring'] = 0;
                break;

            case 'forum':
                $defaults = [
                    'type' => 'general',
                    'assessed' => 0,
                    'scale' => 0,
                    'grade_forum' => 0,
                    'forcesubscribe' => 0, // FORUM_CHOOSESUBSCRIBE.
                    'trackingtype' => 1, // FORUM_TRACKING_OPTIONAL.
                    'maxbytes' => 0,
                    'maxattachments' => 9,
                    'displaywordcount' => 0,
                    'rsstype' => 0,
                    'rssarticles' => 0,
                    'duedate' => 0,
                    'cutoffdate' => 0,
                    'lockdiscussionafter' => 0,
                    'blockperiod' => 0,
                    'blockafter' => 0,
                    'warnafter' => 0,
                    'completiondiscussions' => 0,
                    'completionreplies' => 0,
                    'completionposts' => 0,
                ];
                break;

            default:
                throw new invalid_parameter_exception("Unsupported module type: {$modname}");
        }

        return array_merge($common, $defaults);
    }

    /**
     * Overlay settings from the caller onto a moduleinfo object.
     *
     * @param stdClass $moduleinfo moduleinfo being built
     * @param array $settings decoded settings JSON
     * @param string $modname module name
     */
    public static function apply_settings(stdClass $moduleinfo, array $settings, string $modname): void {
        // Forgive the common typo used in some API docs.
        if ($modname === 'forum' && array_key_exists('cutofftdate', $settings) && !array_key_exists('cutoffdate', $settings)) {
            $settings['cutoffdate'] = $settings['cutofftdate'];
        }
        unset($settings['cutofftdate']);

        foreach ($settings as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]+$/', $key)) {
                throw new invalid_parameter_exception("Invalid settings key: {$key}");
            }
            if (in_array($key, self::PROTECTED_KEYS, true)) {
                throw new invalid_parameter_exception("Setting '{$key}' cannot be set through settings");
            }
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $moduleinfo->$key = $value;
        }

        // Forum ratings: a positive scale means "point" ratings out of that maximum.
        if ($modname === 'forum' && !empty($moduleinfo->scale) && empty($moduleinfo->assessed)
                && !array_key_exists('assessed', $settings)) {
            $moduleinfo->assessed = 1; // RATING_AGGREGATE_AVERAGE.
        }

        // Turning on a completion rule implies automatic completion tracking.
        if (!array_key_exists('completion', $settings)) {
            foreach (self::COMPLETION_RULE_KEYS as $rule) {
                if (!empty($settings[$rule])) {
                    $moduleinfo->completion = COMPLETION_TRACKING_AUTOMATIC;
                    break;
                }
            }
        }
        if ($modname === 'quiz' && !empty($settings['completionminattempts'])) {
            $moduleinfo->completionminattemptsenabled = 1;
        }
    }

    /**
     * Whether any completion-related setting was supplied.
     *
     * @param array $settings decoded settings
     * @return bool
     */
    public static function touches_completion(array $settings): bool {
        foreach (array_keys($settings) as $key) {
            if (str_starts_with((string) $key, 'completion')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert stored module data into the form-shaped data that MODNAME_update_instance() expects.
     *
     * get_moduleinfo_data() returns the raw DB record, while the update callbacks of some modules expect
     * the processed mod_form data (see each mod_form::data_preprocessing()).
     *
     * @param stdClass $data data from get_moduleinfo_data()
     * @param \cm_info|stdClass $cm course module
     * @param stdClass $course course record
     * @param \context_module $context module context
     */
    public static function preprocess_update_data(stdClass $data, $cm, stdClass $course, \context_module $context): void {
        global $CFG, $DB;

        switch ($data->modulename) {
            case 'quiz':
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                // Review options are stored as bitmasks.
                $times = [
                    'during' => \mod_quiz\question\display_options::DURING,
                    'immediately' => \mod_quiz\question\display_options::IMMEDIATELY_AFTER,
                    'open' => \mod_quiz\question\display_options::LATER_WHILE_OPEN,
                    'closed' => \mod_quiz\question\display_options::AFTER_CLOSE,
                ];
                foreach (self::QUIZ_REVIEW_FIELDS as $field) {
                    $mask = (int) ($data->{'review' . $field} ?? 0);
                    foreach ($times as $when => $bit) {
                        $data->{$field . $when} = ($mask & $bit) ? 1 : 0;
                    }
                }
                $data->quizpassword = $data->password ?? '';
                unset($data->password);
                if (!empty($data->completionminattempts)) {
                    $data->completionminattemptsenabled = 1;
                }
                // Preserve overall feedback; quiz_after_add_or_update() rewrites it from the form data.
                $feedbacks = array_values($DB->get_records('quiz_feedback', ['quizid' => $data->instance], 'mingrade DESC'));
                if ($feedbacks) {
                    $data->feedbacktext = [];
                    $data->feedbackboundaries = [];
                    foreach ($feedbacks as $i => $feedback) {
                        $data->feedbacktext[$i] = [
                            'text' => $feedback->feedbacktext,
                            'format' => $feedback->feedbacktextformat,
                            'itemid' => 0,
                        ];
                        if ($feedback->mingrade > 0) {
                            $data->feedbackboundaries[$i] = $feedback->mingrade;
                        }
                    }
                }
                // Settings stored by quiz access rule plugins.
                foreach (\mod_quiz\access_manager::load_settings((int) $data->instance) as $name => $value) {
                    $data->$name = $value;
                }
                break;

            case 'assign':
                require_once($CFG->dirroot . '/mod/assign/locallib.php');
                $assign = new \assign($context, $cm, $course);
                $plugins = array_merge($assign->get_submission_plugins(), $assign->get_feedback_plugins());
                foreach ($plugins as $plugin) {
                    if (!$plugin->is_visible()) {
                        continue;
                    }
                    $prefix = $plugin->get_subtype() . '_' . $plugin->get_type();
                    $data->{$prefix . '_enabled'} = $plugin->is_enabled() ? 1 : 0;
                    foreach ((array) $plugin->get_config() as $name => $value) {
                        if ($name !== 'enabled') {
                            $data->{$prefix . '_' . $name} = $value;
                        }
                    }
                }
                // Plugins whose form field names differ from their config names.
                $renames = [
                    'assignsubmission_file_maxfilesubmissions' => 'assignsubmission_file_maxfiles',
                    'assignsubmission_file_maxsubmissionsizebytes' => 'assignsubmission_file_maxsizebytes',
                    'assignsubmission_file_filetypeslist' => 'assignsubmission_file_filetypes',
                    'assignsubmission_onlinetext_wordlimitenabled' => 'assignsubmission_onlinetext_wordlimit_enabled',
                ];
                foreach ($renames as $from => $to) {
                    if (property_exists($data, $from)) {
                        $data->$to = $data->$from;
                        unset($data->$from);
                    }
                }
                $data->assignsubmission_file_maxfiles = $data->assignsubmission_file_maxfiles ?? 1;
                $data->assignsubmission_file_maxsizebytes = $data->assignsubmission_file_maxsizebytes ?? 0;
                break;
        }
    }

    /**
     * Resolve the id of the 'grokbot' question category in a quiz's question bank, creating it if needed.
     *
     * @param \context_module $context quiz module context
     * @return stdClass category record
     */
    public static function get_or_create_question_category(\context_module $context): stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $top = question_get_top_category($context->id, true);
        $category = $DB->get_record('question_categories',
            ['contextid' => $context->id, 'parent' => $top->id, 'name' => 'grokbot'], '*', IGNORE_MULTIPLE);
        if ($category) {
            return $category;
        }

        $category = new stdClass();
        $category->name = 'grokbot';
        $category->info = 'Questions created by the Grokbot web service.';
        $category->infoformat = FORMAT_HTML;
        $category->contextid = $context->id;
        $category->parent = $top->id;
        $category->sortorder = 999;
        $category->stamp = make_unique_id_code();
        $category->idnumber = null;
        $category->id = $DB->insert_record('question_categories', $category);

        \core\event\question_category_created::create_from_question_category_instance($category, $context)->trigger();

        return $category;
    }
}
