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
 * HTTP calls to the hub self-registration API (contract §1.1).
 *
 * Uses Moodle's \curl, which applies $CFG->proxyhost / proxybypass and the curl security
 * helper. https only, no redirects. No web service token is ever sent; the only credential is the
 * hub-issued site secret as a Bearer header on confirm / rotate / status / deregister.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hub_api {
    /** Request timeout (the hub may take up to 15 s including its 5 s callback). */
    public const TIMEOUT = 25;
    /** Connect timeout. */
    public const CONNECT_TIMEOUT = 10;

    /** @var string hub base URL without trailing slash */
    protected $base;

    /**
     * Constructor.
     *
     * @param string $base hub base URL (https)
     */
    public function __construct(string $base) {
        $this->base = rtrim($base, '/');
    }

    /**
     * POST /api/v1/sites/register.
     *
     * @param array $payload request body
     * @return array{httpcode: int, data: array|null, errno: int}
     */
    public function register(array $payload): array {
        return $this->call('POST', '/api/v1/sites/register', $payload, null);
    }

    /**
     * POST /api/v1/sites/{site_key}/confirm.
     *
     * @param string $sitekey site key
     * @param int $version secret version to confirm
     * @param string $secret the new (pending) secret
     * @return array{httpcode: int, data: array|null, errno: int}
     */
    public function confirm(string $sitekey, int $version, string $secret): array {
        return $this->call(
            'POST',
            '/api/v1/sites/' . rawurlencode($sitekey) . '/confirm',
            ['secret_version' => $version],
            $secret
        );
    }

    /**
     * POST /api/v1/sites/{site_key}/rotate.
     *
     * @param string $sitekey site key
     * @param string $secret the current secret
     * @return array{httpcode: int, data: array|null, errno: int}
     */
    public function rotate(string $sitekey, string $secret): array {
        return $this->call('POST', '/api/v1/sites/' . rawurlencode($sitekey) . '/rotate', new \stdClass(), $secret);
    }

    /**
     * GET /api/v1/sites/{site_key}/status.
     *
     * @param string $sitekey site key
     * @param string $secret the current secret
     * @return array{httpcode: int, data: array|null, errno: int}
     */
    public function status(string $sitekey, string $secret): array {
        return $this->call('GET', '/api/v1/sites/' . rawurlencode($sitekey) . '/status', null, $secret);
    }

    /**
     * POST /api/v1/sites/{site_key}/deregister.
     *
     * @param string $sitekey site key
     * @param string $secret the current secret
     * @return array{httpcode: int, data: array|null, errno: int}
     */
    public function deregister(string $sitekey, string $secret): array {
        return $this->call('POST', '/api/v1/sites/' . rawurlencode($sitekey) . '/deregister', new \stdClass(), $secret);
    }

    /**
     * Base URL.
     *
     * @return string
     */
    public function base(): string {
        return $this->base;
    }

    /**
     * Send a JSON request and decode the JSON answer.
     *
     * @param string $method GET|POST
     * @param string $path API path
     * @param array|\stdClass|null $body JSON body
     * @param string|null $bearer site secret for the Authorization header
     * @return array{httpcode: int, data: array|null, errno: int}
     */
    protected function call(string $method, string $path, $body, ?string $bearer): array {
        $headers = ['Accept: application/json', 'User-Agent: ' . self::user_agent()];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        $json = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $raw = $this->send($method, $this->base . $path, $json, $headers);
        unset($headers, $bearer);
        $data = null;
        if ($raw['body'] !== '') {
            $decoded = json_decode($raw['body'], true);
            $data = is_array($decoded) ? $decoded : null;
        }
        return ['httpcode' => $raw['httpcode'], 'data' => $data, 'errno' => $raw['errno']];
    }

    /**
     * Transport. Overridden in unit tests.
     *
     * @param string $method GET|POST
     * @param string $url full URL
     * @param string|null $body JSON body
     * @param string[] $headers request headers
     * @return array{httpcode: int, body: string, errno: int}
     */
    protected function send(string $method, string $url, ?string $body, array $headers): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
        ]);
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }
        $response = $method === 'POST' ? $curl->post($url, (string) $body) : $curl->get($url);
        $info = $curl->get_info();
        $errno = (int) $curl->get_errno();
        // Hosts blocked by $CFG->curlsecurityblockedhosts come back as an error string without errno.
        if ($errno === 0 && (int) ($info['http_code'] ?? 0) === 0) {
            $errno = -1;
        }
        return [
            'httpcode' => $errno === 0 ? (int) ($info['http_code'] ?? 0) : 0,
            'body' => $errno === 0 ? (string) $response : '',
            'errno' => $errno,
        ];
    }

    /**
     * User-Agent: local_ubionai/<release> (Moodle <release>).
     *
     * @return string
     */
    public static function user_agent(): string {
        global $CFG;
        $release = registrar::plugin_info()['release'];
        $moodle = preg_replace('/[^\w .+()-]/', '', (string) ($CFG->release ?? ''));
        return 'local_ubionai/' . $release . ' (Moodle ' . $moodle . ')';
    }
}
