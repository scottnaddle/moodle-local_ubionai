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
 * Fake hub for the self-registration tests (no network).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ubionai;

use local_ubionai\registration\callback;
use local_ubionai\registration\hub_api;

defined('MOODLE_INTERNAL') || die();

/**
 * Fake hub: queued answers per endpoint; on register it calls the site callback like the hub does.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registration_fake_hub extends hub_api {
    /** Hub base URL used by the tests. */
    public const BASE = 'https://hub.example.test';
    /** Fake site secret (test only). */
    public const SECRET = 'phpunit-fake-site-secret-0123456789abcdefgh';
    /** Second fake site secret (test only). */
    public const SECRET2 = 'phpunit-fake-site-secret-second-0123456789a';
    /** Site key the fake hub assigns. */
    public const SITE_KEY = 'www-example-com-moodle-3f9a1c';

    /** @var array<int, array{method: string, path: string, body: ?string, headers: string[]}> */
    public $calls = [];
    /** @var array<string, array<int, array{0: int, 1: ?array}>> endpoint => queued [httpcode, body] */
    public $responses = [];
    /** @var array|null [status, body] the site callback returned during register */
    public $callbackresult = null;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(self::BASE);
    }

    /**
     * Queue an answer.
     *
     * @param string $endpoint register|confirm|rotate|status|deregister
     * @param int $httpcode status (0 = transport failure)
     * @param array|null $body JSON body
     * @return self
     */
    public function queue(string $endpoint, int $httpcode, ?array $body): self {
        $this->responses[$endpoint][] = [$httpcode, $body];
        return $this;
    }

    /**
     * A successful registration / rotate result.
     *
     * @param int $version secret version
     * @param string $secret secret to issue
     * @return array
     */
    public static function issued(int $version = 1, string $secret = self::SECRET): array {
        $key = self::SITE_KEY;
        return [
            'schema' => 'ubionai.site-registration-result/v1',
            'site_key' => $key,
            'site_secret' => $secret,
            'secret_version' => $version,
            'secret_state' => 'pending_confirm',
            'confirm_url' => self::BASE . "/api/v1/sites/{$key}/confirm",
            'tenant' => [
                'status' => 'active', 'tier' => 'limited',
                'limits' => ['max_teachers' => 5, 'daily_tool_calls' => 300, 'concurrency' => 1, 'llm_proxy' => false],
            ],
            'mcp_url' => self::BASE . '/m/www.example.com/moodle/mcp',
            'legacy_mcp_url' => self::BASE . "/t/{$key}/mcp",
            'oauth' => [
                'allowed_callbacks' => [self::BASE . '/m/www.example.com/moodle/oauth/callback',
                    self::BASE . "/t/{$key}/oauth/callback"],
                'issuers' => [self::BASE . '/m/www.example.com/moodle', self::BASE . "/t/{$key}"],
            ],
            'auth_mode' => 'shared_secret',
            'mac' => ['alg' => 'HMAC-SHA256', 'kdf' => 'HKDF-SHA256', 'salt' => 'ubionai-site-v1',
                'info' => "oauth-mac|{$key}|v{$version}"],
            'egress_ips' => ['203.0.113.10'],
            'hub_keys_url' => self::BASE . '/.well-known/ubionai-hub-keys.json',
        ];
    }

    /**
     * Record the call and answer from the queue.
     *
     * @param string $method GET|POST
     * @param string $url full URL
     * @param string|null $body JSON body
     * @param string[] $headers request headers
     * @return array{httpcode: int, body: string, errno: int}
     */
    protected function send(string $method, string $url, ?string $body, array $headers): array {
        $path = substr($url, strlen($this->base));
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];
        $endpoint = preg_replace('#\A/api/v1/sites/(?:[^/]+/)?#', '', $path);
        if ($endpoint === 'register') {
            $request = json_decode((string) $body, true);
            $this->callbackresult = callback::handle('GET', (string) ($request['callback_nonce'] ?? ''));
        }
        $next = array_shift($this->responses[$endpoint]) ?? [500, ['error' => 'internal_error']];
        return [
            'httpcode' => $next[0],
            'body' => $next[1] === null ? '' : (string) json_encode($next[1]),
            'errno' => $next[0] === 0 ? 7 : 0,
        ];
    }

    /**
     * Calls to one endpoint.
     *
     * @param string $endpoint register|confirm|rotate|status|deregister
     * @return array
     */
    public function calls_to(string $endpoint): array {
        return array_values(array_filter($this->calls, static function (array $call) use ($endpoint): bool {
            return preg_replace('#\A/api/v1/sites/(?:[^/]+/)?#', '', $call['path']) === $endpoint;
        }));
    }
}
