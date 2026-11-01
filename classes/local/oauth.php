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

use moodle_exception;

/**
 * Per-user delegated login for the hub's OAuth server (Easy Connect spike, hub-staging trial).
 *
 * The hub redirects a teacher's browser to oauth/authorize.php with a request signed by the
 * tenant shared secret. After Moodle login and consent the plugin redirects back to the hub with a
 * one-time code; the hub redeems the code over the back channel (oauth/exchange.php, also signed)
 * for a web service token of that user, limited to the ubionai_user service. Site administrators
 * and tokens of any other service (moodle_mobile_app etc.) are refused in code.
 *
 * Signed messages: base64url(json) . "." . base64url(HMAC-SHA256(secret, "ubionai-oauth-v1." . body)).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth {
    /** The only service this class mints or revokes tokens for. */
    public const SERVICE = 'ubionai_user';
    /** Name stored on minted tokens. */
    public const TOKEN_NAME = 'ubionai-user';
    /** One-time code lifetime (seconds). */
    public const CODE_TTL = 120;
    /** Token lifetime (seconds); matches the hub refresh token lifetime. */
    public const TOKEN_TTL = 30 * DAYSECS;
    /** Minimum shared secret length. */
    public const MIN_SECRET = 32;
    /** MAC domain separator. */
    private const MAC_PREFIX = 'ubionai-oauth-v1.';

    /**
     * Shared secret configured for this site, or null when OAuth is not configured.
     *
     * @return string|null
     */
    public static function secret(): ?string {
        $secret = trim((string) get_config('local_ubionai', 'oauth_secret'));
        return strlen($secret) >= self::MIN_SECRET ? $secret : null;
    }

    /**
     * True when the hub URL, site id and shared secret are all configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::secret() !== null && self::callback_url() !== null;
    }

    /**
     * The only hub callback URL this site redirects to: {hub_url}/t/{hub_site_id}/oauth/callback.
     *
     * @return string|null
     */
    public static function callback_url(): ?string {
        $hub = rtrim(trim((string) get_config('local_ubionai', 'hub_url')), '/');
        $site = trim((string) get_config('local_ubionai', 'hub_site_id'));
        if (!preg_match('#^https?://[^/?\#@\s]+(/[^?\#\s]*)?$#i', $hub) || !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $site)) {
            return null;
        }
        return $hub . '/t/' . $site . '/oauth/callback';
    }

    /**
     * Sign claims with the shared secret.
     *
     * @param array $claims claims
     * @param string|null $secret secret (defaults to the configured one)
     * @return string
     */
    public static function sign(array $claims, ?string $secret = null): string {
        $secret = $secret ?? self::secret();
        if ($secret === null) {
            throw new moodle_exception('oauth_notconfigured', 'local_ubionai');
        }
        $body = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(hash_hmac('sha256', self::MAC_PREFIX . $body, $secret, true));
    }

    /**
     * Verify a signed message from the hub: MAC, type, audience (this site), site id and expiry.
     *
     * @param string $token signed message
     * @param string $typ expected message type
     * @return array claims
     * @throws moodle_exception when invalid
     */
    public static function verify(string $token, string $typ): array {
        global $CFG;
        $secret = self::secret();
        if ($secret === null || !self::is_configured()) {
            throw new moodle_exception('oauth_notconfigured', 'local_ubionai');
        }
        if (strlen($token) > 8192 || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m)) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        $expected = hash_hmac('sha256', self::MAC_PREFIX . $m[1], $secret, true);
        if (!hash_equals($expected, self::unb64($m[2]))) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        $claims = json_decode(self::unb64($m[1]), true);
        if (
            !is_array($claims)
                || ($claims['typ'] ?? null) !== $typ
                || ($claims['aud'] ?? null) !== rtrim($CFG->wwwroot, '/')
                || ($claims['site'] ?? null) !== trim((string) get_config('local_ubionai', 'hub_site_id'))
                || !is_int($claims['exp'] ?? null) || $claims['exp'] < time()
        ) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        return $claims;
    }

    /**
     * Verify the authorization request the hub sent through the browser.
     *
     * @param string $req signed request
     * @return array claims (state, client_name, redirect_host, scope, callback)
     */
    public static function verify_authorize_request(string $req): array {
        $claims = self::verify($req, 'authz_req');
        if (
            ($claims['callback'] ?? null) !== self::callback_url()
                || !is_string($claims['state'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $claims['state'])
        ) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        return $claims;
    }

    /**
     * Throw unless the user may connect: real, active, not a site administrator, and holding
     * local/ubionai:connect in at least one course (editing and non-editing teachers by default).
     *
     * @param int $userid user id
     * @return void
     */
    public static function require_can_connect(int $userid): void {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || $user->deleted || $user->suspended || isguestuser($user) || $user->auth === 'nologin') {
            throw new moodle_exception('oauth_notallowed', 'local_ubionai');
        }
        if (is_siteadmin($userid)) {
            throw new moodle_exception('oauth_siteadmin', 'local_ubionai');
        }
        if (!get_user_capability_course('local/ubionai:connect', $userid, false, '', '', 1)) {
            throw new moodle_exception('oauth_notteacher', 'local_ubionai');
        }
    }

    /**
     * Issue a one-time code for the user after consent.
     *
     * @param int $userid user id
     * @param array $claims verified authorization request
     * @return string the code (only its hash is stored)
     */
    public static function issue_code(int $userid, array $claims): string {
        global $DB;
        self::require_can_connect($userid);
        $DB->delete_records_select('local_ubionai_codes', 'timeexpires < ?', [time()]);
        $code = self::b64(random_bytes(32));
        $DB->insert_record('local_ubionai_codes', (object) [
            'codehash' => hash('sha256', $code),
            'userid' => $userid,
            'state' => $claims['state'],
            'timecreated' => time(),
            'timeexpires' => time() + self::CODE_TTL,
        ]);
        return $code;
    }

    /**
     * Back channel: redeem a one-time code for a token of that user (ubionai_user service only).
     *
     * @param string $assertion signed exchange request from the hub
     * @return array {userid, tokenid, wstoken, validuntil}
     */
    public static function exchange(string $assertion): array {
        global $DB;
        $claims = self::verify($assertion, 'exchange');
        if (!is_string($claims['code'] ?? null) || !is_string($claims['state'] ?? null)) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        $transaction = $DB->start_delegated_transaction();
        $record = $DB->get_record('local_ubionai_codes', ['codehash' => hash('sha256', $claims['code'])]);
        if ($record) {
            $DB->delete_records('local_ubionai_codes', ['id' => $record->id]);
        }
        $transaction->allow_commit();
        if (!$record || $record->timeexpires < time() || !hash_equals($record->state, $claims['state'])) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        $userid = (int) $record->userid;
        self::require_can_connect($userid);
        [$token, $tokenid, $validuntil] = self::mint_token($userid);
        return ['userid' => $userid, 'tokenid' => $tokenid, 'wstoken' => $token, 'validuntil' => $validuntil];
    }

    /**
     * Mint a token of the per-user service for the user: assigns the minimal role and authorises
     * the user on the restricted service first. Refuses site administrators and other services.
     *
     * @param int $userid user id
     * @param string $serviceshortname must be self::SERVICE
     * @return array [token, tokenid, validuntil]
     */
    public static function mint_token(int $userid, string $serviceshortname = self::SERVICE): array {
        global $DB;
        if ($serviceshortname !== self::SERVICE) {
            throw new moodle_exception('oauth_wrongservice', 'local_ubionai');
        }
        if (is_siteadmin($userid)) {
            throw new moodle_exception('oauth_siteadmin', 'local_ubionai');
        }
        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE, 'enabled' => 1]);
        if (!$service) {
            throw new moodle_exception('oauth_noservice', 'local_ubionai');
        }
        $syscontext = \context_system::instance();
        $roleid = $DB->get_field('role', 'id', ['shortname' => setup::ROLE_SHORTNAME], MUST_EXIST);
        role_assign($roleid, $userid, $syscontext->id, 'local_ubionai');
        if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $userid])) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid' => $userid,
                'iprestriction' => null,
                'validuntil' => null,
                'timecreated' => time(),
            ]);
        }
        $validuntil = time() + self::TOKEN_TTL;
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $userid,
            $syscontext,
            $validuntil,
            '',
            self::TOKEN_NAME
        );
        $tokenid = (int) $DB->get_field('external_tokens', 'id', ['token' => $token], MUST_EXIST);
        // The back channel runs without a session; record the user as the creator, not the guest.
        $DB->set_field('external_tokens', 'creatorid', $userid, ['id' => $tokenid]);
        return [$token, $tokenid, $validuntil];
    }

    /**
     * Tokens of the per-user service held by the user (never other services).
     *
     * @param int $userid user id
     * @return array token records (id, name, timecreated, validuntil, lastaccess)
     */
    public static function user_tokens(int $userid): array {
        global $DB;
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => self::SERVICE]);
        if (!$serviceid) {
            return [];
        }
        return $DB->get_records(
            'external_tokens',
            ['userid' => $userid, 'externalserviceid' => $serviceid],
            'timecreated DESC, id DESC',
            'id, name, timecreated, validuntil, lastaccess'
        );
    }

    /**
     * Delete one per-user service token of the user. Tokens of other services are never touched.
     * When the user has no per-user token left, the service authorisation and role are removed.
     *
     * @param int $userid user id
     * @param int $tokenid external_tokens.id
     * @return bool true when a token was deleted
     */
    public static function revoke_token(int $userid, int $tokenid): bool {
        global $DB;
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => self::SERVICE]);
        if (!$serviceid) {
            return false;
        }
        $conditions = ['id' => $tokenid, 'userid' => $userid, 'externalserviceid' => $serviceid];
        if (!$DB->record_exists('external_tokens', $conditions)) {
            return false;
        }
        $DB->delete_records('external_tokens', $conditions);
        if (!$DB->record_exists('external_tokens', ['userid' => $userid, 'externalserviceid' => $serviceid])) {
            $DB->delete_records('external_services_users', ['externalserviceid' => $serviceid, 'userid' => $userid]);
            if ($roleid = $DB->get_field('role', 'id', ['shortname' => setup::ROLE_SHORTNAME])) {
                role_unassign($roleid, $userid, \context_system::instance()->id, 'local_ubionai');
            }
        }
        return true;
    }

    /**
     * Back channel: the hub revokes a token it holds (connector removed, refresh reuse, failed check).
     *
     * @param string $assertion signed revoke request
     * @return bool
     */
    public static function revoke_from_hub(string $assertion): bool {
        $claims = self::verify($assertion, 'revoke');
        if (!is_int($claims['userid'] ?? null) || !is_int($claims['tokenid'] ?? null)) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        return self::revoke_token($claims['userid'], $claims['tokenid']);
    }

    /**
     * Base64url encode without padding.
     *
     * @param string $data bytes
     * @return string
     */
    public static function b64(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode.
     *
     * @param string $data text
     * @return string
     */
    private static function unb64(string $data): string {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
