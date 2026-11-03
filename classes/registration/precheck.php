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
 * Local pre-check that the site URL can be reached from the internet (contract §4.3, §8.1).
 *
 * Only a hint: split DNS can make this server see a different answer than the hub, so the hub's
 * private_address_not_supported is the final word. A failed lookup is not judged private here.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class precheck {
    /** Host suffixes treated as internal. */
    public const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet'];

    /** Extra IPv4/IPv6 ranges refused even if PHP filters call them public. */
    private const DENY_CIDRS = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '168.63.129.16/32',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '2001::/32',
        '100::/64', '64:ff9b::/96', '2002::/16', '::ffff:0:0/96',
    ];

    /** @var callable|null test resolver: fn(string $host): string[] */
    private static $resolver = null;

    /**
     * Why the URL is not supported, or null when it looks public.
     *
     * @param string $url site URL ($CFG->wwwroot)
     * @return string|null unsupported_scheme|invalid_site_url|private_address_not_supported
     */
    public static function problem(string $url): ?string {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return 'invalid_site_url';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'unsupported_scheme';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'invalid_site_url';
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if (self::host_is_internal($host)) {
            return 'private_address_not_supported';
        }
        foreach (self::resolve($host) as $ip) {
            if (!self::ip_is_public($ip)) {
                return 'private_address_not_supported';
            }
        }
        return null;
    }

    /**
     * Host-name level checks (before DNS): IP literals, single labels, localhost, internal suffixes.
     *
     * @param string $host lower-case host
     * @return bool
     */
    public static function host_is_internal(string $host): bool {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        // Decimal / octal / hex IPv4 spellings (2130706433, 0177.0.0.1, 0x7f.1) are IP literals too.
        if (preg_match('/\A[0-9x.]+\z/i', $host) && preg_match('/\A[0-9]/', $host)) {
            return true;
        }
        if (strpos($host, '.') === false || $host === 'localhost') {
            return true;
        }
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an address is a public unicast address.
     *
     * @param string $ip IPv4 or IPv6 address
     * @return bool
     */
    public static function ip_is_public(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        foreach (self::DENY_CIDRS as $cidr) {
            if (self::in_cidr($packed, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a packed address is inside a CIDR of the same family.
     *
     * @param string $packed packed address
     * @param string $cidr network/prefix
     * @return bool
     */
    private static function in_cidr(string $packed, string $cidr): bool {
        [$base, $prefix] = explode('/', $cidr);
        $net = inet_pton($base);
        if (strlen($net) !== strlen($packed)) {
            return false;
        }
        $prefix = (int) $prefix;
        $bytes = intdiv($prefix, 8);
        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * Resolve A and AAAA records of a host.
     *
     * @param string $host host name
     * @return string[] addresses (empty when the lookup fails)
     */
    private static function resolve(string $host): array {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                } else if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if (!$ips) {
            $ips = @gethostbynamel($host) ?: [];
        }
        return $ips;
    }

    /**
     * Replace DNS resolution in unit tests.
     *
     * @param callable|null $resolver fn(string $host): string[]
     * @return void
     */
    public static function set_resolver_for_testing(?callable $resolver): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for unit tests');
        }
        self::$resolver = $resolver;
    }
}
