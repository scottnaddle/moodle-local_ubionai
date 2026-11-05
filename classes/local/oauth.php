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

use local_ubionai\registration\registrar;
use local_ubionai\registration\secrets;
use local_ubionai\registration\state;
use moodle_exception;

/**
 * Per-user delegated login for the hub's OAuth server.
 *
 * The hub redirects a teacher's browser to oauth/authorize.php with a signed request. After Moodle
 * login and consent the plugin redirects back to the hub with a one-time code; the hub redeems the
 * code over the back channel (oauth/exchange.php, also signed) for a web service token of that
 * user, limited to the ubionai_user service. Site administrators and tokens of any other service
 * (moodle_mobile_app etc.) are refused in code.
 *
 * Signed messages: base64url(json) . "." . base64url(HMAC-SHA256(key, "ubionai-oauth-v1." . body)).
 * The key and the hub URLs depend on how the site is connected (registration contract §6, §7):
 *
 * - shared_secret (self-registered, a confirmed site secret held): key = HKDF mac_key of the site secret
 *   version named by the kv claim, site = site_key, callback {hub}/m/<host_key>/oauth/callback
 *   from the allowed_callbacks the hub returned at registration, iss = that /m/ issuer.
 * - legacy_file (manual hub-staging setup): key = the oauth_secret setting, site = hub_site_id,
 *   callback {hub_url}/t/{hub_site_id}/oauth/callback.
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
    /** Mode of a self-registered site. */
    public const MODE_SHARED = 'shared_secret';
    /** Mode of a manually configured site. */
    public const MODE_LEGACY = 'legacy_file';
    /** MAC domain separator. */
    private const MAC_PREFIX = 'ubionai-oauth-v1.';
    /** Path of the hub callback below an issuer. */
    private const CALLBACK_SUFFIX = '/oauth/callback';

    /**
     * Message protection in use: shared_secret once self-registration is confirmed, else legacy_file.
     *
     * @return string self::MODE_SHARED|self::MODE_LEGACY
     */
    public static function mode(): string {
        return self::registered() ? self::MODE_SHARED : self::MODE_LEGACY;
    }

    /**
     * Whether the site is self-registered with a confirmed (current) site secret.
     *
     * Keyed on the current secret rather than reg_state = registered: a rotation or re-registration
     * that fails half way leaves reg_state at pending_confirm / failed_* while the hub keeps signing
     * with the current version. The current secret only exists after a confirm and is cleared when
     * consent is withdrawn.
     *
     * @return bool
     */
    private static function registered(): bool {
        return registrar::enabled() && state::get() !== state::DEREGISTERED
            && preg_match(registrar::SITE_KEY_RE, (string) get_config('local_ubionai', 'site_key'))
            && secrets::current_version() !== null;
    }

    /**
     * Legacy shared secret configured for this site, or null (legacy_file mode only).
     *
     * @return string|null
     */
    public static function secret(): ?string {
        $secret = trim((string) get_config('local_ubionai', 'oauth_secret'));
        return strlen($secret) >= self::MIN_SECRET ? $secret : null;
    }

    /**
     * True when this site can verify hub messages and knows the hub callback.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        if (self::callback_url() === null) {
            return false;
        }
        if (self::mode() === self::MODE_SHARED) {
            return secrets::mac_key((int) secrets::current_version()) !== null;
        }
        return self::secret() !== null;
    }

    /**
     * The hub callback URL this site redirects to (the first one when several are allowed).
     *
     * @return string|null
     */
    public static function callback_url(): ?string {
        return self::callback_urls()[0] ?? null;
    }

    /**
     * Hub callback URLs this site may redirect to.
     *
     * shared_secret: the {hub}/m/<host_key>/oauth/callback entries of the allowed_callbacks stored at
     * registration, on the currently configured hub. legacy_file: {hub_url}/t/{hub_site_id}/oauth/callback.
     *
     * @return string[]
     */
    public static function callback_urls(): array {
        if (self::mode() === self::MODE_SHARED) {
            $hub = registrar::hub_base();
            $stored = json_decode((string) get_config('local_ubionai', 'allowed_callbacks'), true);
            if ($hub === null || !is_array($stored)) {
                return [];
            }
            $re = '#\A' . preg_quote($hub, '#') . '/m/[A-Za-z0-9._:/-]+' . preg_quote(self::CALLBACK_SUFFIX, '#') . '\z#D';
            return array_values(array_filter($stored, static function ($url) use ($re): bool {
                return is_string($url) && strlen($url) <= 512 && preg_match($re, $url) && strpos($url, '/../') === false;
            }));
        }
        $hub = rtrim(trim((string) get_config('local_ubionai', 'hub_url')), '/');
        $site = trim((string) get_config('local_ubionai', 'hub_site_id'));
        if (!preg_match('#^https?://[^/?\#@\s]+(/[^?\#\s]*)?$#i', $hub) || !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $site)) {
            return [];
        }
        return [$hub . '/t/' . $site . self::CALLBACK_SUFFIX];
    }

    /**
     * Site id the hub puts in the site claim: site_key (shared_secret) or hub_site_id (legacy_file).
     *
     * @return string
     */
    private static function site_id(): string {
        $name = self::mode() === self::MODE_SHARED ? 'site_key' : 'hub_site_id';
        return trim((string) get_config('local_ubionai', $name));
    }

    /**
     * Audience the hub puts in the aud claim.
     *
     * legacy_file: wwwroot without trailing slash. shared_secret: the hub's canonical site URL
     * (normalize_site_url: lower-case scheme and host, no :443, no trailing slash).
     *
     * @return string
     */
    private static function audience(): string {
        global $CFG;
        $wwwroot = rtrim((string) $CFG->wwwroot, '/');
        if (self::mode() !== self::MODE_SHARED) {
            return $wwwroot;
        }
        $parts = parse_url($wwwroot);
        if ($parts === false || empty($parts['host'])) {
            return $wwwroot;
        }
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '';
        return strtolower($parts['scheme'] ?? '') . '://' . strtolower(rtrim($parts['host'], '.')) . $port
            . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Sign claims like the hub does (tests and interop vectors; the hub never accepts plugin MACs).
     *
     * legacy_file: with $secret or the oauth_secret setting. shared_secret (no $secret given): with
     * the current mac_key, adding kv.
     *
     * @param array $claims claims
     * @param string|null $secret raw key (defaults to the key of the current mode)
     * @return string
     */
    public static function sign(array $claims, ?string $secret = null): string {
        if ($secret === null && self::mode() === self::MODE_SHARED) {
            // The key must match the kv claim, so a claim naming another known version uses that key.
            $version = is_int($claims['kv'] ?? null) ? $claims['kv'] : (int) secrets::current_version();
            $secret = secrets::mac_key($version);
            $claims['kv'] = $version;
        } else {
            $secret = $secret ?? self::secret();
        }
        if ($secret === null) {
            throw new moodle_exception('oauth_notconfigured', 'local_ubionai');
        }
        $body = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(hash_hmac('sha256', self::MAC_PREFIX . $body, $secret, true));
    }

    /**
     * Verify a signed message from the hub: MAC (key of the current mode), type, audience (this
     * site), site id, expiry and, for shared_secret, an issuer belonging to an allowed callback.
     *
     * @param string $token signed message
     * @param string $typ expected message type
     * @return array claims
     * @throws moodle_exception when invalid
     */
    public static function verify(string $token, string $typ): array {
        if (!self::is_configured()) {
            throw new moodle_exception('oauth_notconfigured', 'local_ubionai');
        }
        if (strlen($token) > 8192 || !preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/D', $token, $m)) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        $shared = self::mode() === self::MODE_SHARED;
        if ($shared) {
            // MAC with the HKDF key of the kv version (current, or next during a rotation); hash_equals inside.
            $claims = registrar::authenticator(self::MODE_SHARED)->verify($token);
        } else {
            $expected = hash_hmac('sha256', self::MAC_PREFIX . $m[1], (string) self::secret(), true);
            if (!hash_equals($expected, self::unb64($m[2]))) {
                throw new moodle_exception('oauth_badrequest', 'local_ubionai');
            }
            $claims = json_decode(self::unb64($m[1]), true);
        }
        if (
            !is_array($claims)
                || ($claims['typ'] ?? null) !== $typ
                || !is_string($claims['aud'] ?? null) || !hash_equals(self::audience(), $claims['aud'])
                || !is_string($claims['site'] ?? null) || !hash_equals(self::site_id(), $claims['site'])
                || !is_int($claims['exp'] ?? null) || $claims['exp'] < time()
                || ($shared && !in_array($claims['iss'] ?? null, self::issuers(), true))
        ) {
            throw new moodle_exception('oauth_badrequest', 'local_ubionai');
        }
        return $claims;
    }

    /**
     * Issuers whose messages are accepted in shared_secret mode (allowed callbacks minus the suffix).
     *
     * @return string[]
     */
    private static function issuers(): array {
        return array_map(static function (string $url): string {
            return substr($url, 0, -strlen(self::CALLBACK_SUFFIX));
        }, self::callback_urls());
    }

    /**
     * Verify the authorization request the hub sent through the browser.
     *
     * @param string $req signed request
     * @return array claims (state, client_name, redirect_host, scope, callback)
     */
    public static function verify_authorize_request(string $req): array {
        $claims = self::verify($req, 'authz_req');
        $callback = $claims['callback'] ?? null;
        if (
            !is_string($callback) || !in_array($callback, self::callback_urls(), true)
                || (self::mode() === self::MODE_SHARED && $callback !== ($claims['iss'] ?? null) . self::CALLBACK_SUFFIX)
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
