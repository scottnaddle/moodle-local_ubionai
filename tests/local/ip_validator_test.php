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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the IP / CIDR list validator.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ubionai\local\ip_validator
 */
final class ip_validator_test extends \advanced_testcase {
    /**
     * Valid values and their normalised forms.
     *
     * @return array
     */
    public static function valid_provider(): array {
        return [
            'ipv4' => ['203.0.113.10', '203.0.113.10'],
            'ipv6 compressed' => ['2001:DB8:0:0:0:0:0:1', '2001:db8::1'],
            'ipv4-mapped' => ['::ffff:192.0.2.1', inet_ntop(inet_pton('::ffff:192.0.2.1'))],
            'cidr' => ['2001:DB8::/32', '2001:db8::/32'],
            'whitespace around entries' => [' 2001:db8::/32 , 203.0.113.10 ', '2001:db8::/32,203.0.113.10'],
            'duplicates' => ['203.0.113.10, 203.0.113.10, 2001:db8::/32,203.0.113.10',
                '203.0.113.10,2001:db8::/32'],
            'order kept' => ['8.8.8.8, 1.1.1.1', '8.8.8.8,1.1.1.1'],
            'empty' => ['', ''],
            'whitespace only' => ['   ', ''],
            'zero network v4' => ['0.0.0.0/0', '0.0.0.0/0'],
            'zero network v6' => ['::/0', '::/0'],
            'full prefix' => ['203.0.113.10/32', '203.0.113.10/32'],
        ];
    }

    /**
     * Valid values normalise as documented.
     *
     * @dataProvider valid_provider
     * @param string $value raw value
     * @param string $expected normalised value
     */
    public function test_valid_values(string $value, string $expected): void {
        $this->assertSame($expected, ip_validator::normalise($value));
    }

    /**
     * Invalid values and a fragment expected in the error message.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'comment' => ['1.2.3.4 # office', '1.2.3.4'],
            'trailing comma' => ['1.2.3.4,', 'empty entry'],
            'leading comma' => [',1.2.3.4', 'empty entry'],
            'double comma' => ['1.2.3.4,,5.6.7.8', 'empty entry'],
            'semicolon' => ['1.2.3.4;5.6.7.8', '1.2.3.4;5.6.7.8'],
            'inner whitespace' => ['1.2.3.4 5.6.7.8', '1.2.3.4 5.6.7.8'],
            'octet out of range' => ['999.1.1.1', '999.1.1.1'],
            'host bits' => ['10.0.0.1/24', '10.0.0.1/24'],
            'zone id' => ['fe80::1%eth0', 'fe80::1%eth0'],
            'hostname' => ['host.example', 'host.example'],
            'range' => ['1.2.3.4-9', '1.2.3.4-9'],
            'partial' => ['10.1.', '10.1.'],
            'v4 prefix too large' => ['1.2.3.4/33', '1.2.3.4/33'],
            'v6 prefix too large' => ['2001:db8::/129', '2001:db8::/129'],
            'two prefixes' => ['1.2.3.4/24/8', '1.2.3.4/24/8'],
            'empty prefix' => ['1.2.3.4/', '1.2.3.4/'],
            'empty base' => ['/24', '/24'],
            'non-numeric prefix' => ['1.2.3.4/x', '1.2.3.4/x'],
            'hex octet' => ['0x7f.0.0.1', '0x7f.0.0.1'],
        ];
    }

    /**
     * Invalid values raise an invalid_parameter_exception naming the entry.
     *
     * @dataProvider invalid_provider
     * @param string $value raw value
     * @param string $fragment fragment expected in the message
     */
    public function test_invalid_values(string $value, string $fragment): void {
        try {
            ip_validator::normalise($value);
            $this->fail('Expected an invalid_parameter_exception for ' . $value);
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /**
     * A normalised list longer than 255 characters is rejected.
     */
    public function test_too_long(): void {
        $entries = [];
        for ($i = 1; $i <= 22; $i++) {
            $entries[] = '2001:db8::' . dechex($i);
        }
        $value = implode(',', $entries);

        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('255');
        ip_validator::normalise($value);
    }

    /**
     * A bad egress_ip response is rejected before any hub config is written.
     */
    public function test_register_rejects_bad_egress_ip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        provisioner::provision();
        set_config('hub_url', 'https://hub.example.test', 'local_ubionai');

        ip_validator_hub_transport::$response = [
            'body' => json_encode([
                'site_id' => 'test-local',
                'mcp_endpoint' => 'https://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer-secret-value',
                'egress_ip' => '1.2.3.4 # office',
            ]),
            'errno' => 0,
            'httpcode' => 200,
        ];

        $result = ip_validator_hub_transport::register('ABCDEFGHIJKLMNOPQRSTUVWX');

        $this->assertFalse($result['ok']);
        $this->assertSame('bad_response', $result['status']);
        $this->assertNull($result['siteid']);
        $this->assertFalse(get_config('local_ubionai', 'hub_siteid'));
        $this->assertFalse(get_config('local_ubionai', 'hub_mcp_endpoint'));
        $this->assertFalse(get_config('local_ubionai', 'hub_egress_ip'));
        $this->assertFalse(get_config('local_ubionai', 'hub_registered_at'));
        $this->assertFalse(get_config('local_ubionai', 'hub_mcp_bearer'));
    }

    /**
     * A valid egress_ip response is stored in normalised form.
     */
    public function test_register_normalises_egress_ip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        provisioner::provision();
        set_config('hub_url', 'https://hub.example.test', 'local_ubionai');

        ip_validator_hub_transport::$response = [
            'body' => json_encode([
                'site_id' => 'test-local',
                'mcp_endpoint' => 'https://hub.example.test/t/test-local/mcp',
                'mcp_bearer' => 'bearer-secret-value',
                'egress_ip' => ' 203.0.113.10 , 2001:DB8::/32 ',
            ]),
            'errno' => 0,
            'httpcode' => 200,
        ];

        $result = ip_validator_hub_transport::register('ABCDEFGHIJKLMNOPQRSTUVWX');

        $this->assertTrue($result['ok']);
        $this->assertSame('203.0.113.10,2001:db8::/32', get_config('local_ubionai', 'hub_egress_ip'));
    }
}

/**
 * Transport double for hub_client registration tests.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ip_validator_hub_transport extends hub_client {
    /** @var array{body: string, errno: int, httpcode: int} Next response. */
    public static $response = ['body' => '', 'errno' => 0, 'httpcode' => 200];

    /**
     * Return the configured response without making a request.
     *
     * @param string $endpoint the registration endpoint
     * @param string $body the JSON request body
     * @return array{body: string, errno: int, httpcode: int}
     */
    protected static function post_json(string $endpoint, string $body): array {
        return self::$response;
    }
}
