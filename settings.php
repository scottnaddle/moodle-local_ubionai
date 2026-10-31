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
 * Settings for local_ubionai.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    if (!class_exists('local_ubionai_admin_setting_hub_url')) {
        /**
         * Hub URL setting that only accepts https URLs.
         */
        class local_ubionai_admin_setting_hub_url extends admin_setting_configtext {
            /**
             * Validate the hub URL.
             *
             * @param string $data the submitted value
             * @return true|string true when valid, an error message otherwise
             */
            public function validate($data) {
                $data = trim((string) $data);
                if ($data !== '' && !preg_match('#^https://#i', $data)) {
                    return get_string('hub_url_invalid', 'local_ubionai');
                }
                return parent::validate($data);
            }
        }
    }

    $page = new admin_settingpage('local_ubionai', get_string('connection', 'local_ubionai'));
    $page->add(new local_ubionai_admin_setting_hub_url('local_ubionai/hub_url',
        get_string('hub_url', 'local_ubionai'),
        get_string('hub_url_desc', 'local_ubionai'),
        '', PARAM_URL));
    $ADMIN->add('localplugins', $page);

    $ADMIN->add('localplugins', new admin_externalpage('local_ubionai_connection',
        get_string('connectionpage', 'local_ubionai'),
        new moodle_url('/local/ubionai/connect.php'),
        'local/ubionai:manageconnection'));
}
