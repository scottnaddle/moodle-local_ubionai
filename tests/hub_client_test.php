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

namespace local_ubionai;

defined('MOODLE_INTERNAL') || die();

use local_ubionai\local\hub_client;
use local_ubionai\local\provisioner;

/**
 * Tests for the hub registration client.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ubionai\local\hub_client
 */
final class hub_client_test extends \advanced_testcase {
    /** @var string A syntactically valid registration code. */
    private const CODE = 'ABCDEFGHIJKLMNOPQRSTUVWX';

    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('hub_url', 'https://hub.example.test', 'local_ubionai');
        hub_client_test_transport::$calls = [];
        hub_client_test_transport::$response = ['body' => '', 'errno' => 0, 'httpcode' => 200];
    }

    /**
     * Invalid hub URLs are rejected before any request is made.
     */
    public function test_invalid_hub_url(): void {
        $invalid = [
            '',
            'http://hub.example.test',
            'https://user:pass@hub.example.test',
            'https://hub.example.test/?query=1',
            'https://hub.example.test/#fragment',
            'ftp://hub.example.test',
        ];

        foreach ($invalid as $huburl) {
            set_config('hub_url', $huburl, 'local_ubionai');
            hub_client_test_transport::$calls = [];

            $result = hub_client_test_transport::register(self::CODE);

            $this->assertFalse($result['ok']);
            $this->assertSame('invalid_hub_url', $result['status']);
            $this->assertNull($result['siteid']);
            $this->assertCount(0, hub_client_test_transport::$calls);
        }
    }

    /**
     * Malformed registration codes are rejected before any request is made.
     */
    public function test_invalid_code(): void {
        $invalid = ['', 'short', str_repeat('a', 129), 'code with spaces', 'has.dots.in.it'];

        foreach ($invalid as $code) {
            hub_client_test_transport::$calls = [];

            $result = hub_client_test_transport::register($code);

            $this->assertFalse($result['ok']);
            $this->assertSame('invalid_code', $result['status']);
            $this->assertNull($result['siteid']);
            $this->assertCount(0, hub_client_test_transport::$calls);
        }
    }

    /**
     * The request body contains exactly the three contract keys.
     */
    public function test_request_body(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        provisioner::provision();
        hub_client_test_transport::$response = self::success_response();

        $result = hub_client_test_transport::register(self::CODE);
        $this->assertTrue($result['ok']);

        $this->assertCount(1, hub_client_test_transport::$calls);
        $this->assertSame('https://hub.example.test/register', hub_client_test_transport::$calls[0]['endpoint']);

        $body = json_decode(hub_client_test_transport::$calls[0]['body'], true);
        $this->assertIsArray($body);

        $keys = array_keys($body);
        $expected = ['registration_code', 'site_url', 'wstoken'];
        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertSame(self::CODE, $body['registration_code']);
        $this->assertSame($CFG->wwwroot, $body['site_url']);

        $service = $DB->get_record('external_services', ['shortname' => provisioner::SERVICE], '*', MUST_EXIST);
        $token = $DB->get_field('external_tokens', 'token', ['externalserviceid' => $service->id], IGNORE_MULTIPLE);
        $this->assertIsString($token);
        $this->assertSame($token, $body['wstoken']);
    }

    /**
     * Hub HTTP statuses map to the documented plugin status keys.
     */
    public function test_status_mapping(): void {
        $this->resetAfterTest();
        provisioner::provision();

        $cases = [
            200 => 'registered',
            400 => 'registration_failed',
            403 => 'code_rejected',
            409 => 'already_registered',
            413 => 'protocol_error',
            415 => 'protocol_error',
            500 => 'bad_response',
            404 => 'bad_response',
        ];

        foreach ($cases as $httpcode => $expected) {
            if ($httpcode === 200) {
                hub_client_test_transport::$response = self::success_response();
            } else {
                hub_client_test_transport::$response = [
                    'body' => '{}',
                    'errno' => 0,
                    'httpcode' => $httpcode,
                ];
            }

            $result = hub_client_test_transport::register(self::CODE);
            $this->assertSame($expected, $result['status'], 'HTTP ' . $httpcode);
            $this->assertSame($expected === 'registered', $result['ok']);
        }
    }

    /**
     * A curl-level failure is reported as hub_unreachable.
     */
    public function test_curl_error(): void {
        $this->resetAfterTest();
        provisioner::provision();
        hub_client_test_transport::$response = ['body' => '', 'errno' => 7, 'httpcode' => 0];

        $result = hub_client_test_transport::register(self::CODE);

        $this->assertFalse($result['ok']);
        $this->assertSame('hub_unreachable', $result['status']);
        $this->assertNull($result['siteid']);
    }

    /**
     * Malformed JSON on a 200 response is rejected without config writes.
     */
    public function test_malformed_success_json(): void {
        $this->resetAfterTest();
        provisioner::provision();
        hub_client_test_transport::$response = ['body' => 'not json', 'errno' => 0, 'httpcode' => 200];

        $result = hub_client_test_transport::register(self::CODE);

        $this->assertFalse($result['ok']);
        $this->assertSame('bad_response', $result['status']);
        $this->assertFalse(get_config('local_ubionai', 'hub_siteid'));
    }

    /**
     * A valid success response stores all config, with the bearer encrypted.
     */
    public function test_success_stores_config(): void {
        $this->resetAfterTest();
        provisioner::provision();
        hub_client_test_transport::$response = self::success_response();

        $result = hub_client_test_transport::register(self::CODE);

        $this->assertTrue($result['ok']);
        $this->assertSame('registered', $result['status']);
        $this->assertSame('test-local', $result['siteid']);

        $this->assertSame('test-local', get_config('local_ubionai', 'hub_siteid'));
        $this->assertSame('https://hub.example.test/t/test-local/mcp',
            get_config('local_ubionai', 'hub_mcp_endpoint'));
        $this->assertSame('203.0.113.10', get_config('local_ubionai', 'hub_egress_ip'));
        $this->assertGreaterThan(0, (int) get_config('local_ubionai', 'hub_registered_at'));

        $stored = (string) get_config('local_ubionai', 'hub_mcp_bearer');
        $this->assertNotSame('', $stored);
        $this->assertNotSame('bearer-secret-value', $stored);
        $this->assertSame('bearer-secret-value', \core\encryption::decrypt($stored));
    }

    /**
     * Invalid response payloads are rejected without config writes.
     */
    public function test_invalid_response_payload(): void {
        $this->resetAfterTest();
        provisioner::provision();

        $invalid = [
            ['site_id' => 'TEST', 'mcp_endpoint' => 'https://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer'],
            ['site_id' => 'test-local', 'mcp_endpoint' => 'https://evil.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer'],
            ['site_id' => 'test-local', 'mcp_endpoint' => 'http://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer'],
            ['site_id' => 'test-local', 'mcp_endpoint' => 'https://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => ''],
            ['site_id' => 'test-local', 'mcp_endpoint' => 'https://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer', 'egress_ip' => 123],
        ];

        foreach ($invalid as $payload) {
            unset_config('hub_siteid', 'local_ubionai');
            unset_config('hub_mcp_endpoint', 'local_ubionai');
            unset_config('hub_egress_ip', 'local_ubionai');
            unset_config('hub_registered_at', 'local_ubionai');
            unset_config('hub_mcp_bearer', 'local_ubionai');

            hub_client_test_transport::$response = [
                'body' => json_encode($payload),
                'errno' => 0,
                'httpcode' => 200,
            ];

            $result = hub_client_test_transport::register(self::CODE);

            $this->assertFalse($result['ok']);
            $this->assertSame('bad_response', $result['status']);
            $this->assertFalse(get_config('local_ubionai', 'hub_siteid'));
            $this->assertFalse(get_config('local_ubionai', 'hub_mcp_bearer'));
        }
    }

    /**
     * The registration code is never persisted in config or logs.
     */
    public function test_code_is_not_persisted(): void {
        global $DB;
        $this->resetAfterTest();
        provisioner::provision();
        hub_client_test_transport::$response = self::success_response();

        hub_client_test_transport::register(self::CODE);

        $needle = '%' . $DB->sql_like_escape(self::CODE) . '%';
        $this->assertFalse($DB->record_exists_select('config_plugins',
            $DB->sql_like('value', ':needle'), ['needle' => $needle]));

        if ($DB->get_manager()->table_exists('logstore_standard_log')) {
            $this->assertFalse($DB->record_exists_select('logstore_standard_log',
                $DB->sql_like('other', ':needle'), ['needle' => $needle]));
        }
    }

    /**
     * A valid hub success response body.
     *
     * @return array{body: string, errno: int, httpcode: int}
     */
    private static function success_response(): array {
        return [
            'body' => json_encode([
                'site_id' => 'test-local',
                'mcp_endpoint' => 'https://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer-secret-value',
                'egress_ip' => '203.0.113.10',
            ]),
            'errno' => 0,
            'httpcode' => 200,
        ];
    }
}

/**
 * Transport double for hub_client tests.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hub_client_test_transport extends hub_client {
    /** @var array<int, array{endpoint: string, body: string}> Recorded requests. */
    public static $calls = [];

    /** @var array{body: string, errno: int, httpcode: int} Next response. */
    public static $response = ['body' => '', 'errno' => 0, 'httpcode' => 200];

    /**
     * Record the request and return the configured response.
     *
     * @param string $endpoint the registration endpoint
     * @param string $body the JSON request body
     * @return array{body: string, errno: int, httpcode: int}
     */
    protected static function post_json(string $endpoint, string $body): array {
        self::$calls[] = ['endpoint' => $endpoint, 'body' => $body];

        return self::$response;
    }
}
