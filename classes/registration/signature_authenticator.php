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
 * signature mode (option 2, contract §7): JWS EdDSA by a hub key whose key set is signed by a
 * root key pinned in the plugin. Placeholder only: no keys are generated or pinned here.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signature_authenticator implements site_authenticator {
    /**
     * Mode name.
     *
     * @return string
     */
    public function mode(): string {
        return 'signature';
    }

    /**
     * Not implemented in v1.
     *
     * @param string $token signed message
     * @return array
     */
    public function verify(string $token): array {
        throw new \coding_exception('auth_mode=signature is not implemented (registration contract option 2)');
    }

    /**
     * Not implemented in v1.
     *
     * @return array
     */
    public function describe(): array {
        throw new \coding_exception('auth_mode=signature is not implemented (registration contract option 2)');
    }
}
