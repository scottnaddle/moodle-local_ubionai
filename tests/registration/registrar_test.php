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
 * Tests for the hub self-registration flow against a fake hub.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai\registration;

use local_ubionai\local\oauth;
use local_ubionai\local\setup;
use local_ubionai\registration_fake_hub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/registration_fake_hub.php');

/**
 * Tests for the hub self-registration flow against a fake hub.
 *
 * @covers \local_ubionai\registration\registrar
 * @covers \local_ubionai\registration\precheck
 * @covers \local_ubionai\task\retry_registration
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(registrar::class)]
#[CoversClass(precheck::class)]
#[CoversClass(\local_ubionai\task\retry_registration::class)]
final class registrar_test extends \advanced_testcase {
    /** @var registration_fake_hub */
    private $hub;

    /**
     * Fake hub, public DNS answer, admin user, consent accepted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // PHPUnit's wwwroot is https://www.example.com/moodle.
        set_config('hub_url', registration_fake_hub::BASE, 'local_ubionai');
        set_config('self_registration', 1, 'local_ubionai');
        set_config('consent', 'accepted', 'local_ubionai');
        $this->hub = new registration_fake_hub();
        registrar::set_api_for_testing($this->hub);
        precheck::set_resolver_for_testing(static function (string $host): array {
            return ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'];
        });
    }

    /**
     * Remove the test doubles.
     */
    protected function tearDown(): void {
        registrar::set_api_for_testing(null);
        precheck::set_resolver_for_testing(null);
        parent::tearDown();
    }

    /**
     * Queue a full successful registration.
     *
     * @param int $code 201 or 200
     * @return void
     */
    private function queue_success(int $code = 201): void {
        $this->hub->queue('register', $code, registration_fake_hub::issued())
            ->queue('confirm', 200, ['secret_version' => 1, 'secret_state' => 'current']);
    }

    /**
     * Queued retry tasks.
     *
     * @return array
     */
    private function retry_tasks(): array {
        return \core\task\manager::get_adhoc_tasks('\\local_ubionai\\task\\retry_registration');
    }

    /**
     * Saving consent registers once, the callback proves the domain, and confirm promotes the secret.
     */
    public function test_consent_registers_and_confirms(): void {
        global $CFG;
        $this->queue_success();
        setup::consent_updated('s_local_ubionai_consent');

        $this->assertSame(state::REGISTERED, state::get());
        $this->assertCount(1, $this->hub->calls_to('register'));
        $this->assertCount(1, $this->hub->calls_to('confirm'));

        $request = json_decode($this->hub->calls_to('register')[0]['body'], true);
        $this->assertSame('ubionai.site-registration/v1', $request['schema']);
        $this->assertSame($CFG->wwwroot, $request['site_url']);
        $this->assertSame('www.example.com', $request['wwwroot_host']);
        $this->assertSame('local_ubionai', $request['plugin']['component']);
        $this->assertSame(registrar::plugin_info()['version'], $request['plugin']['version']);
        $this->assertIsString($request['moodle']['version']);
        $this->assertTrue($request['consent']['accepted']);
        $this->assertSame(['hub_processing', 'llm_provider_transfer'], $request['consent']['scopes']);
        $this->assertSame(['shared_secret'], $request['auth_modes']);
        foreach (['wstoken', 'token', 'password', 'admin_contact'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $request);
        }

        // The hub's callback saw the pending record; challenge_sha256 binds its body to the request.
        [$status, $body] = $this->hub->callbackresult;
        $this->assertSame(200, $status);
        $this->assertSame($request['callback_nonce'], $body['nonce']);
        $rawchallenge = base64_decode(strtr($body['challenge'], '-_', '+/'), true);
        $this->assertSame(32, strlen($rawchallenge));
        $this->assertSame($request['challenge_sha256'], oauth::b64(hash('sha256', $rawchallenge, true)));
        $this->assertSame($request['challenge_sha256'], registrar::challenge_sha256($body['challenge']));
        $this->assertStringNotContainsString($body['challenge'], $this->hub->calls_to('register')[0]['body']);

        // Confirm authenticates with the new secret; register sends no Authorization header at all.
        $this->assertContains('Authorization: Bearer ' . registration_fake_hub::SECRET, $this->hub->calls_to('confirm')[0]['headers']);
        $this->assertSame(['secret_version' => 1], json_decode($this->hub->calls_to('confirm')[0]['body'], true));
        foreach ($this->hub->calls_to('register')[0]['headers'] as $header) {
            $this->assertStringStartsNotWith('Authorization', $header);
        }

        $status = registrar::status();
        $this->assertSame(registration_fake_hub::SITE_KEY, $status['site_key']);
        $this->assertSame(1, $status['secret_version']);
        $this->assertNull($status['secret_version_next']);
        $this->assertSame('limited', $status['tier']);
        $this->assertSame(5, $status['limits']['max_teachers']);
        $this->assertSame(registration_fake_hub::BASE . '/m/www.example.com/moodle/mcp', $status['mcp_url']);
        $this->assertSame(['203.0.113.10'], $status['egress_ips']);
        $this->assertSame('shared_secret', $status['auth_mode']);
        $this->assertSame(registration_fake_hub::SECRET, secrets::reveal('current'));
        $this->assertStringNotContainsString(registration_fake_hub::SECRET, (string) get_config('local_ubionai', 'site_secret_current'));
        // The pending record is deleted once confirmed: the callback answers 404 again.
        $this->assertSame(404, callback::handle('GET', $request['callback_nonce'])[0]);
        $this->assertEmpty($this->retry_tasks());
    }

    /**
     * A retryable failure schedules the adhoc task with the first backoff step; the task finishes it.
     */
    public function test_retryable_failure_and_task(): void {
        $this->hub->queue('register', 502, ['error' => 'callback_unreachable', 'error_description' => 'x']);
        $before = time();
        $this->assertSame(state::FAILED_RETRYABLE, registrar::register());

        $status = registrar::status();
        $this->assertSame('callback_unreachable', $status['last_error']);
        $this->assertGreaterThanOrEqual($before + 60, $status['next_retry_at']);
        $this->assertLessThanOrEqual(time() + 60, $status['next_retry_at']);
        $tasks = $this->retry_tasks();
        $this->assertCount(1, $tasks);
        $this->assertSame($status['next_retry_at'], (int) reset($tasks)->get_next_run_time());

        // A second failure moves to the 5 minute step; a task is queued for that time.
        set_config('next_retry_at', time() - 1, 'local_ubionai');
        $this->hub->queue('register', 0, null);
        $this->assertSame(state::FAILED_RETRYABLE, registrar::run_scheduled());
        $this->assertSame('hub_unreachable', registrar::status()['last_error']);
        $due = registrar::status()['next_retry_at'];
        $this->assertGreaterThanOrEqual(time() + 299, $due);
        $times = array_map(static function ($task): int {
            return (int) $task->get_next_run_time();
        }, $this->retry_tasks());
        $this->assertContains($due, $times);

        // Not due yet: nothing is sent.
        $calls = count($this->hub->calls);
        (new \local_ubionai\task\retry_registration())->execute();
        $this->assertCount($calls, $this->hub->calls);

        set_config('next_retry_at', time() - 1, 'local_ubionai');
        $this->queue_success();
        $this->expectOutputRegex('/registration state/');
        (new \local_ubionai\task\retry_registration())->execute();
        $this->assertSame(state::REGISTERED, state::get());
        $this->assertFalse(get_config('local_ubionai', 'next_retry_at'));
        $this->assertFalse(get_config('local_ubionai', 'reg_attempts'));
    }

    /**
     * After 24 hours of failures the plugin stops and asks the administrator.
     */
    public function test_retry_gives_up_after_a_day(): void {
        set_config('reg_attempts', 9, 'local_ubionai');
        set_config('reg_first_failure_at', time() - DAYSECS, 'local_ubionai');
        $this->hub->queue('register', 500, ['error' => 'internal_error']);
        $this->assertSame(state::FAILED_ACTION_REQUIRED, registrar::register());
        $this->assertTrue(registrar::status()['retry_exhausted']);
        $this->assertNull(registrar::status()['next_retry_at']);

        // Manual retry starts a new series.
        $this->queue_success();
        $this->assertSame(state::REGISTERED, registrar::retry());
        $this->assertFalse(registrar::status()['retry_exhausted']);
    }

    /**
     * Hub answers that need the administrator and the resulting state.
     *
     * @return array
     */
    public static function final_failure_provider(): array {
        return [
            'mismatch' => [422, 'callback_mismatch', state::FAILED_ACTION_REQUIRED],
            'redirected' => [422, 'callback_redirected', state::FAILED_ACTION_REQUIRED],
            'operator' => [409, 'site_managed_by_operator', state::FAILED_ACTION_REQUIRED],
            'disabled by operator' => [403, 'host_blocked', state::FAILED_ACTION_REQUIRED],
            'upgrade' => [426, 'plugin_upgrade_required', state::FAILED_ACTION_REQUIRED],
            'private' => [403, 'private_address_not_supported', state::UNSUPPORTED_PRIVATE],
        ];
    }

    /**
     * Non-retryable answers stop without scheduling a retry.
     *
     * @param int $httpcode status
     * @param string $code error code
     * @param string $expected state
     *
     * @dataProvider final_failure_provider
     */
    #[DataProvider('final_failure_provider')]
    public function test_final_failure(int $httpcode, string $code, string $expected): void {
        $this->hub->queue('register', $httpcode, ['error' => $code]);
        $this->assertSame($expected, registrar::register());
        $this->assertSame($code, registrar::status()['last_error']);
        $this->assertEmpty($this->retry_tasks());
        $this->assertNull(secrets::next_version());
    }

    /**
     * A malformed success (wrong mac info, foreign MCP host) is refused and nothing is stored.
     */
    public function test_bad_issued_response(): void {
        $bad = registration_fake_hub::issued();
        $bad['mac']['info'] = 'oauth-mac|other|v1';
        $this->hub->queue('register', 201, $bad);
        $this->assertSame(state::FAILED_ACTION_REQUIRED, registrar::register());
        $this->assertNull(secrets::next_version());

        $bad = registration_fake_hub::issued();
        $bad['mcp_url'] = 'https://evil.example/mcp';
        $this->hub->queue('register', 201, $bad);
        $this->assertSame(state::FAILED_ACTION_REQUIRED, registrar::retry());
        $this->assertSame('bad_response', registrar::status()['last_error']);
        $this->assertEmpty($this->hub->calls_to('confirm'));
    }

    /**
     * Private, local or non-https site addresses are refused before any hub call.
     *
     * @return array
     */
    public static function private_provider(): array {
        return [
            'http' => ['http://lms.school.ac.kr', ['93.184.215.14']],
            'ip literal' => ['https://203.0.113.5/moodle', []],
            'decimal ip' => ['https://2130706433/', []],
            'localhost' => ['https://localhost/moodle', []],
            'single label' => ['https://moodle/', []],
            'internal suffix' => ['https://lms.school.internal', ['93.184.215.14']],
            'home.arpa' => ['https://lms.home.arpa', ['93.184.215.14']],
            'rfc1918' => ['https://lms.school.ac.kr', ['10.1.2.3']],
            'mixed answer' => ['https://lms.school.ac.kr', ['93.184.215.14', '192.168.0.10']],
            'cgnat' => ['https://lms.school.ac.kr', ['100.100.100.200']],
            'imds' => ['https://lms.school.ac.kr', ['169.254.169.254']],
            'azure wireserver' => ['https://lms.school.ac.kr', ['168.63.129.16']],
            'ula v6' => ['https://lms.school.ac.kr', ['fd00:ec2::254']],
            'mapped v6' => ['https://lms.school.ac.kr', ['::ffff:127.0.0.1']],
        ];
    }

    /**
     * Pre-check marks the site unsupported_private and sends nothing.
     *
     * @param string $wwwroot site address
     * @param string[] $ips DNS answer
     *
     * @dataProvider private_provider
     */
    #[DataProvider('private_provider')]
    public function test_private_precheck(string $wwwroot, array $ips): void {
        global $CFG;
        $CFG->wwwroot = $wwwroot;
        precheck::set_resolver_for_testing(static function (string $host) use ($ips): array {
            return $ips;
        });
        $this->assertSame(state::UNSUPPORTED_PRIVATE, registrar::register());
        $this->assertEmpty($this->hub->calls);
    }

    /**
     * Reissue: the current secret keeps working until the new one is confirmed.
     */
    public function test_rotate_keeps_current_until_confirmed(): void {
        $this->queue_success();
        $this->assertSame(state::REGISTERED, registrar::register());

        $this->hub->queue('rotate', 200, registration_fake_hub::issued(2, registration_fake_hub::SECRET2))
            ->queue('confirm', 503, ['error' => 'verifier_busy']);
        $this->assertSame(state::FAILED_RETRYABLE, registrar::rotate());
        $this->assertContains('Authorization: Bearer ' . registration_fake_hub::SECRET, $this->hub->calls_to('rotate')[0]['headers']);
        $this->assertSame(1, secrets::current_version());
        $this->assertSame(2, secrets::next_version());
        $this->assertSame(registration_fake_hub::SECRET, secrets::reveal('current'));
        $this->assertSame('confirm', get_config('local_ubionai', 'reg_retry_action'));
        // Both keys verify while the new one is pending.
        $this->assertNotNull(secrets::mac_key(1));
        $this->assertNotNull(secrets::mac_key(2));

        set_config('next_retry_at', time() - 1, 'local_ubionai');
        $this->hub->queue('confirm', 200, ['secret_version' => 2, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::run_scheduled());
        $this->assertSame(2, secrets::current_version());
        $this->assertNull(secrets::next_version());
        $this->assertSame(registration_fake_hub::SECRET2, secrets::reveal('current'));
        $this->assertNull(secrets::mac_key(1));
        // Only the initial registration called register; rotate is authenticated with the current secret.
        $this->assertCount(1, $this->hub->calls_to('register'), 'rotate needs no new callback');
        $this->assertCount(1, $this->hub->calls_to('rotate'));
    }

    /**
     * An expired pending secret on confirm leads to a new registration on the next retry.
     */
    public function test_confirm_expired_reregisters(): void {
        $this->hub->queue('register', 201, registration_fake_hub::issued())
            ->queue('confirm', 410, ['error' => 'pending_secret_expired']);
        $this->assertSame(state::FAILED_RETRYABLE, registrar::register());
        $this->assertSame('register', get_config('local_ubionai', 'reg_retry_action'));

        set_config('next_retry_at', time() - 1, 'local_ubionai');
        $this->hub->queue('register', 200, registration_fake_hub::issued(2, registration_fake_hub::SECRET2))
            ->queue('confirm', 200, ['secret_version' => 2, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::run_scheduled());
        $this->assertSame(2, secrets::current_version());
    }

    /**
     * Withdrawing consent deregisters and forgets the secrets; the flag off means no hub call.
     */
    public function test_withdraw_and_flag(): void {
        $this->queue_success();
        registrar::register();
        $this->hub->queue('deregister', 200, ['status' => 'disabled']);
        set_config('consent', 'declined', 'local_ubionai');
        setup::consent_updated('s_local_ubionai_consent');
        $this->assertSame(state::DEREGISTERED, state::get());
        $this->assertCount(1, $this->hub->calls_to('deregister'));
        $this->assertNull(secrets::current_version());
        $this->assertFalse(get_config('local_ubionai', 'site_secret_current'));

        $calls = count($this->hub->calls);
        set_config('self_registration', 0, 'local_ubionai');
        set_config('consent', 'accepted', 'local_ubionai');
        setup::consent_updated('s_local_ubionai_consent');
        $this->assertSame(state::CONSENTED, state::get());
        $this->assertCount($calls, $this->hub->calls);
    }

    /**
     * An empty hub_url uses the default hub; the setting overrides it.
     */
    public function test_default_hub_is_overridable(): void {
        $this->assertSame('https://mcp.ubion.global', registrar::DEFAULT_HUB);
        set_config('hub_url', '', 'local_ubionai');
        $this->assertSame('https://mcp.ubion.global', registrar::hub_base());
        set_config('hub_url', 'https://hub.school.example/', 'local_ubionai');
        $this->assertSame('https://hub.school.example', registrar::hub_base());
        $this->assertStringContainsString('https://mcp.ubion.global', get_string('hub_url_desc', 'local_ubionai'));
        $this->assertStringNotContainsString('provisional', get_string('hub_url_desc', 'local_ubionai'));
    }

    /**
     * Re-registration sends the same site identity; the hub's grants_kept answer is recorded and shown.
     */
    public function test_reregistration_reports_grants_kept(): void {
        $this->queue_success(201);
        $this->assertSame(state::REGISTERED, registrar::register());
        $this->assertNull(registrar::status()['grants_kept'], 'a new tenant has no grants to keep');

        // Same Moodle (same site_identifier_hash): the hub keeps the teacher grants.
        $same = registration_fake_hub::issued(2, registration_fake_hub::SECRET2) + ['grants_kept' => true];
        $this->hub->queue('register', 200, $same)
            ->queue('confirm', 200, ['secret_version' => 2, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::register());
        $this->assertTrue(registrar::status()['grants_kept']);

        // The hub decided the identity differs: every grant was revoked.
        $other = registration_fake_hub::issued(3) + ['grants_kept' => false];
        $this->hub->queue('register', 200, $other)
            ->queue('confirm', 200, ['secret_version' => 3, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::register());
        $this->assertFalse(registrar::status()['grants_kept']);

        $requests = array_map(static function (array $call): array {
            return json_decode($call['body'], true);
        }, $this->hub->calls_to('register'));
        $this->assertCount(3, $requests);
        foreach ($requests as $request) {
            $this->assertSame(registrar::site_identifier_hash(), $request['site_identifier_hash']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $request['site_identifier_hash']);
        }

        // Rotate does not touch grants: the last re-registration result stays visible.
        $this->hub->queue('rotate', 200, registration_fake_hub::issued(4, registration_fake_hub::SECRET2))
            ->queue('confirm', 200, ['secret_version' => 4, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::rotate());
        $this->assertFalse(registrar::status()['grants_kept']);

        // Withdrawal forgets it.
        $this->hub->queue('deregister', 200, ['status' => 'disabled']);
        set_config('consent', 'declined', 'local_ubionai');
        setup::consent_updated('s_local_ubionai_consent');
        $this->assertNull(registrar::status()['grants_kept']);
    }

    /**
     * The hub's provisional limits and tenant status are stored and shown as reported.
     */
    public function test_limits_and_pending_tenant_from_hub(): void {
        $issued = registration_fake_hub::issued();
        $issued['tenant']['status'] = 'pending';
        $issued['tenant']['limits'] = ['max_teachers' => 7, 'daily_requests' => 900, 'concurrency' => 1,
            'llm_proxy' => false, 'future_limit' => 3, 'Bad-Key' => 1, 'nested' => ['x' => 1]];
        $this->hub->queue('register', 201, $issued)
            ->queue('confirm', 200, ['secret_version' => 1, 'secret_state' => 'current']);
        $this->assertSame(state::REGISTERED, registrar::register());

        $status = registrar::status();
        $this->assertSame('limited', $status['tier']);
        $this->assertSame('pending', $status['tenant_status']);
        $this->assertSame(['max_teachers' => 7, 'daily_requests' => 900, 'concurrency' => 1, 'llm_proxy' => false,
            'future_limit' => 3], $status['limits']);

        $this->assertSame([
            get_string('reg_limit_max_teachers', 'local_ubionai', '7'),
            get_string('reg_limit_daily_requests', 'local_ubionai', '900'),
            get_string('reg_limit_concurrency', 'local_ubionai', '1'),
            get_string('reg_limit_llm_proxy', 'local_ubionai', get_string('no')),
            'future_limit: 3',
        ], registrar::describe_limits($status['limits']));
        $this->assertSame('Teachers: 7', registrar::describe_limits(['max_teachers' => 7])[0]);
        $this->assertSame('Requests per day: 900', registrar::describe_limits(['daily_requests' => 900])[0]);
    }

    /**
     * Registration never creates web service tokens or touches the legacy svc_ubionai provisioning.
     */
    public function test_no_tokens_minted(): void {
        global $DB;
        $tokens = $DB->count_records('external_tokens');
        $this->queue_success();
        setup::consent_updated('s_local_ubionai_consent');
        $this->assertSame(state::REGISTERED, state::get());
        $this->assertSame($tokens, $DB->count_records('external_tokens'));
        $this->assertFalse($DB->record_exists('user', ['username' => \local_ubionai\local\provisioner::USERNAME]));
        foreach ($this->hub->calls as $call) {
            $this->assertStringNotContainsString('wstoken', (string) $call['body']);
        }
    }
}
