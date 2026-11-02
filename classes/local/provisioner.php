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
 * Only tokens minted here are tracked and handed to the hub. A token issued elsewhere (for example
 * the setup script's 'ubionai-mcp' token, whose string also lives in a token file) is never tracked
 * or sent to the hub; it is left in place unless the caller opts in to revoking it.
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
        'moodle/course:view',
        'mod/assign:view',
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
        // Local plugin functions (11).
        'local_ubionai_create_module', 'local_ubionai_update_module', 'local_ubionai_add_quiz_questions',
        'local_ubionai_update_section', 'local_ubionai_add_sections', 'local_ubionai_set_section_availability',
        'local_ubionai_set_rubric', 'local_ubionai_set_grade_categories', 'local_ubionai_create_badge',
        'local_ubionai_get_course_assignments', 'local_ubionai_list_course_assignments',
    ];

    /**
     * Ensure WS + REST enabled, service user, role, external service, functions,
     * authorisation and exactly one valid token.
     *
     * @param string $iprestriction optional IP restriction applied to authorisation and token
     * @param bool $revokelegacy delete untracked 'ubionai-mcp' tokens of the service user (opt-in)
     * @return array status array that never contains the token string
     */
    public static function provision(string $iprestriction = '', bool $revokelegacy = false): array {
        global $CFG;

        $iprestriction = ip_validator::normalise($iprestriction);

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
        $tokenaction = self::ensure_single_token((int) $service->id, (int) $user->id, $iprestriction);
        $legacyrevoked = $revokelegacy ? self::revoke_legacy_tokens((int) $service->id, (int) $user->id) : 0;

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
            'token_created' => $tokenaction !== 'reused',
            'token_action' => $tokenaction,
            'token_count' => count(self::token_rows()),
            'legacy_revoked' => $legacyrevoked,
            'legacy_untracked' => count(self::legacy_token_ids((int) $service->id, (int) $user->id)),
        ];
    }

    /**
     * Delete the tokens this plugin issued for the service user.
     *
     * The user, role and service are kept so that reconnecting is cheap and idempotent.
     * Tokens of the service user that this plugin did not issue are left in place.
     *
     * @return array{deleted: int, untracked: int} deleted = tracked rows removed,
     *         untracked = other token rows of the service user (any service) left in place
     */
    public static function disconnect(): array {
        global $DB;

        $service = self::service();
        $user = self::service_user();
        if (!$user) {
            self::set_tracked_token_ids([]);
            return ['deleted' => 0, 'untracked' => 0];
        }

        $userid = (int) $user->id;
        $total = (int) $DB->count_records('external_tokens', ['userid' => $userid]);
        $tracked = self::tracked_token_ids();
        if (!$service || !$tracked) {
            self::set_tracked_token_ids([]);
            return ['deleted' => 0, 'untracked' => $total];
        }

        [$insql, $params] = $DB->get_in_or_equal($tracked, SQL_PARAMS_NAMED, 'tokenid');
        $params['serviceid'] = (int) $service->id;
        $params['userid'] = $userid;
        $select = "externalserviceid = :serviceid AND userid = :userid AND id {$insql}";

        $deleted = (int) $DB->count_records_select('external_tokens', $select, $params);
        if ($deleted > 0) {
            $DB->delete_records_select('external_tokens', $select, $params);
        }
        self::set_tracked_token_ids([]);

        return ['deleted' => $deleted, 'untracked' => max(0, $total - $deleted)];
    }

    /**
     * Run a callback with the provisioned web service token string.
     *
     * The token string exists only inside the closure call; it is never returned, logged or
     * stored by this method. Precondition: provision() has run.
     *
     * @param callable(string):mixed $fn callback receiving the token string
     * @return mixed the closure's return value
     * @throws \coding_exception when no tracked token row exists
     */
    public static function with_token(callable $fn): mixed {
        global $DB;

        $service = self::service();
        $user = self::service_user();
        if (!$service || !$user) {
            throw new \coding_exception('No external token is provisioned.');
        }

        $ids = self::tracked_token_ids();
        if (!$ids) {
            throw new \coding_exception('No external token is provisioned.');
        }

        [$validsql, $params] = self::valid_token_select((int) $service->id, (int) $user->id);
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'tk');
        $row = $DB->get_record_sql(
            "SELECT id, token FROM {external_tokens} WHERE id {$insql} AND {$validsql}"
                . ' ORDER BY timecreated DESC, id DESC',
            $inparams + $params,
            IGNORE_MULTIPLE
        );
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

        $trackedids = self::tracked_token_ids();
        $trackedcount = count($trackedids);
        $untrackedcount = 0;
        if ($user) {
            $usertokens = (int) $DB->count_records('external_tokens', ['userid' => (int) $user->id]);
            $ourtracked = 0;
            if ($service && $trackedids) {
                [$insql, $params] = $DB->get_in_or_equal($trackedids, SQL_PARAMS_NAMED, 'tokenid');
                $params['serviceid'] = (int) $service->id;
                $params['userid'] = (int) $user->id;
                $ourtracked = (int) $DB->count_records_select('external_tokens',
                    "externalserviceid = :serviceid AND userid = :userid AND id {$insql}", $params);
            }
            $untrackedcount = max(0, $usertokens - $ourtracked);
        }

        $tokenvalid = false;
        if ($service && $user && $trackedids) {
            [$validsql, $params] = self::valid_token_select((int) $service->id, (int) $user->id);
            [$insql, $inparams] = $DB->get_in_or_equal($trackedids, SQL_PARAMS_NAMED, 'tk');
            $tokenvalid = $DB->record_exists_select('external_tokens',
                "id {$insql} AND {$validsql}", $inparams + $params);
        }

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
            'tokens_tracked' => $trackedcount,
            'tokens_untracked' => $untrackedcount,
            'token_valid' => $tokenvalid,
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
        // \core\user::create_user() only exists from Moodle 5.3, where user_create_user() became
        // deprecated. Use whichever API the running core provides.
        if (method_exists(\core\user::class, 'create_user')) {
            $userid = \core\user::create_user($record, true, false);
        } else {
            require_once($CFG->dirroot . '/user/lib.php');
            $userid = user_create_user($record, true, false);
        }

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
        }
    }

    /**
     * SQL fragment and params selecting valid (PERMANENT, unexpired) tokens of ours.
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @return array{string, array} [select fragment, named params]
     */
    private static function valid_token_select(int $serviceid, int $userid): array {
        return [
            'externalserviceid = :vts_serviceid AND userid = :vts_userid '
                . 'AND tokentype = :vts_tokentype AND (validuntil = 0 OR validuntil > :vts_now)',
            [
                'vts_serviceid' => $serviceid,
                'vts_userid' => $userid,
                'vts_tokentype' => EXTERNAL_TOKEN_PERMANENT,
                'vts_now' => time(),
            ],
        ];
    }

    /**
     * Ensure exactly one usable token is tracked for the service user.
     *
     * Order: reuse a valid tracked token; delete tracked-but-unusable rows; otherwise mint a new
     * one. An untracked valid 'ubionai-mcp' token (for example from the setup script) is never
     * adopted: its string already exists outside Moodle, so a fresh token is minted for the hub
     * ('rotated') and the legacy row is left untracked.
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @param string $iprestriction optional IP restriction
     * @return string 'reused'|'rotated'|'created'
     */
    private static function ensure_single_token(int $serviceid, int $userid, string $iprestriction): string {
        global $DB;

        [$validsql, $validparams] = self::valid_token_select($serviceid, $userid);
        $tracked = self::tracked_token_ids();

        // Steps 1-3: newest valid tracked row wins; every other tracked row is ours and is deleted.
        $kept = null;
        if ($tracked) {
            [$insql, $params] = $DB->get_in_or_equal($tracked, SQL_PARAMS_NAMED, 'tk');
            $rows = $DB->get_records_select('external_tokens',
                "id {$insql} AND {$validsql}", $params + $validparams,
                'timecreated DESC, id DESC', 'id');
            $kept = $rows ? reset($rows) : null;
            foreach ($tracked as $id) {
                if ($kept === null || (int) $kept->id !== (int) $id) {
                    $DB->delete_records('external_tokens', ['id' => $id]);
                }
            }
            self::set_tracked_token_ids($kept ? [(int) $kept->id] : []);
        }

        // Step 4: reuse.
        if ($kept !== null) {
            if ($iprestriction !== '') {
                $DB->set_field('external_tokens', 'iprestriction', $iprestriction, ['id' => $kept->id]);
            }
            return 'reused';
        }

        // Step 5: a valid untracked 'ubionai-mcp' token means rotation, not adoption.
        $legacyparams = $validparams;
        $legacyparams['legacyname'] = self::TOKEN_NAME;
        $rotated = $DB->record_exists_select('external_tokens', "{$validsql} AND name = :legacyname", $legacyparams);

        // Step 6: mint.
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            \core_external\util::get_service_by_id($serviceid),
            $userid,
            \context_system::instance(),
            0,
            $iprestriction,
            self::TOKEN_NAME
        );
        $newid = (int) $DB->get_field('external_tokens', 'id', ['token' => $token]);
        unset($token);
        if ($newid <= 0) {
            throw new \coding_exception('Unable to track the new external token.');
        }
        self::set_tracked_token_ids([$newid]);

        return $rotated ? 'rotated' : 'created';
    }

    /**
     * Ids of untracked 'ubionai-mcp' tokens of the service user on our service (any validity).
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @return int[]
     */
    private static function legacy_token_ids(int $serviceid, int $userid): array {
        global $DB;

        $select = 'externalserviceid = :serviceid AND userid = :userid AND name = :name';
        $params = ['serviceid' => $serviceid, 'userid' => $userid, 'name' => self::TOKEN_NAME];
        $tracked = self::tracked_token_ids();
        if ($tracked) {
            [$insql, $inparams] = $DB->get_in_or_equal($tracked, SQL_PARAMS_NAMED, 'tk', false);
            $select .= " AND id {$insql}";
            $params += $inparams;
        }

        $rows = $DB->get_records_select('external_tokens', $select, $params, 'id', 'id');

        return array_map('intval', array_keys($rows));
    }

    /**
     * Delete untracked 'ubionai-mcp' tokens of the service user on our service.
     *
     * Opt-in only: such a token may still be used by another client (for example a single-tenant
     * MCP server configured by the setup script).
     *
     * @param int $serviceid external service id
     * @param int $userid service user id
     * @return int number of deleted rows
     */
    private static function revoke_legacy_tokens(int $serviceid, int $userid): int {
        global $DB;

        $ids = self::legacy_token_ids($serviceid, $userid);
        if ($ids) {
            $DB->delete_records_list('external_tokens', 'id', $ids);
        }

        return count($ids);
    }

    /**
     * Get the tracked token row ids, dropping entries that no longer exist or point elsewhere.
     *
     * @return int[]
     */
    private static function tracked_token_ids(): array {
        global $DB;

        $raw = (string) get_config('local_ubionai', 'issued_token_ids');
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $id = (int) $part;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $service = self::service();
        $user = self::service_user();
        $rows = $ids ? $DB->get_records_list('external_tokens', 'id', $ids, '',
            'id, externalserviceid, userid') : [];

        $valid = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue; // Gone: untrack.
            }
            if ($service && $user
                    && ((int) $rows[$id]->externalserviceid !== (int) $service->id
                        || (int) $rows[$id]->userid !== (int) $user->id)) {
                continue; // Points elsewhere: untrack, do NOT delete.
            }
            $valid[] = $id;
        }
        if ($valid !== $ids) {
            self::set_tracked_token_ids($valid);
        }

        return $valid;
    }

    /**
     * Replace the tracked token row ids in plugin config.
     *
     * @param int[] $ids token row ids
     */
    private static function set_tracked_token_ids(array $ids): void {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        set_config('issued_token_ids', implode(',', $clean), 'local_ubionai');
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
