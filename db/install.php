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
 * Install hook for local_ubionai (Phase 0 spike).
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs once after install.xml, before core registers this plugin's capabilities, functions and
 * declared services. No external HTTP, no reliance on $USER (may run anonymously or from CLI).
 *
 * @return bool
 */
function xmldb_local_ubionai_install(): bool {
    \local_ubionai\local\setup::ensure_defaults('install');
    return true;
}

/**
 * Recovery hook for an interrupted install: the defaults are idempotent.
 *
 * @return bool
 */
function xmldb_local_ubionai_install_recovery(): bool {
    \local_ubionai\local\setup::ensure_defaults('install_recovery');
    return true;
}
