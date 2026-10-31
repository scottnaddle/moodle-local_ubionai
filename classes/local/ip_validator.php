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

use invalid_parameter_exception;

/**
 * Validates and normalises comma-separated IP / CIDR lists.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ip_validator {
    /**
     * Normalise a comma-separated list of IPv4/IPv6 addresses or CIDR networks.
     *
     * @param string $value raw value; '' or whitespace-only means "no restriction"
     * @return string canonical comma-joined list ('' when input is empty)
     * @throws invalid_parameter_exception when any entry is not a strict IP/CIDR
     */
    public static function normalise(string $value): string {
        if (trim($value) === '') {
            return '';
        }

        $seen = [];
        $normalised = [];
        foreach (explode(',', $value) as $raw) {
            $entry = trim($raw);
            if ($entry === '') {
                throw new invalid_parameter_exception('empty entry in IP list');
            }
            if (strpos($entry, '#') !== false) {
                throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
            }
            if (strpos($entry, '%') !== false) {
                throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
            }
            if (preg_match('/\s/', $entry)) {
                throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
            }

            $canonical = self::normalise_entry($entry);
            if (!isset($seen[$canonical])) {
                $seen[$canonical] = true;
                $normalised[] = $canonical;
            }
        }

        $result = implode(',', $normalised);
        if (strlen($result) > 255) {
            throw new invalid_parameter_exception('IP list exceeds 255 characters');
        }

        return $result;
    }

    /**
     * Normalise one bare address or strict CIDR network.
     *
     * @param string $entry trimmed, whitespace-free entry
     * @return string canonical form
     * @throws invalid_parameter_exception when the entry is not a strict IP/CIDR
     */
    private static function normalise_entry(string $entry): string {
        if (strpos($entry, '/') === false) {
            $packed = self::parse_address($entry);
            if ($packed === null) {
                throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
            }

            return (string) inet_ntop($packed);
        }

        $parts = explode('/', $entry);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || !ctype_digit($parts[1])) {
            throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
        }
        [$base, $prefix] = $parts;

        $packed = self::parse_address($base);
        if ($packed === null) {
            throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
        }

        $length = strlen($packed);
        $maxprefix = $length === 4 ? 32 : 128;
        $prefixlength = (int) $prefix;
        if ($prefixlength > $maxprefix) {
            throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
        }

        $fullbytes = intdiv($prefixlength, 8);
        $remaining = $prefixlength % 8;
        $mask = str_repeat("\xff", $fullbytes);
        if ($remaining > 0) {
            $mask .= chr((0xff << (8 - $remaining)) & 0xff);
        }
        $mask = str_pad($mask, $length, "\x00");
        if (($packed & $mask) !== $packed) {
            throw new invalid_parameter_exception("invalid IP entry '{$entry}'");
        }

        return inet_ntop($packed) . '/' . $prefixlength;
    }

    /**
     * Parse a bare IPv4/IPv6 address into its packed representation.
     *
     * @param string $address address without a prefix
     * @return string|null packed address, or null when invalid
     */
    private static function parse_address(string $address): ?string {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($address);

        return $packed === false ? null : $packed;
    }
}
