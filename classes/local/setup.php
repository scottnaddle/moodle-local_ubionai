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

/**
 * Zero-config defaults created on install/upgrade (Phase 0 spike).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup {
    /** Shortname of the minimal role given to connected teachers. */
    public const ROLE_SHORTNAME = 'ubionai_wsuser';

    /**
     * Create the minimal role, the site key pair and the initial state. Idempotent.
     *
     * @param string $where caller label recorded for the spike
     * @return void
     */
    public static function ensure_defaults(string $where): void {
        global $DB, $USER;

        $roleid = $DB->get_field('role', 'id', ['shortname' => self::ROLE_SHORTNAME]);
        if (!$roleid) {
            $roleid = create_role(
                'UBION AI web service user',
                self::ROLE_SHORTNAME,
                'Minimal role for teachers connected to Claude: REST web service use only.',
                ''
            );
        }
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        // Core capability: already registered, so this is allowed during install (plugin caps are not).
        assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        if (get_config('local_ubionai', 'site_public_key') === false) {
            $kp = sodium_crypto_sign_keypair();
            set_config('site_public_key', base64_encode(sodium_crypto_sign_publickey($kp)), 'local_ubionai');
            set_config('site_secret_key', base64_encode(sodium_crypto_sign_secretkey($kp)), 'local_ubionai');
        }
        if (get_config('local_ubionai', 'connect_state') === false) {
            set_config('connect_state', 'awaiting_consent', 'local_ubionai');
        }
        // Self-registration (WP1). oauth.php switches to shared_secret by itself once registered (oauth::mode()).
        if (get_config('local_ubionai', 'reg_state') === false) {
            set_config('reg_state', \local_ubionai\registration\state::AWAITING_CONSENT, 'local_ubionai');
        }
        if (get_config('local_ubionai', 'auth_mode') === false) {
            set_config('auth_mode', 'legacy_file', 'local_ubionai');
        }
        if (get_config('local_ubionai', 'self_registration') === false) {
            // Sites already set up by the hub operator (0.6.x) keep the manual flow.
            $legacy = trim((string) get_config('local_ubionai', 'hub_site_id')) !== '';
            set_config('self_registration', $legacy ? 0 : 1, 'local_ubionai');
        }
        // Spike evidence: who/where ran the hook.
        set_config('spike_install_trace', json_encode([
            'where' => $where, 'time' => time(), 'cli' => CLI_SCRIPT,
            'userid' => isset($USER->id) ? (int) $USER->id : null,
            'pluginfuncs_registered' => $DB->count_records('external_functions', ['component' => 'local_ubionai']),
            'service_exists' => $DB->record_exists('external_services', ['shortname' => 'ubionai_user']),
        ]), 'local_ubionai');
    }

    /**
     * Updated callback of the consent setting: runs on save in admin/upgradesettings.php or settings.php.
     *
     * @param string $fullname setting full name
     * @return void
     */
    public static function consent_updated(string $fullname): void {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/webservice/lib.php');
        $value = get_config('local_ubionai', 'consent');
        set_config('spike_consent_trace', json_encode([
            'value' => $value, 'time' => time(), 'userid' => (int) $USER->id,
            'siteadmin' => is_siteadmin(), 'cli' => CLI_SCRIPT,
        ]), 'local_ubionai');
        if ($value !== 'accepted') {
            set_config('connect_state', $value === 'declined' ? 'declined' : 'awaiting_consent', 'local_ubionai');
            \local_ubionai\registration\registrar::consent_updated($value);
            return;
        }
        set_config('enablewebservices', 1);
        $protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
        if (!in_array('rest', $protocols)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }
        set_config('connect_state', 'consented_registration_pending', 'local_ubionai');
        // One synchronous self-registration attempt; failures are retried by an adhoc task.
        \local_ubionai\registration\registrar::consent_updated($value);
    }
}
