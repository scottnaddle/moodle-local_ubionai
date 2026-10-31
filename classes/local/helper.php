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

namespace local_ubionai\local;

use invalid_parameter_exception;
use stdClass;

/**
 * Shared helpers for the local_ubionai external functions.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** @var string[] Module types supported by create_module. */
    public const SUPPORTED_MODULES = [
        'assign', 'quiz', 'forum', 'page', 'resource', 'url', 'workshop', 'wiki', 'glossary', 'feedback', 'h5pactivity',
        'openmaic',
    ];

    /** @var string[] moduleinfo keys that callers may never override through the settings JSON. */
    private const PROTECTED_KEYS = [
        'id', 'course', 'module', 'modulename', 'coursemodule', 'instance', 'section', 'add', 'update',
        'introeditor', 'intro', 'introformat', 'name', 'visible', 'return', 'sr', 'availabilityconditionsjson',
        'completionunlocked', 'page',
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
        'completionentries', 'completionminprogress',
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
     * @param string $modname one of SUPPORTED_MODULES
     * @return array
     */
    public static function module_defaults(string $modname): array {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

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

            case 'page':
                $config = get_config('page');
                $defaults = [
                    'content' => '',
                    'contentformat' => FORMAT_HTML,
                    'display' => (int) ($config->display ?? RESOURCELIB_DISPLAY_OPEN),
                    'popupwidth' => (int) ($config->popupwidth ?? 620),
                    'popupheight' => (int) ($config->popupheight ?? 450),
                    'printintro' => (int) ($config->printintro ?? 0),
                    'printlastmodified' => (int) ($config->printlastmodified ?? 1),
                    'revision' => 1,
                ];
                break;

            case 'resource':
                $config = get_config('resource');
                $defaults = [
                    'files' => 0,
                    'display' => (int) ($config->display ?? RESOURCELIB_DISPLAY_AUTO),
                    'popupwidth' => (int) ($config->popupwidth ?? 620),
                    'popupheight' => (int) ($config->popupheight ?? 450),
                    'printintro' => (int) ($config->printintro ?? 1),
                    'showsize' => (int) ($config->showsize ?? 0),
                    'showtype' => (int) ($config->showtype ?? 0),
                    'showdate' => (int) ($config->showdate ?? 0),
                    'filterfiles' => (int) ($config->filterfiles ?? 0),
                    'revision' => 1,
                    'tobemigrated' => 0,
                    'legacyfiles' => 0, // RESOURCELIB_LEGACYFILES_NO.
                ];
                break;

            case 'url':
                $config = get_config('url');
                $defaults = [
                    'externalurl' => '',
                    'display' => (int) ($config->display ?? RESOURCELIB_DISPLAY_AUTO),
                    'popupwidth' => (int) ($config->popupwidth ?? 620),
                    'popupheight' => (int) ($config->popupheight ?? 450),
                    'printintro' => (int) ($config->printintro ?? 1),
                ];
                break;

            case 'workshop':
                $config = get_config('workshop');
                $editor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
                $defaults = [
                    'strategy' => (string) ($config->strategy ?? 'accumulative'),
                    'grade' => (float) ($config->grade ?? 80),
                    'gradinggrade' => (float) ($config->gradinggrade ?? 20),
                    'gradedecimals' => (int) ($config->gradedecimals ?? 0),
                    'submissiontypetext' => 1, // WORKSHOP_SUBMISSION_TYPE_AVAILABLE.
                    'submissiontypefile' => 1,
                    'nattachments' => 1,
                    'submissionfiletypes' => '',
                    'maxbytes' => (int) ($config->maxbytes ?? 0),
                    'latesubmissions' => 0,
                    'useselfassessment' => 0,
                    'overallfeedbackmode' => 1,
                    'overallfeedbackfiles' => 0,
                    'overallfeedbackfiletypes' => '',
                    'overallfeedbackmaxbytes' => (int) ($config->maxbytes ?? 0),
                    'useexamples' => 0,
                    'examplesmode' => (int) ($config->examplesmode ?? 0),
                    'submissionstart' => 0,
                    'submissionend' => 0,
                    'phaseswitchassessment' => 0,
                    'assessmentstart' => 0,
                    'assessmentend' => 0,
                    // The editors carry no draft area: the raw HTML fields below are stored as they are.
                    'instructauthorseditor' => $editor,
                    'instructreviewerseditor' => $editor,
                    'conclusioneditor' => $editor,
                    'instructauthors' => '',
                    'instructauthorsformat' => FORMAT_HTML,
                    'instructreviewers' => '',
                    'instructreviewersformat' => FORMAT_HTML,
                    'conclusion' => '',
                    'conclusionformat' => FORMAT_HTML,
                ];
                break;

            case 'wiki':
                $defaults = [
                    'wikimode' => 'collaborative',
                    'firstpagetitle' => '',
                    'defaultformat' => 'html',
                    'forceformat' => 0,
                ];
                break;

            case 'glossary':
                $defaults = [
                    'globalglossary' => 0,
                    'mainglossary' => 0,
                    'defaultapproval' => (int) ($CFG->glossary_defaultapproval ?? 1),
                    'editalways' => 0,
                    'allowduplicatedentries' => (int) ($CFG->glossary_dupentries ?? 0),
                    'allowcomments' => (int) ($CFG->glossary_allowcomments ?? 0),
                    'usedynalink' => (int) ($CFG->glossary_linkbydefault ?? 1),
                    'displayformat' => 'dictionary',
                    'approvaldisplayformat' => 'default',
                    'entbypage' => (int) (!empty($CFG->glossary_entbypage) ? $CFG->glossary_entbypage : 10),
                    'showalphabet' => 1,
                    'showall' => 1,
                    'showspecial' => 1,
                    'allowprintview' => 1,
                    'rsstype' => 0,
                    'rssarticles' => 0,
                    'assessed' => 0,
                    'scale' => 0,
                    'assesstimestart' => 0,
                    'assesstimefinish' => 0,
                    'completionentries' => 0,
                ];
                break;

            case 'feedback':
                $defaults = [
                    'anonymous' => 1, // FEEDBACK_ANONYMOUS_YES.
                    'email_notification' => 0,
                    'multiple_submit' => 0,
                    'autonumbering' => 0,
                    'site_after_submit' => '',
                    'page_after_submit' => '',
                    'page_after_submitformat' => FORMAT_HTML,
                    // No draft area: page_after_submit is stored as it is.
                    'page_after_submit_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
                    'publish_stats' => 0,
                    'timeopen' => 0,
                    'timeclose' => 0,
                    'completionsubmit' => 0,
                ];
                break;

            case 'h5pactivity':
                $core = (new \core_h5p\factory())->get_core();
                $defaults = [
                    'packagefile' => 0,
                    'grade' => 100,
                    'displayoptions' => \core_h5p\helper::get_display_options($core,
                        \core_h5p\helper::decode_display_options($core)),
                    'enabletracking' => 1,
                    'grademethod' => 1, // \mod_h5pactivity\local\manager::GRADEHIGHESTATTEMPT.
                    'reviewmode' => 1, // \mod_h5pactivity\local\manager::REVIEWCOMPLETION.
                ];
                break;

            case 'openmaic':
                $defaults = [
                    'manuscript' => '',
                    'lectureid' => '',
                    'grade' => 100,
                    'completionminprogress' => 0,
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

        if (array_key_exists('availability', $settings)) {
            $availability = self::normalise_availability($settings['availability'], 'settings.availability');
            unset($settings['availability']);
            // update_moduleinfo() prefers availabilityconditionsjson ('' = none) over availability.
            $moduleinfo->availability = $availability;
            $moduleinfo->availabilityconditionsjson = $availability ?? '';
        }
        if (array_key_exists('completion', $settings)) {
            $completion = $settings['completion'];
            if (is_bool($completion) || !is_numeric($completion)
                    || !in_array((int) $completion, [COMPLETION_TRACKING_NONE, COMPLETION_TRACKING_MANUAL,
                        COMPLETION_TRACKING_AUTOMATIC], true)) {
                throw new invalid_parameter_exception('settings.completion must be 0 (none), 1 (manual) or 2 (automatic)');
            }
            $settings['completion'] = (int) $completion;
        }

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

        if (array_key_exists('completion', $settings) && $settings['completion'] !== COMPLETION_TRACKING_AUTOMATIC) {
            // Without automatic tracking no rule may stay active (mirrors moodleform_mod::data_postprocessing()).
            foreach (self::COMPLETION_RULE_KEYS as $rule) {
                if (!array_key_exists($rule, $settings) && property_exists($moduleinfo, $rule)) {
                    $moduleinfo->$rule = 0;
                }
            }
            if ($modname === 'quiz' && !array_key_exists('completionminattempts', $settings)) {
                $moduleinfo->completionminattemptsenabled = 0;
            }
        } else if (!array_key_exists('completion', $settings)) {
            // Turning on a completion rule implies automatic completion tracking.
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
     * Validate an availability tree and return it as a JSON string.
     *
     * @param mixed $value JSON string, decoded object/array, or null/'' for "no restrictions"
     * @param string $param parameter name, used in error messages
     * @return string|null JSON, or null when there are no restrictions
     * @throws invalid_parameter_exception
     */
    public static function normalise_availability($value, string $param): ?string {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new invalid_parameter_exception("{$param} must be valid JSON");
            }
        } else {
            // Already decoded as part of the settings object: re-encode to get stdClass trees.
            $decoded = json_decode(json_encode($value));
        }
        if (!is_object($decoded)) {
            throw new invalid_parameter_exception("{$param} must be a JSON object (availability tree)");
        }
        try {
            $tree = new \core_availability\tree($decoded);
        } catch (\coding_exception $e) {
            throw new invalid_parameter_exception("{$param} is not a valid availability tree: " . $e->getMessage());
        }
        return $tree->is_empty() ? null : json_encode($decoded);
    }

    /**
     * Validate module-specific required settings before creating a module.
     *
     * @param stdClass $moduleinfo moduleinfo being built
     * @param string $modname module name
     * @param bool $creating whether the module is being created (resource files are then required)
     * @throws invalid_parameter_exception
     */
    public static function validate_module_settings(stdClass $moduleinfo, string $modname, bool $creating): void {
        global $USER;

        if ($modname === 'url') {
            $url = trim((string) ($moduleinfo->externalurl ?? ''));
            if ($url === '' || clean_param($url, PARAM_URL) === '') {
                throw new invalid_parameter_exception('settings.externalurl must be a valid URL');
            }
            $moduleinfo->externalurl = $url;
        }
        if ($modname === 'resource') {
            $itemid = $moduleinfo->files ?? 0;
            if (!is_numeric($itemid) || (int) $itemid < 0) {
                throw new invalid_parameter_exception('settings.files must be a draft item id');
            }
            $moduleinfo->files = (int) $itemid;
            if ($moduleinfo->files === 0 && $creating) {
                throw new invalid_parameter_exception('settings.files (draft item id with the uploaded file) is required');
            }
            if ($moduleinfo->files !== 0) {
                $usercontext = \context_user::instance($USER->id);
                $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $moduleinfo->files,
                    'id', false);
                if (!$files) {
                    throw new invalid_parameter_exception(
                        "settings.files: draft area {$moduleinfo->files} of the current user contains no files");
                }
            }
        }
        if ($modname === 'page' && isset($moduleinfo->content) && !is_string($moduleinfo->content)) {
            throw new invalid_parameter_exception('settings.content must be an HTML string');
        }
        switch ($modname) {
            case 'workshop':
                self::validate_workshop_settings($moduleinfo);
                break;
            case 'wiki':
                if (!in_array($moduleinfo->wikimode ?? '', ['collaborative', 'individual'], true)) {
                    throw new invalid_parameter_exception('settings.wikimode must be collaborative or individual');
                }
                if (!in_array($moduleinfo->defaultformat ?? '', ['html', 'creole', 'nwiki'], true)) {
                    throw new invalid_parameter_exception('settings.defaultformat must be html, creole or nwiki');
                }
                if ($creating) {
                    $title = trim((string) ($moduleinfo->firstpagetitle ?? ''));
                    $moduleinfo->firstpagetitle = $title === '' ? $moduleinfo->name : $title;
                }
                break;
            case 'glossary':
                $formats = get_list_of_plugins('mod/glossary/formats', 'TEMPLATE');
                if (!in_array($moduleinfo->displayformat ?? '', $formats, true)) {
                    throw new invalid_parameter_exception('settings.displayformat must be one of: ' . implode(', ', $formats));
                }
                break;
            case 'feedback':
                if (isset($moduleinfo->page_after_submit)) {
                    $moduleinfo->page_after_submit_editor = [
                        'text' => (string) $moduleinfo->page_after_submit,
                        'format' => FORMAT_HTML,
                        'itemid' => 0,
                    ];
                }
                break;
            case 'h5pactivity':
                $itemid = $moduleinfo->packagefile ?? 0;
                if (!is_numeric($itemid) || (int) $itemid < 0) {
                    throw new invalid_parameter_exception('settings.packagefile must be a draft item id');
                }
                $moduleinfo->packagefile = (int) $itemid;
                if ($moduleinfo->packagefile === 0 && $creating) {
                    throw new invalid_parameter_exception('settings.packagefile (draft item id with the .h5p file) is required');
                }
                if ($moduleinfo->packagefile !== 0) {
                    $usercontext = \context_user::instance($USER->id);
                    $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $moduleinfo->packagefile,
                        'id', false);
                    if (count($files) !== 1 || !preg_match('/\.h5p$/i', reset($files)->get_filename())) {
                        throw new invalid_parameter_exception(
                            "settings.packagefile: draft area {$moduleinfo->packagefile} must contain exactly one .h5p file");
                    }
                }
                break;
            case 'openmaic':
                $progress = $moduleinfo->completionminprogress ?? 0;
                if (!is_numeric($progress) || (int) $progress < 0 || (int) $progress > 100) {
                    throw new invalid_parameter_exception('settings.completionminprogress must be between 0 and 100');
                }
                $moduleinfo->completionminprogress = (int) $progress;
                $lectureid = trim((string) ($moduleinfo->lectureid ?? ''));
                if ($lectureid !== clean_param($lectureid, PARAM_ALPHANUMEXT) || \core_text::strlen($lectureid) > 64) {
                    throw new invalid_parameter_exception('settings.lectureid must be at most 64 letters, digits, - or _');
                }
                $moduleinfo->lectureid = $lectureid;
                if (isset($moduleinfo->manuscript) && !is_string($moduleinfo->manuscript)) {
                    throw new invalid_parameter_exception('settings.manuscript must be a string');
                }
                break;
        }
    }

    /**
     * Validate and normalise workshop settings.
     *
     * @param stdClass $moduleinfo moduleinfo being built
     * @throws invalid_parameter_exception
     */
    private static function validate_workshop_settings(stdClass $moduleinfo): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $strategies = array_keys(\core_component::get_plugin_list('workshopform'));
        if (!in_array($moduleinfo->strategy ?? '', $strategies, true)) {
            throw new invalid_parameter_exception('settings.strategy must be one of: ' . implode(', ', $strategies));
        }
        foreach (['grade', 'gradinggrade'] as $field) {
            if (!is_numeric($moduleinfo->$field ?? null) || $moduleinfo->$field < 0) {
                throw new invalid_parameter_exception("settings.{$field} must be a number >= 0");
            }
        }
        foreach (['submissiontypetext', 'submissiontypefile'] as $field) {
            if (!in_array((int) ($moduleinfo->$field ?? -1), [0, 1, 2], true)) {
                throw new invalid_parameter_exception("settings.{$field} must be 0 (disabled), 1 (available) or 2 (required)");
            }
            $moduleinfo->$field = (int) $moduleinfo->$field;
        }
        if (!$moduleinfo->submissiontypetext && !$moduleinfo->submissiontypefile) {
            throw new invalid_parameter_exception('At least one of settings.submissiontypetext / submissiontypefile must be enabled');
        }
        // Instructions are given as plain HTML fields; the editors only carry the text.
        foreach (['instructauthors', 'instructreviewers', 'conclusion'] as $field) {
            if (!is_string($moduleinfo->$field ?? '')) {
                throw new invalid_parameter_exception("settings.{$field} must be an HTML string");
            }
            $moduleinfo->{$field . 'editor'} = [
                'text' => (string) ($moduleinfo->$field ?? ''),
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
            $moduleinfo->{$field . 'format'} = FORMAT_HTML;
        }
        // workshop_add_instance() moves both grade items into these categories.
        $coursecategory = \grade_category::fetch_course_category($moduleinfo->course);
        foreach (['gradecategory', 'gradinggradecategory'] as $field) {
            if (empty($moduleinfo->$field)) {
                $moduleinfo->$field = $coursecategory->id;
            } else if (!\grade_category::fetch(['id' => (int) $moduleinfo->$field, 'courseid' => $moduleinfo->course])) {
                throw new invalid_parameter_exception("settings.{$field} is not a grade category of this course");
            }
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

            case 'workshop':
                require_once($CFG->libdir . '/gradelib.php');
                // Keep both grade items in their current categories (workshop_update_instance() moves them).
                $items = \grade_item::fetch_all(['itemtype' => 'mod', 'itemmodule' => 'workshop',
                    'iteminstance' => $data->instance, 'courseid' => $course->id]) ?: [];
                foreach ($items as $item) {
                    if ((int) $item->itemnumber === 0) {
                        $data->gradecategory = $item->categoryid;
                    } else if ((int) $item->itemnumber === 1) {
                        $data->gradinggradecategory = $item->categoryid;
                    }
                }
                break;

            case 'page':
            case 'resource':
            case 'url':
                // Display options are stored serialised; the update callbacks expect them as separate fields.
                $displayoptions = (array) unserialize_array((string) ($data->displayoptions ?? ''));
                $data->printintro = (int) !empty($displayoptions['printintro']);
                $data->popupwidth = (int) ($displayoptions['popupwidth'] ?? 620);
                $data->popupheight = (int) ($displayoptions['popupheight'] ?? 450);
                if ($data->modulename === 'page') {
                    $data->printlastmodified = (int) !empty($displayoptions['printlastmodified']);
                    // No draft item: the stored content (with its @@PLUGINFILE@@ links) is kept as is.
                    $data->page = ['text' => $data->content, 'format' => $data->contentformat, 'itemid' => 0];
                } else if ($data->modulename === 'resource') {
                    foreach (['showsize', 'showtype', 'showdate'] as $option) {
                        $data->$option = (int) !empty($displayoptions[$option]);
                    }
                    // 0 = keep the existing files; a draft item id replaces them.
                    $data->files = 0;
                } else {
                    $i = 0;
                    foreach ((array) unserialize_array((string) ($data->parameters ?? '')) as $parameter => $variable) {
                        $data->{'parameter_' . $i} = $parameter;
                        $data->{'variable_' . $i} = $variable;
                        $i++;
                    }
                }
                break;
        }
    }

    /**
     * Copy settings into the form-shaped fields that MODNAME_update_instance() reads, after apply_settings().
     *
     * @param stdClass $data update data
     * @param array $settings decoded settings
     */
    public static function postprocess_update_data(stdClass $data, array $settings): void {
        if ($data->modulename === 'page') {
            if (array_key_exists('content', $settings)) {
                $data->page['text'] = (string) $data->content;
                $data->page['format'] = FORMAT_HTML;
            }
            if (array_key_exists('contentformat', $settings)) {
                $data->page['format'] = (int) $data->contentformat;
            }
        }
    }

    /**
     * Load a course section by number, with a clear error when it does not exist.
     *
     * @param stdClass $course course record
     * @param int $sectionnum section number (not id)
     * @return \section_info
     * @throws invalid_parameter_exception
     */
    public static function get_section(stdClass $course, int $sectionnum): \section_info {
        if ($sectionnum < 0) {
            throw new invalid_parameter_exception('section must be >= 0');
        }
        $sectioninfo = get_fast_modinfo($course)->get_section_info($sectionnum, IGNORE_MISSING);
        if (!$sectioninfo) {
            throw new invalid_parameter_exception("Section {$sectionnum} does not exist in course {$course->id}");
        }
        return $sectioninfo;
    }

    /**
     * Build the course_update_section() data for a section name and/or summary.
     *
     * @param string|null $name new name (null = unchanged, '' = default name)
     * @param string|null $summary new summary HTML (null = unchanged)
     * @return array
     * @throws invalid_parameter_exception
     */
    public static function section_fields(?string $name, ?string $summary): array {
        $data = [];
        if ($name !== null) {
            $name = trim($name);
            if (\core_text::strlen($name) > 255) {
                throw new invalid_parameter_exception('Section name must be at most 255 characters');
            }
            // An empty name falls back to the course format's default section name.
            $data['name'] = $name === '' ? null : $name;
        }
        if ($summary !== null) {
            $data['summary'] = $summary;
            $data['summaryformat'] = FORMAT_HTML;
        }
        return $data;
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
        $category->info = 'Questions created by the UbionAI web service.';
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
