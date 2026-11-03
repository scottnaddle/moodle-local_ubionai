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

/**
 * Tests for the hub error code -> state / retry mapping and the backoff schedule.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai\registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the hub error code -> state / retry mapping and the backoff schedule.
 *
 * @covers \local_ubionai\registration\errors
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(errors::class)]
final class errors_test extends \advanced_testcase {
    /**
     * Error codes with phase, expected state, retry flag and retry action.
     *
     * @return array
     */
    public static function classify_provider(): array {
        $retry = state::FAILED_RETRYABLE;
        $final = state::FAILED_ACTION_REQUIRED;
        return [
            'invalid_request' => ['invalid_request', 'register', $final, false, null],
            'invalid_site_url' => ['invalid_site_url', 'register', $final, false, null],
            'unsupported_scheme' => ['unsupported_scheme', 'register', $final, false, null],
            'consent_required' => ['consent_required', 'register', $final, false, null],
            'consent_outdated' => ['consent_outdated', 'register', $final, false, null],
            'private' => ['private_address_not_supported', 'register', state::UNSUPPORTED_PRIVATE, false, null],
            'port_not_allowed' => ['port_not_allowed', 'register', $final, false, null],
            'host_blocked' => ['host_blocked', 'register', $final, false, null],
            'operator' => ['site_managed_by_operator', 'register', $final, false, null],
            'site_url_mismatch' => ['site_url_mismatch', 'register', $final, false, null],
            'callback_mismatch' => ['callback_mismatch', 'register', $final, false, null],
            'callback_redirected' => ['callback_redirected', 'register', $final, false, null],
            'plugin_upgrade_required' => ['plugin_upgrade_required', 'register', $final, false, null],
            'request_too_large' => ['request_too_large', 'register', $final, false, null],
            'bad_response' => ['bad_response', 'register', $final, false, null],
            'in_progress' => ['registration_in_progress', 'register', $retry, true, 'register'],
            'nonce_reused' => ['nonce_reused', 'register', $retry, true, 'register'],
            'rate_limited' => ['rate_limited', 'register', $retry, true, 'register'],
            'internal_error' => ['internal_error', 'register', $retry, true, 'register'],
            'unreachable' => ['callback_unreachable', 'register', $retry, true, 'register'],
            'callback_bad_response' => ['callback_bad_response', 'register', $retry, true, 'register'],
            'callback_timeout' => ['callback_timeout', 'register', $retry, true, 'register'],
            'disabled' => ['registration_disabled', 'register', $retry, true, 'register'],
            'verifier_busy' => ['verifier_busy', 'register', $retry, true, 'register'],
            'hub_unreachable' => ['hub_unreachable', 'register', $retry, true, 'register'],
            'confirm transient' => ['internal_error', 'confirm', $retry, true, 'confirm'],
            'confirm expired' => ['pending_secret_expired', 'confirm', $retry, true, 'register'],
            'confirm unknown site' => ['site_not_found', 'confirm', $retry, true, 'register'],
            'rotate bad secret' => ['invalid_site_credentials', 'rotate', $retry, true, 'register'],
            'rotate transient' => ['verifier_busy', 'rotate', $retry, true, 'rotate'],
        ];
    }

    /**
     * Each code maps to the documented plugin behaviour (contract §4.1).
     *
     * @param string $code error code
     * @param string $phase register|confirm|rotate
     * @param string $state expected state
     * @param bool $retry expected retry flag
     * @param string|null $action expected retry action
     *
     * @dataProvider classify_provider
     */
    #[DataProvider('classify_provider')]
    public function test_classify(string $code, string $phase, string $state, bool $retry, ?string $action): void {
        $this->assertSame(['state' => $state, 'retry' => $retry, 'action' => $action], errors::classify($code, $phase));
    }

    /**
     * HTTP status / body combinations and the code they normalise to.
     *
     * @return array
     */
    public static function normalise_provider(): array {
        return [
            'transport failure' => [0, null, 'hub_unreachable'],
            'known code' => [502, 'callback_unreachable', 'callback_unreachable'],
            'unknown code on 429' => [429, 'whatever', 'rate_limited'],
            'html 503' => [503, null, 'hub_unreachable'],
            'unknown 4xx' => [418, null, 'bad_response'],
            'unknown code on 400' => [400, 'surprise', 'bad_response'],
        ];
    }

    /**
     * Unknown or missing codes fall back by HTTP status.
     *
     * @param int $httpcode status
     * @param string|null $code body error
     * @param string $expected normalised code
     *
     * @dataProvider normalise_provider
     */
    #[DataProvider('normalise_provider')]
    public function test_normalise(int $httpcode, ?string $code, string $expected): void {
        $this->assertSame($expected, errors::normalise($httpcode, $code));
    }

    /**
     * Backoff is 1 min, 5 min, 30 min, 2 h, 6 h, then 6 h, and stops after 24 h.
     */
    public function test_backoff(): void {
        $now = 1_800_000_000;
        $delays = [];
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $delays[] = errors::next_delay($attempt, $now, $now, 'internal_error', null);
        }
        $this->assertSame([60, 300, 1800, 7200, 21600, 21600, 21600], $delays);
        $this->assertSame(21600, errors::next_delay(1, $now, $now, 'registration_disabled', null));
        $this->assertSame(900, errors::next_delay(1, $now, $now, 'rate_limited', 900));
        $this->assertSame(60, errors::next_delay(1, $now, $now, 'rate_limited', 15));
        $this->assertSame(21600, errors::next_delay(1, $now, $now, 'rate_limited', 999999));
        $this->assertSame(21600, errors::next_delay(6, $now - DAYSECS + 1, $now, 'internal_error', null));
        $this->assertNull(errors::next_delay(6, $now - DAYSECS, $now, 'internal_error', null));
    }

    /**
     * Every code has an English and a Korean message.
     */
    public function test_every_code_has_strings(): void {
        $sm = get_string_manager();
        foreach (errors::KNOWN as $code) {
            $this->assertTrue($sm->string_exists('reg_error_' . $code, 'local_ubionai'), $code);
        }
        foreach (state::ALL as $state) {
            $this->assertTrue($sm->string_exists('reg_state_' . $state, 'local_ubionai'), $state);
        }
        $ko = [];
        $string = [];
        include(__DIR__ . '/../../lang/ko/local_ubionai.php');
        $ko = $string;
        foreach (errors::KNOWN as $code) {
            $this->assertArrayHasKey('reg_error_' . $code, $ko, $code);
        }
    }
}
