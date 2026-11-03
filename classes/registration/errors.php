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

/**
 * Hub error code -> plugin state / retry mapping (registration contract §4.1) and retry backoff.
 *
 * Every code has a reg_error_<code> language string (shown to site administrators).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class errors {
    /** Backoff delays in seconds: 1 min, 5 min, 30 min, 2 h, 6 h (then 6 h until the deadline). */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];
    /** Stop retrying this long after the first failure of a series. */
    public const GIVE_UP_AFTER = DAYSECS;
    /** Delay for registration_disabled (hub feature flag off). */
    public const DISABLED_DELAY = 21600;

    /** Hub codes the plugin retries automatically (§4.1 retryable set). */
    public const RETRYABLE = [
        'registration_in_progress', 'nonce_reused', 'rate_limited', 'internal_error',
        'callback_unreachable', 'callback_bad_response', 'callback_timeout',
        'registration_disabled', 'verifier_busy',
        // Local: transport failure or an unparsable hub answer on a 5xx.
        'hub_unreachable',
    ];

    /** Codes after which the plugin must register again (new callback proof). */
    public const REREGISTER = [
        'site_not_found', 'invalid_site_credentials', 'pending_secret_expired', 'nothing_to_confirm',
    ];

    /** Every hub code of §4.1 plus local codes; used for language strings. */
    public const KNOWN = [
        'invalid_request', 'invalid_site_url', 'unsupported_scheme', 'consent_required',
        'consent_outdated', 'private_address_not_supported', 'port_not_allowed', 'host_blocked',
        'site_not_found', 'invalid_site_credentials', 'registration_in_progress', 'nonce_reused',
        'site_managed_by_operator', 'nothing_to_confirm', 'pending_secret_expired',
        'request_too_large', 'unsupported_media_type', 'site_url_mismatch', 'callback_mismatch',
        'callback_redirected', 'plugin_upgrade_required', 'rate_limited', 'callback_unreachable',
        'callback_bad_response', 'callback_timeout', 'registration_disabled', 'verifier_busy',
        'internal_error',
        // Local codes.
        'hub_unreachable', 'bad_response', 'invalid_hub_url', 'plugin_error', 'retry_exhausted',
        'not_consented',
    ];

    /**
     * Normalise an error code from a hub response.
     *
     * @param int $httpcode HTTP status (0 = transport failure)
     * @param string|null $code "error" member of the JSON body
     * @return string a code from KNOWN
     */
    public static function normalise(int $httpcode, ?string $code): string {
        if ($httpcode === 0) {
            return 'hub_unreachable';
        }
        if ($code !== null && in_array($code, self::KNOWN, true)) {
            return $code;
        }
        if ($httpcode === 429) {
            return 'rate_limited';
        }
        if ($httpcode >= 500) {
            return 'hub_unreachable';
        }
        return 'bad_response';
    }

    /**
     * What to do after a failure.
     *
     * @param string $code normalised code
     * @param string $phase register|confirm|rotate
     * @return array{state: string, retry: bool, action: string|null} action = phase to retry
     */
    public static function classify(string $code, string $phase): array {
        if ($code === 'private_address_not_supported') {
            return ['state' => state::UNSUPPORTED_PRIVATE, 'retry' => false, 'action' => null];
        }
        if ($phase !== 'register' && in_array($code, self::REREGISTER, true)) {
            return ['state' => state::FAILED_RETRYABLE, 'retry' => true, 'action' => 'register'];
        }
        if (in_array($code, self::RETRYABLE, true)) {
            return ['state' => state::FAILED_RETRYABLE, 'retry' => true, 'action' => $phase];
        }
        return ['state' => state::FAILED_ACTION_REQUIRED, 'retry' => false, 'action' => null];
    }

    /**
     * Delay before the next attempt, or null when the 24 hour window is over.
     *
     * @param int $attempt failures so far in this series (1 = first failure)
     * @param int $firstfailure time of the first failure of the series
     * @param int $now current time
     * @param string $code normalised code
     * @param int|null $retryafter hub retry_after hint in seconds
     * @return int|null seconds
     */
    public static function next_delay(int $attempt, int $firstfailure, int $now, string $code, ?int $retryafter): ?int {
        if ($now - $firstfailure >= self::GIVE_UP_AFTER) {
            return null;
        }
        $index = max(0, min($attempt, count(self::BACKOFF)) - 1);
        $delay = self::BACKOFF[$index];
        if ($code === 'registration_disabled') {
            $delay = max($delay, self::DISABLED_DELAY);
        }
        if ($retryafter !== null && $retryafter > $delay) {
            $delay = min($retryafter, self::DISABLED_DELAY);
        }
        return $delay;
    }
}
