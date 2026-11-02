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

/**
 * Client for the UBION AI hub registration endpoint.
 *
 * The one-time registration code and the web service token are never logged, returned, written
 * to plugin config (except the encrypted MCP bearer), or placed in URLs. Only status keys and
 * non-secret metadata leave this class.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hub_client {
    /**
     * Whether a hub URL has been configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return trim((string) get_config('local_ubionai', 'hub_url')) !== '';
    }

    /**
     * Whether the site has completed registration with the hub.
     *
     * @return bool
     */
    public static function is_registered(): bool {
        return trim((string) get_config('local_ubionai', 'hub_siteid')) !== '';
    }

    /**
     * Register this site with the hub.
     *
     * @param string $regcode one-time registration code from the hub admin
     * @return array{ok: bool, status: string, siteid: ?string} status is one of the
     *         hub_status_* keys in lang/en/local_ubionai.php
     */
    public static function register(string $regcode): array {
        global $CFG;

        $endpoint = self::endpoint();
        if ($endpoint === null) {
            return ['ok' => false, 'status' => 'invalid_hub_url', 'siteid' => null];
        }

        $code = trim($regcode);
        unset($regcode);
        if (preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $code) !== 1) {
            return ['ok' => false, 'status' => 'invalid_code', 'siteid' => null];
        }

        $siteurl = (string) $CFG->wwwroot;
        $body = provisioner::with_token(static function (string $token) use ($code, $siteurl): string {
            return (string) json_encode([
                'registration_code' => $code,
                'site_url' => $siteurl,
                'wstoken' => $token,
            ]);
        });

        $response = static::post_json($endpoint, $body);
        unset($body, $code);

        if ($response['errno'] !== 0) {
            return ['ok' => false, 'status' => 'hub_unreachable', 'siteid' => null];
        }

        $httpcode = $response['httpcode'];
        if ($httpcode === 400) {
            return ['ok' => false, 'status' => 'registration_failed', 'siteid' => null];
        }
        if ($httpcode === 403) {
            return ['ok' => false, 'status' => 'code_rejected', 'siteid' => null];
        }
        if ($httpcode === 409) {
            return ['ok' => false, 'status' => 'already_registered', 'siteid' => null];
        }
        if ($httpcode === 413 || $httpcode === 415) {
            return ['ok' => false, 'status' => 'protocol_error', 'siteid' => null];
        }
        if ($httpcode !== 200) {
            return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
        }

        $data = json_decode($response['body']);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
            return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
        }

        if (
            !isset($data->site_id) || !is_string($data->site_id)
                || preg_match('/\A[a-z0-9][a-z0-9-]{1,62}\z/D', $data->site_id) !== 1
        ) {
            return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
        }
        if (
            !isset($data->mcp_endpoint) || !is_string($data->mcp_endpoint)
                || !self::endpoint_matches_hub($data->mcp_endpoint, $endpoint)
        ) {
            return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
        }
        if (!isset($data->mcp_bearer) || !is_string($data->mcp_bearer) || $data->mcp_bearer === '') {
            return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
        }

        $egressip = '';
        if (property_exists($data, 'egress_ip') && $data->egress_ip !== null) {
            if (!is_string($data->egress_ip)) {
                return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
            }
            try {
                $egressip = ip_validator::normalise($data->egress_ip);
            } catch (\invalid_parameter_exception $e) {
                return ['ok' => false, 'status' => 'bad_response', 'siteid' => null];
            }
        }

        $bearer = \core\encryption::encrypt($data->mcp_bearer);
        set_config('hub_siteid', $data->site_id, 'local_ubionai');
        set_config('hub_mcp_endpoint', $data->mcp_endpoint, 'local_ubionai');
        set_config('hub_egress_ip', $egressip, 'local_ubionai');
        set_config('hub_registered_at', time(), 'local_ubionai');
        set_config('hub_mcp_bearer', $bearer, 'local_ubionai');

        unset($bearer, $data->mcp_bearer);

        return ['ok' => true, 'status' => 'registered', 'siteid' => $data->site_id];
    }

    /**
     * Build the registration endpoint from the configured hub URL.
     *
     * @return string|null the endpoint, or null when the hub URL is missing or invalid
     */
    private static function endpoint(): ?string {
        $huburl = trim((string) get_config('local_ubionai', 'hub_url'));
        if ($huburl === '') {
            return null;
        }

        $parts = parse_url($huburl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }
        if (
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
                || isset($parts['fragment'])
        ) {
            return null;
        }

        return rtrim($huburl, '/') . '/register';
    }

    /**
     * Whether an MCP endpoint is an https URL on the same host as the hub.
     *
     * @param string $mcpurl the endpoint returned by the hub
     * @param string $endpoint the registration endpoint
     * @return bool
     */
    private static function endpoint_matches_hub(string $mcpurl, string $endpoint): bool {
        $mcpparts = parse_url($mcpurl);
        $hubparts = parse_url($endpoint);
        if ($mcpparts === false || $hubparts === false) {
            return false;
        }
        if (empty($mcpparts['scheme']) || strtolower($mcpparts['scheme']) !== 'https') {
            return false;
        }
        if (empty($mcpparts['host']) || empty($hubparts['host'])) {
            return false;
        }

        return strtolower($mcpparts['host']) === strtolower($hubparts['host']);
    }

    /**
     * POST a JSON body to the hub.
     *
     * @param string $endpoint the registration endpoint
     * @param string $body the JSON request body
     * @return array{body: string, errno: int, httpcode: int}
     */
    protected static function post_json(string $endpoint, string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        $curl->setHeader('Content-Type: application/json');

        $response = $curl->post($endpoint, $body);
        $info = $curl->get_info();

        return [
            'body' => (string) $response,
            'errno' => (int) $curl->get_errno(),
            'httpcode' => (int) ($info['http_code'] ?? 0),
        ];
    }
}
