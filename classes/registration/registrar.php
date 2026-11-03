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

namespace local_ubionai\registration;

use local_ubionai\local\oauth;

/**
 * Site self-registration with the hub (registration contract §1-§3, §8.1, option 1 shared secret).
 *
 * register: precheck -> nonce/challenge -> POST /register (the hub calls register_callback.php)
 * -> secret stored as "next" (encrypted) -> POST /confirm -> "next" promoted to "current".
 * Until confirm succeeds the previous registration (current secret, site key, URLs) is kept.
 *
 * Never calls provisioner::provision() or hub_client::register(), never sends a web service
 * token, administrator data or the admin contact.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registrar {
    /** Official hub, used when the advanced hub_url setting is empty. */
    public const DEFAULT_HUB = 'https://mcp.ubion.ai';
    /** Terms / privacy notice version the consent screen shows. */
    public const TERMS_VERSION = '2026-11-01';
    /** Consent scopes of v1. */
    public const SCOPES = ['hub_processing', 'llm_provider_transfer'];
    /** Pending registration lifetime (seconds). */
    public const PENDING_TTL = 600;
    /** Site key format (= hub site_id). */
    public const SITE_KEY_RE = '/\A[a-z0-9][a-z0-9-]{1,62}\z/D';

    /** @var hub_api|null transport override for unit tests */
    private static $api = null;
    /** @var bool true while the adhoc retry task runs */
    private static $intask = false;

    /**
     * Whether the new self-registration flow is switched on (WP4 flag, legacy flow untouched).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (string) get_config('local_ubionai', 'self_registration') !== '0';
    }

    /**
     * Hub base URL: the hub_url setting, or the official hub when empty.
     *
     * @return string|null null when the configured value is not a plain https URL
     */
    public static function hub_base(): ?string {
        $url = rtrim(trim((string) get_config('local_ubionai', 'hub_url')), '/');
        if ($url === '') {
            $url = self::DEFAULT_HUB;
        }
        $parts = parse_url($url);
        if (
            $parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            return null;
        }
        return $url;
    }

    /**
     * Plugin version and release from version.php (the values on disk).
     *
     * @return array{version: int, release: string}
     */
    public static function plugin_info(): array {
        global $CFG;
        $plugin = new \stdClass();
        include($CFG->dirroot . '/local/ubionai/version.php');
        return ['version' => (int) $plugin->version, 'release' => (string) $plugin->release];
    }

    /**
     * hex(sha256("ubionai-site-v1\0" + siteidentifier)).
     *
     * @return string
     */
    public static function site_identifier_hash(): string {
        return hash('sha256', "ubionai-site-v1\0" . get_site_identifier());
    }

    /**
     * base64url(sha256(base64url_decode(challenge))): the hash covers the decoded bytes, not the string.
     *
     * @param string $challenge base64url challenge as returned to the hub callback
     * @return string
     */
    public static function challenge_sha256(string $challenge): string {
        $raw = base64_decode(strtr($challenge, '-_', '+/') . str_repeat('=', (4 - strlen($challenge) % 4) % 4), true);
        if ($raw === false || $raw === '') {
            throw new \coding_exception('invalid challenge');
        }
        return oauth::b64(hash('sha256', $raw, true));
    }

    /**
     * Consent setting changed (called from setup::consent_updated). Never throws.
     *
     * @param string|false $value new consent value
     * @return void
     */
    public static function consent_updated($value): void {
        try {
            if ($value === 'accepted') {
                if (state::get() === state::REGISTERED) {
                    return;
                }
                set_config('consent_accepted_at', time(), 'local_ubionai');
                state::set(state::CONSENTED);
                if (self::enabled()) {
                    self::register();
                }
                return;
            }
            self::withdraw();
        } catch (\Throwable $e) {
            debugging('local_ubionai registration failed: ' . get_class($e), DEBUG_DEVELOPER);
            self::fail_final('plugin_error');
        }
    }

    /**
     * Register (or re-register) now: one synchronous attempt.
     *
     * @return string resulting state
     */
    public static function register(): string {
        return self::locked(function (): string {
            return self::do_register();
        });
    }

    /**
     * Administrator "Retry": start a new retry series and run the pending step now.
     *
     * @return string resulting state
     */
    public static function retry(): string {
        return self::locked(function (): string {
            self::clear_schedule();
            if (get_config('local_ubionai', 'reg_issued') && secrets::has('next')) {
                return self::do_confirm();
            }
            return self::do_register();
        });
    }

    /**
     * Administrator "Reissue secret": rotate with the current secret, then confirm.
     *
     * @return string resulting state
     */
    public static function rotate(): string {
        return self::locked(function (): string {
            self::clear_schedule();
            return self::do_rotate();
        });
    }

    /**
     * Adhoc task entry point.
     *
     * @return string resulting state
     */
    public static function run_scheduled(): string {
        self::$intask = true;
        try {
            return self::locked(function (): string {
                return self::run_due();
            });
        } finally {
            self::$intask = false;
        }
    }

    /**
     * Run the due retry step (inside the lock).
     *
     * @return string resulting state
     */
    private static function run_due(): string {
        if (state::get() !== state::FAILED_RETRYABLE) {
            return state::get();
        }
        $due = (int) get_config('local_ubionai', 'next_retry_at');
        if ($due > time()) {
            self::queue_task($due);
            return state::get();
        }
        $action = (string) get_config('local_ubionai', 'reg_retry_action');
        if ($action === 'confirm' && secrets::has('next')) {
            return self::do_confirm();
        }
        if ($action === 'rotate' && secrets::has('current')) {
            return self::do_rotate();
        }
        return self::do_register();
    }

    /**
     * Status for the admin screen and CLI (no secrets).
     *
     * @return array
     */
    public static function status(): array {
        global $CFG;
        $cfg = get_config('local_ubionai');
        $registeredurl = (string) ($cfg->reg_site_url ?? '');
        return [
            'enabled' => self::enabled(),
            'state' => state::get(),
            'hub' => self::hub_base(),
            'site_key' => (string) ($cfg->site_key ?? ''),
            'secret_version' => secrets::current_version(),
            'secret_version_next' => secrets::next_version(),
            'tier' => (string) ($cfg->tier ?? ''),
            'tenant_status' => (string) ($cfg->tenant_status ?? ''),
            'limits' => self::json_config('limits'),
            'mcp_url' => (string) ($cfg->mcp_url ?? ''),
            'legacy_mcp_url' => (string) ($cfg->legacy_mcp_url ?? ''),
            'egress_ips' => self::json_config('egress_ips'),
            'last_error' => (string) ($cfg->last_error ?? ''),
            'last_attempt_at' => (int) ($cfg->last_attempt_at ?? 0) ?: null,
            'next_retry_at' => (int) ($cfg->next_retry_at ?? 0) ?: null,
            'retry_exhausted' => !empty($cfg->reg_exhausted),
            'registered_at' => (int) ($cfg->registered_at ?? 0) ?: null,
            'url_changed' => $registeredurl !== '' && $registeredurl !== (string) $CFG->wwwroot,
            'auth_mode' => (string) ($cfg->auth_mode ?? 'legacy_file'),
        ];
    }

    /**
     * Authenticator for a hub message protection mode (contract §7). legacy_file stays in oauth.php.
     *
     * @param string $mode shared_secret|signature
     * @return site_authenticator
     */
    public static function authenticator(string $mode): site_authenticator {
        if ($mode === 'shared_secret') {
            return new shared_secret_authenticator();
        }
        if ($mode === 'signature') {
            return new signature_authenticator();
        }
        throw new \coding_exception('Unsupported auth mode: ' . $mode);
    }

    /**
     * The pending registration for the callback, if any and not expired.
     *
     * @return array{nonce: string, challenge: string, created: int}|null
     */
    public static function pending(): ?array {
        $raw = get_config('local_ubionai', 'reg_pending');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (
            !is_array($data) || !is_string($data['nonce'] ?? null) || !is_string($data['challenge'] ?? null)
                || !is_int($data['created'] ?? null) || time() - $data['created'] > self::PENDING_TTL
        ) {
            return null;
        }
        try {
            $challenge = \core\encryption::decrypt($data['challenge']);
        } catch (\moodle_exception $e) {
            return null;
        }
        return ['nonce' => $data['nonce'], 'challenge' => $challenge, 'created' => $data['created']];
    }

    /**
     * Use a fake hub in unit tests.
     *
     * @param hub_api|null $api
     * @return void
     */
    public static function set_api_for_testing(?hub_api $api): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for unit tests');
        }
        self::$api = $api;
    }

    /**
     * Hub API client.
     *
     * @param string $base hub base URL
     * @return hub_api
     */
    protected static function api(string $base): hub_api {
        return self::$api ?? new hub_api($base);
    }

    /**
     * One registration attempt.
     *
     * @return string resulting state
     */
    private static function do_register(): string {
        global $CFG;
        if (get_config('local_ubionai', 'consent') !== 'accepted') {
            state::set(state::AWAITING_CONSENT);
            return state::AWAITING_CONSENT;
        }
        $base = self::hub_base();
        if ($base === null) {
            return self::fail_final('invalid_hub_url');
        }
        $wwwroot = (string) $CFG->wwwroot;
        if (($problem = precheck::problem($wwwroot)) !== null) {
            self::clear_schedule();
            set_config('last_error', $problem, 'local_ubionai');
            state::set(state::UNSUPPORTED_PRIVATE);
            return state::UNSUPPORTED_PRIVATE;
        }

        $nonce = oauth::b64(random_bytes(32));
        $challenge = oauth::b64(random_bytes(32));
        set_config('reg_pending', json_encode([
            'nonce' => $nonce,
            'challenge' => \core\encryption::encrypt($challenge),
            'created' => time(),
        ]), 'local_ubionai');
        state::set(state::REGISTERING);
        set_config('last_attempt_at', time(), 'local_ubionai');

        $info = self::plugin_info();
        $acceptedat = (int) get_config('local_ubionai', 'consent_accepted_at') ?: time();
        $payload = [
            'schema' => 'ubionai.site-registration/v1',
            'site_url' => $wwwroot,
            'wwwroot_host' => strtolower((string) parse_url($wwwroot, PHP_URL_HOST)),
            'moodle' => [
                'release' => \core_text::substr((string) $CFG->release, 0, 100),
                'version' => (string) $CFG->version,
                'branch' => (string) $CFG->branch,
            ],
            'plugin' => ['component' => 'local_ubionai', 'version' => $info['version'], 'release' => $info['release']],
            'site_identifier_hash' => self::site_identifier_hash(),
            'callback_nonce' => $nonce,
            'challenge_sha256' => self::challenge_sha256($challenge),
            'consent' => [
                'accepted' => true,
                'terms_version' => self::TERMS_VERSION,
                'accepted_at' => gmdate('Y-m-d\TH:i:s\Z', $acceptedat),
                'scopes' => self::SCOPES,
            ],
            'locale' => strpos(current_language(), 'ko') === 0 ? 'ko' : 'en',
            'auth_modes' => ['shared_secret'],
        ];
        unset($challenge);

        $response = self::api($base)->register($payload);
        if ($response['httpcode'] === 200 || $response['httpcode'] === 201) {
            return self::issued($base, $response['data'], 'register');
        }
        return self::failure($response, 'register');
    }

    /**
     * One rotate attempt.
     *
     * @return string resulting state
     */
    private static function do_rotate(): string {
        $base = self::hub_base();
        $sitekey = (string) get_config('local_ubionai', 'site_key');
        $secret = secrets::reveal('current');
        if ($base === null || $sitekey === '' || $secret === null) {
            return self::fail_final($base === null ? 'invalid_hub_url' : 'invalid_site_credentials');
        }
        set_config('last_attempt_at', time(), 'local_ubionai');
        $response = self::api($base)->rotate($sitekey, $secret);
        unset($secret);
        if ($response['httpcode'] === 200) {
            return self::issued($base, $response['data'], 'rotate');
        }
        return self::failure($response, 'rotate');
    }

    /**
     * One confirm attempt for the "next" secret.
     *
     * @return string resulting state
     */
    private static function do_confirm(): string {
        global $CFG;
        $base = self::hub_base();
        $issued = self::json_config('reg_issued');
        $secret = secrets::reveal('next');
        $version = secrets::next_version();
        if ($base === null) {
            return self::fail_final('invalid_hub_url');
        }
        if ($secret === null || $version === null || !is_string($issued['site_key'] ?? null)) {
            return self::failure(['httpcode' => 409, 'data' => ['error' => 'nothing_to_confirm'], 'errno' => 0], 'confirm');
        }
        set_config('last_attempt_at', time(), 'local_ubionai');
        $response = self::api($base)->confirm($issued['site_key'], $version, $secret);
        unset($secret);
        $data = $response['data'] ?? [];
        if (
            $response['httpcode'] !== 200 || ($data['secret_state'] ?? null) !== 'current'
                || ($data['secret_version'] ?? null) !== $version
        ) {
            return self::failure($response, 'confirm');
        }

        secrets::promote_next();
        set_config('site_key', $issued['site_key'], 'local_ubionai');
        foreach (['mcp_url', 'legacy_mcp_url', 'tier', 'tenant_status', 'reg_auth_mode'] as $name) {
            set_config($name, (string) ($issued[$name] ?? ''), 'local_ubionai');
        }
        foreach (['allowed_callbacks', 'issuers', 'limits', 'egress_ips'] as $name) {
            set_config($name, json_encode($issued[$name] ?? []), 'local_ubionai');
        }
        set_config('registered_at', time(), 'local_ubionai');
        set_config('reg_site_url', (string) $CFG->wwwroot, 'local_ubionai');
        unset_config('reg_issued', 'local_ubionai');
        unset_config('reg_pending', 'local_ubionai');
        unset_config('last_error', 'local_ubionai');
        self::clear_schedule();
        state::set(state::REGISTERED);
        return state::REGISTERED;
    }

    /**
     * Handle a 200/201 that carries a new secret: validate, store as "next", confirm.
     *
     * @param string $base hub base URL
     * @param array|null $data decoded body
     * @param string $phase register|rotate
     * @return string resulting state
     */
    private static function issued(string $base, ?array $data, string $phase): string {
        $issued = self::validate_issued($base, $data ?? []);
        if (
            $issued === null
                || ($phase === 'rotate' && $issued['site_key'] !== (string) get_config('local_ubionai', 'site_key'))
        ) {
            return self::failure(['httpcode' => 200, 'data' => ['error' => 'bad_response'], 'errno' => 0], $phase);
        }
        secrets::store_next($data['site_secret'], $issued['secret_version']);
        unset($data['site_secret']);
        set_config('reg_issued', json_encode($issued), 'local_ubionai');
        state::set(state::PENDING_CONFIRM);
        return self::do_confirm();
    }

    /**
     * Validate the non-secret part of a registration / rotate result (contract §1.4).
     *
     * @param string $base hub base URL
     * @param array $data decoded body
     * @return array|null normalised metadata without the secret
     */
    private static function validate_issued(string $base, array $data): ?array {
        $sitekey = $data['site_key'] ?? null;
        $version = $data['secret_version'] ?? null;
        if (
            ($data['schema'] ?? null) !== 'ubionai.site-registration-result/v1'
                || !is_string($sitekey) || !preg_match(self::SITE_KEY_RE, $sitekey)
                || !is_string($data['site_secret'] ?? null) || !preg_match(secrets::SECRET_RE, $data['site_secret'])
                || !is_int($version) || $version < 1
                || ($data['secret_state'] ?? null) !== 'pending_confirm'
                || ($data['auth_mode'] ?? 'shared_secret') !== 'shared_secret'
        ) {
            return null;
        }
        if (isset($data['mac'])) {
            $mac = $data['mac'];
            if (
                !is_array($mac) || ($mac['salt'] ?? null) !== secrets::HKDF_SALT
                    || ($mac['info'] ?? null) !== secrets::hkdf_info($sitekey, $version)
            ) {
                return null;
            }
        }
        $hubhost = strtolower((string) parse_url($base, PHP_URL_HOST));
        $urlok = static function ($url) use ($hubhost): bool {
            return is_string($url) && strlen($url) <= 512 && preg_match('#\Ahttps://[^\s?\#@]+\z#', $url)
                && strtolower((string) parse_url($url, PHP_URL_HOST)) === $hubhost;
        };
        $mcpurl = $data['mcp_url'] ?? null;
        $legacy = $data['legacy_mcp_url'] ?? null;
        if (($mcpurl !== null && !$urlok($mcpurl)) || ($legacy !== null && !$urlok($legacy))) {
            return null;
        }
        $lists = [];
        foreach (['allowed_callbacks', 'issuers'] as $name) {
            $list = $data['oauth'][$name] ?? [];
            if (!is_array($list) || count($list) > 8) {
                return null;
            }
            foreach ($list as $url) {
                if (!$urlok($url)) {
                    return null;
                }
            }
            $lists[$name] = array_values($list);
        }
        $egress = $data['egress_ips'] ?? [];
        if (!is_array($egress) || count($egress) > 16) {
            return null;
        }
        foreach ($egress as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return null;
            }
        }
        $tier = $data['tenant']['tier'] ?? '';
        $status = $data['tenant']['status'] ?? '';
        $limits = $data['tenant']['limits'] ?? [];
        if (
            !is_string($tier) || !preg_match('/\A[a-z_]{0,32}\z/', $tier)
                || !is_string($status) || !preg_match('/\A[a-z_]{0,32}\z/', $status) || !is_array($limits)
        ) {
            return null;
        }
        $cleanlimits = [];
        foreach ($limits as $key => $value) {
            if (is_string($key) && preg_match('/\A[a-z_]{1,32}\z/', $key) && (is_scalar($value) || $value === null)) {
                $cleanlimits[$key] = $value;
            }
        }
        return [
            'site_key' => $sitekey,
            'secret_version' => $version,
            'mcp_url' => (string) ($mcpurl ?? ''),
            'legacy_mcp_url' => (string) ($legacy ?? ''),
            'allowed_callbacks' => $lists['allowed_callbacks'],
            'issuers' => $lists['issuers'],
            'tier' => $tier,
            'tenant_status' => $status,
            'limits' => $cleanlimits,
            'egress_ips' => array_values($egress),
            'reg_auth_mode' => 'shared_secret',
        ];
    }

    /**
     * Record a failed hub call and schedule a retry when the code allows it.
     *
     * @param array $response {httpcode, data, errno}
     * @param string $phase register|confirm|rotate
     * @return string resulting state
     */
    private static function failure(array $response, string $phase): string {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $code = errors::normalise((int) $response['httpcode'], is_string($data['error'] ?? null) ? $data['error'] : null);
        $retryafter = is_int($data['retry_after'] ?? null) ? $data['retry_after'] : null;
        $decision = errors::classify($code, $phase);
        set_config('last_error', $code, 'local_ubionai');

        if ($decision['state'] === state::UNSUPPORTED_PRIVATE) {
            self::clear_schedule();
            state::set(state::UNSUPPORTED_PRIVATE);
            return state::UNSUPPORTED_PRIVATE;
        }
        if (!$decision['retry']) {
            return self::fail_final($code);
        }

        $now = time();
        $attempt = (int) get_config('local_ubionai', 'reg_attempts') + 1;
        $first = (int) get_config('local_ubionai', 'reg_first_failure_at') ?: $now;
        $delay = errors::next_delay($attempt, $first, $now, $code, $retryafter);
        if ($delay === null) {
            set_config('reg_exhausted', 1, 'local_ubionai');
            unset_config('next_retry_at', 'local_ubionai');
            state::set(state::FAILED_ACTION_REQUIRED);
            return state::FAILED_ACTION_REQUIRED;
        }
        set_config('reg_attempts', $attempt, 'local_ubionai');
        set_config('reg_first_failure_at', $first, 'local_ubionai');
        set_config('reg_retry_action', $decision['action'], 'local_ubionai');
        set_config('next_retry_at', $now + $delay, 'local_ubionai');
        state::set(state::FAILED_RETRYABLE);
        self::queue_task($now + $delay);
        return state::FAILED_RETRYABLE;
    }

    /**
     * Stop with a failure that needs the administrator.
     *
     * @param string $code error code
     * @return string resulting state
     */
    private static function fail_final(string $code): string {
        set_config('last_error', $code, 'local_ubionai');
        self::clear_schedule();
        state::set(state::FAILED_ACTION_REQUIRED);
        return state::FAILED_ACTION_REQUIRED;
    }

    /**
     * Consent withdrawn: tell the hub (best effort) and forget the registration.
     *
     * @return void
     */
    private static function withdraw(): void {
        $base = self::hub_base();
        $sitekey = (string) get_config('local_ubionai', 'site_key');
        $secret = secrets::reveal('current');
        $wasregistered = $secret !== null && $sitekey !== '';
        if ($wasregistered && $base !== null) {
            self::api($base)->deregister($sitekey, $secret);
        }
        unset($secret);
        secrets::clear();
        foreach (['reg_pending', 'reg_issued', 'last_error', 'registered_at', 'consent_accepted_at'] as $name) {
            unset_config($name, 'local_ubionai');
        }
        self::clear_schedule();
        state::set($wasregistered ? state::DEREGISTERED : state::AWAITING_CONSENT);
    }

    /**
     * Forget the retry series.
     *
     * @return void
     */
    private static function clear_schedule(): void {
        foreach (['reg_attempts', 'reg_first_failure_at', 'reg_retry_action', 'next_retry_at', 'reg_exhausted'] as $name) {
            unset_config($name, 'local_ubionai');
        }
    }

    /**
     * Queue (or move) the retry adhoc task.
     *
     * @param int $when next run time
     * @return void
     */
    private static function queue_task(int $when): void {
        $task = new \local_ubionai\task\retry_registration();
        $task->set_custom_data(['v' => 1]);
        $task->set_next_run_time($when);
        if (self::$intask) {
            // The running task row is deleted when it finishes, so it must not be the one moved.
            \core\task\manager::queue_adhoc_task($task);
        } else {
            \core\task\manager::reschedule_or_queue_adhoc_task($task);
        }
    }

    /**
     * Run under the registration lock so the admin screen, CLI and cron never overlap.
     *
     * @param callable $fn work
     * @return string resulting state
     */
    private static function locked(callable $fn): string {
        $factory = \core\lock\lock_config::get_lock_factory('local_ubionai_registration');
        $lock = $factory->get_lock('registration', 5);
        if (!$lock) {
            return state::get();
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Decode a JSON config value.
     *
     * @param string $name config name
     * @return array
     */
    private static function json_config(string $name): array {
        $raw = get_config('local_ubionai', $name);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }
}
