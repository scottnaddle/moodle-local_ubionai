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

use stdClass;

/**
 * Idempotent provisioning of the UBION AI web service connection.
 *
 * This is a port of the setup script's steps 1-7. The web service token string is created and
 * used only inside this class. It is never returned to callers, written to plugin config, logged
 * or echoed; only token metadata (count and timestamps) is observable.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioner {
    /** @var string Service user username. */
    public const USERNAME = 'svc_ubionai';

    /** @var string Service user email. */
    public const EMAIL = 'svc_ubionai@example.invalid';

    /** @var string External service shortname. */
    public const SERVICE = 'ubionai';

    /** @var string Service role shortname. */
    public const ROLE = 'ubionai_service';

    /** @var string Token name. */
    public const TOKEN_NAME = 'ubionai-mcp';

    /** @var string Service user first name. */
    public const SERVICE_USER_FIRSTNAME = 'UbionAI';

    /** @var string Service user last name. */
    public const SERVICE_USER_LASTNAME = 'Bot (service)';

    /** @var string Role name. */
    public const ROLE_NAME = 'UbionAI service';

    /** @var string Role description. */
    public const ROLE_DESCRIPTION = 'Web service role for the UbionAI MCP server';

    /** @var string[] Extra capabilities granted to the service role at system context. */
    private const CAPABILITIES = [
        'webservice/rest:use',
        'moodle/webservice:createtoken',
        'moodle/course:create',
        'moodle/role:assign',
        'enrol/manual:enrol',
        'moodle/course:manageactivities',
        'moodle/question:add',
        'moodle/grade:managegradingforms',
        'moodle/grade:manage',
        'moodle/badges:createbadge',
        'moodle/badges:configurecriteria',
        'moodle/badges:awardbadge',
    ];

    /** @var string[] Web service functions exposed to the MCP server. */
    private const FUNCTIONS = [
        'core_webservice_get_site_info', 'core_course_get_courses', 'core_course_get_contents',
        'core_course_get_categories', 'core_course_create_courses', 'core_course_update_courses',
        'core_course_edit_module', 'core_course_edit_section', 'core_courseformat_create_module',
        'core_courseformat_update_course', 'core_enrol_get_enrolled_users', 'core_user_get_users_by_field',
        'core_files_upload', 'core_grades_update_grades', 'core_calendar_get_calendar_events',
        'enrol_manual_enrol_users', 'gradereport_user_get_grade_items', 'gradereport_grader_get_users_in_report',
        'mod_assign_get_assignments', 'mod_assign_get_submissions', 'mod_assign_get_grades',
        'mod_assign_save_grade', 'mod_assign_save_grades', 'mod_quiz_get_quizzes_by_courses',
        'mod_quiz_get_user_attempts', 'mod_quiz_get_attempt_review', 'mod_quiz_add_random_questions',
        'mod_forum_add_discussion', 'mod_forum_get_forum_discussions', 'mod_forum_get_discussion_posts',
        // Optional: mod_attendance. Skipped (and reported by name) when the plugin is absent.
        'mod_attendance_get_sessions', 'mod_attendance_get_session', 'mod_attendance_add_attendance',
        'mod_attendance_add_session', 'mod_attendance_update_user_status',
        // Local plugin functions (9).
        'local_ubionai_create_module', 'local_ubionai_update_module', 'local_ubionai_add_quiz_questions',
        'local_ubionai_update_section', 'local_ubionai_add_sections', 'local_ubionai_set_section_availability',
        'local_ubionai_set_rubric', 'local_ubionai_set_grade_categories', 'local_ubionai_create_badge',
    ];

    /**
     * Ensure WS + REST enabled, service user, role, external service, functions,
     * authorisation and exactly one valid token.
     *
     * @param string $iprestriction optional IP restriction applied to authorisation and token
     * @return array status array that never contains the token string
     */
    public static function provision(string $iprestriction = ''): array {
        global $CFG;

        set_config('enablewebservices', 1);
        $protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }

        $user = self::ensure_user();
        $roleid = self::ensure_role();
        $service = self::ensure_service();
        [$total, $added, $skipped] = self::ensure_functions((int) $service->id);
        self::ensure_authorisation((int) $service->id, (int) $user->id, $iprestriction);
        $tokencreated = self::ensure_single_token((int) $service->id, (int) $user->id, $iprestriction);

        role_assign($roleid, (int) $user->id, \context_system::instance()->id);
        accesslib_clear_role_cache($roleid);

        set_config('connected_at', time(), 'local_ubionai');

        return [
            'userid' => (int) $user->id,
            'roleid' => (int) $roleid,
            'serviceid' => (int) $service->id,
            'functions_total' => (int) $total,
            'functions_added' => (int) $added,
            'skipped' => $skipped,
            'token_created' => (bool) $tokencreated,
            'token_count' => count(self::token_rows()),
        ];
    }

    /**
     * Delete all external tokens for the service and service user.
     *
     * The user, role and service are kept so that reconnecting is cheap and idempotent.
     *
     * @return int number of token rows deleted
     */
    public static function disconnect(): int {
        global $DB;

        $service = self::service();
        $user = self::service_user();
        if (!$service || !$user) {
            return 0;
        }

        $conditions = ['externalserviceid' => (int) $service->id, 'userid' => (int) $user->id];
        $count = $DB->count_records('external_tokens', $conditions);
        $DB->delete_records('external_tokens', $conditions);

        return (int) $count;
    }

    /**
     * Run a callback with the provisioned web service token string.
     *
     * The token string exists only inside the closure call; it is never returned, logged or
     * stored by this method. Precondition: provision() has run.
     *
     * @param callable(string):mixed $fn callback receiving the token string
     * @return mixed the closure's return value
     * @throws \coding_exception when no token row exists
     */
    public static function with_token(callable $fn): mixed {
        global $DB;

        $service = self::service();
        $user = self::service_user();
        if (!$service || !$user) {
            throw new \coding_exception('No external token is provisioned.');
        }

        $rows = $DB->get_records('external_tokens', [
            'externalserviceid' => (int) $service->id,
            'userid' => (int) $user->id,
        ], 'id DESC', 'id, token', 0, 1);
        $row = reset($rows);
        if (!$row) {
            throw new \coding_exception('No external token is provisioned.');
        }

        $result = $fn((string) $row->token);
        unset($row);

        return $result;
    }

    /**
     * Read-only connection status. Never contains the token string.
     *
     * @return array
     */
    public static function status(): array {
        global $DB;

        $service = self::service();
        $user = self::service_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => self::ROLE]);
        $rows = self::token_rows();

        $timecreated = null;
        $lastaccess = null;
        foreach ($rows as $row) {
            $timecreated = $timecreated === null ? (int) $row->timecreated : max($timecreated, (int) $row->timecreated);
            if (!empty($row->lastaccess)) {
                $lastaccess = $lastaccess === null ? (int) $row->lastaccess : max($lastaccess, (int) $row->lastaccess);
            }
        }

        $connectedat = get_config('local_ubionai', 'connected_at');
        $registeredat = get_config('local_ubionai', 'hub_registered_at');
        $hubregistered = hub_client::is_registered();

        return [
            'webservices_enabled' => !empty(get_config('core', 'enablewebservices')),
            'rest_enabled' => in_array('rest', array_filter(explode(',',
                (string) get_config('core', 'webserviceprotocols'))), true),
            'userid' => $user ? (int) $user->id : null,
            'roleid' => $roleid ? (int) $roleid : null,
            'serviceid' => $service ? (int) $service->id : null,
            'functions_total' => $service
                ? (int) $DB->count_records('external_services_functions', ['externalserviceid' => $service->id])
                : 0,
            'user_authorised' => ($service && $user)
                ? $DB->record_exists('external_services_users',
                    ['externalserviceid' => $service->id, 'userid' => $user->id])
                : false,
            'token_count' => count($rows),
            'token_timecreated' => $timecreated,
            'token_lastaccess' => $lastaccess,
            'connected_at' => $connectedat === false ? null : (int) $connectedat,
            'hub_configured' => hub_client::is_configured(),
            'hub_registered' => $hubregistered,
            'hub_siteid' => $hubregistered ? (string) get_config('local_ubionai', 'hub_siteid') : null,
            'hub_registered_at' => ($hubregistered && $registeredat !== false) ? (int) $registeredat : null,
            'hub_mcp_endpoint' => $hubregistered
                ? (string) get_config('local_ubionai', 'hub_mcp_endpoint') : null,
            'hub_egress_ip' => $hubregistered
                ? (string) get_config('local_ubionai', 'hub_egress_ip') : null,
        ];
    }

    /**
     * Ensure the dedicated manual-auth service user exists.
     *
     * @return stdClass user record
     */
    private static function ensure_user(): stdClass {
        global $CFG, $DB;

        $user = $DB->get_record('user', [
            'username' => self::USERNAME,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);
        if ($user) {
            return $user;
        }

        $record = (object) [
            'username' => self::USERNAME,
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'firstname' => self::SERVICE_USER_FIRSTNAME,
            'lastname' => self::SERVICE_USER_LASTNAME,
            'email' => self::EMAIL,
            'password' => random_string(24) . 'Aa1!',
            'policyagreed' => 1,
        ];
        $userid = user_create_user($record, true, false);

        return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    }

    /**
     * Ensure the service role exists with the required capabilities.
     *
     * @return int role id
     */
    private static function ensure_role(): int {
        global $DB;

        $systemcontext = \context_system::instance();
        $roleid = $DB->get_field('role', 'id', ['shortname' => self::ROLE]);
        if (!$roleid) {
            $roleid = create_role(self::ROLE_NAME, self::ROLE, self::ROLE_DESCRIPTION, 'manager');
            foreach (get_default_capabilities('manager') as $capability => $permission) {
                assign_capability($capability, $permission, $roleid, $systemcontext->id, true);
            }
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        foreach (self::CAPABILITIES as $capability) {
            if (get_capability_info($capability)) {
                assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
            }
        }

        foreach (['editingteacher', 'teacher', 'student'] as $target) {
            $targetid = $DB->get_field('role', 'id', ['shortname' => $target]);
            if ($targetid && !$DB->record_exists('role_allow_assign',
                    ['roleid' => $roleid, 'allowassign' => $targetid])) {
                core_role_set_assign_allowed($roleid, $targetid);
            }
        }

        return (int) $roleid;
    }

    /**
     * Ensure the restricted external service exists and is enabled.
     *
     * @return stdClass external service record
     */
    private static function ensure_service(): stdClass {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE]);
        if (!$service) {
            $service = (object) [
                'name' => self::SERVICE,
                'shortname' => self::SERVICE,
                'enabled' => 1,
                'restrictedusers' => 1,
                'downloadfiles' => 1,
                'uploadfiles' => 1,
                'requiredcapability' => '',
                'component' => null,
                'timecreated' => time(),
            ];
            $service->id = $DB->insert_record('external_services', $service);

            return $service;
        }

        $DB->update_record('external_services', (object) [
            'id' => $service->id,
            'enabled' => 1,
            'restrictedusers' => 1,
            'downloadfiles' => 1,
            'uploadfiles' => 1,
        ]);
        $service->enabled = 1;
        $service->restrictedusers = 1;
        $service->downloadfiles = 1;
        $service->uploadfiles = 1;

        return $service;
    }

    /**
     * Ensure every available function is attached to the service.
     *
     * @param int $serviceid external service id
     * @return array [total, added, skipped]
     */
    private static function ensure_functions(int $serviceid): array {
        global $DB;

        $added = 0;
        $skipped = [];
        foreach (self::FUNCTIONS as $function) {
            if (!$DB->record_exists('external_functions', ['name' => $function])) {
                $skipped[] = $function;
                continue;
            }
            if (!$DB->record_exists('external_services_functions',
                    ['externalserviceid' => $serviceid, 'functionname' => $function])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $serviceid,
                    'functionname' => $function,
                ]);
                $added++;
            }
        }

        $total = $DB->count_records('external_services_functions', ['externalserviceid' => $serviceid]);

        return [(int) $total, (int) $added, $skipped];
    }

    /**
     * Ensure the service user is authorised for the service.
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @param string $iprestriction optional IP restriction
     */
    private static function ensure_authorisation(int $serviceid, int $userid, string $iprestriction): void {
        global $DB;

        $conditions = ['externalserviceid' => $serviceid, 'userid' => $userid];
        if (!$DB->record_exists('external_services_users', $conditions)) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $serviceid,
                'userid' => $userid,
                'iprestriction' => $iprestriction !== '' ? $iprestriction : null,
                'validuntil' => 0,
                'timecreated' => time(),
            ]);
            return;
        }

        if ($iprestriction !== '') {
            $DB->set_field('external_services_users', 'iprestriction', $iprestriction, $conditions);
            $DB->set_field('external_tokens', 'iprestriction', $iprestriction, $conditions);
        }
    }

    /**
     * Ensure exactly one token exists for the service user, reusing it when present.
     *
     * The token string is used locally only and never leaves this method.
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @param string $iprestriction optional IP restriction
     * @return bool whether a new token was created
     */
    private static function ensure_single_token(int $serviceid, int $userid, string $iprestriction): bool {
        global $DB;

        $rows = self::token_rows();
        if ($rows) {
            $rows = array_values($rows);
            $keep = array_shift($rows);
            foreach ($rows as $row) {
                $DB->delete_records('external_tokens', ['id' => $row->id]);
            }
            if ($iprestriction !== '') {
                $DB->set_field('external_tokens', 'iprestriction', $iprestriction, ['id' => $keep->id]);
            }
            return false;
        }

        \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            \core_external\util::get_service_by_id($serviceid),
            $userid,
            \context_system::instance(),
            0,
            $iprestriction,
            self::TOKEN_NAME
        );

        return true;
    }

    /**
     * Get the service user record, if it exists.
     *
     * @return stdClass|null
     */
    private static function service_user(): ?stdClass {
        global $CFG, $DB;

        $user = $DB->get_record('user', [
            'username' => self::USERNAME,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);

        return $user ?: null;
    }

    /**
     * Get the external service record, if it exists.
     *
     * @return stdClass|null
     */
    private static function service(): ?stdClass {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE]);

        return $service ?: null;
    }

    /**
     * Get token rows for the service user without selecting the token string.
     *
     * @return array token metadata records
     */
    private static function token_rows(): array {
        global $DB;

        $service = self::service();
        $user = self::service_user();
        if (!$service || !$user) {
            return [];
        }

        return $DB->get_records('external_tokens', [
            'externalserviceid' => (int) $service->id,
            'userid' => (int) $user->id,
        ], 'id DESC', 'id, externalserviceid, userid, tokentype, timecreated, lastaccess, iprestriction, name, validuntil');
    }
}
