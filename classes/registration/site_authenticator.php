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
 * Plugin-side check of hub -> plugin messages (authz_req, exchange, revoke), chosen by auth mode.
 *
 * Mirrors the hub's SiteAuthenticator (registration contract §7.1): shared_secret (option 1, v1)
 * and signature (option 2, reserved). legacy_file stays in \local_ubionai\local\oauth.
 * Implementations only prove that the hub produced the message; type, iss, aud, site, exp, jti
 * and callback checks belong to the caller.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface site_authenticator {
    /**
     * Mode name.
     *
     * @return string legacy_file|shared_secret|signature
     */
    public function mode(): string;

    /**
     * Verify a hub message and return its claims.
     *
     * @param string $token signed message
     * @return array claims
     * @throws \moodle_exception oauth_badrequest when the message is not authentic
     */
    public function verify(string $token): array;

    /**
     * Public parameters (no secrets), for the status screen.
     *
     * @return array
     */
    public function describe(): array;
}
