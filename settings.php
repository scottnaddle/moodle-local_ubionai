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
    // Phase 0 spike: default null => core lists it on admin/upgradesettings.php right after install
    // (any_new_admin_settings) and CLI install/upgrade skips it (admin_apply_default_settings).
    $consent = new admin_setting_configselect(
        'local_ubionai/consent',
        get_string('consent', 'local_ubionai'),
        get_string('consent_desc', 'local_ubionai'),
        null,
        [
            // Declined first so that a blind "Save changes" never consents.
            'declined' => get_string('consent_declined', 'local_ubionai'),
            'accepted' => get_string('consent_accepted', 'local_ubionai'),
        ]
    );
    $consent->set_updatedcallback('\\local_ubionai\\local\\setup::consent_updated');
    $page->add($consent);
    // Empty = registrar::DEFAULT_HUB, a provisional (temporary) default hub (§8.3 #6); admins may override it here.
    $page->add(new local_ubionai_admin_setting_hub_url(
        'local_ubionai/hub_url',
        get_string('hub_url', 'local_ubionai'),
        get_string('hub_url_desc', 'local_ubionai'),
        '',
        PARAM_URL
    ));
    // WP1/WP4: automatic self-registration on consent. Off keeps only the manual (legacy) flow.
    $page->add(new admin_setting_configcheckbox(
        'local_ubionai/self_registration',
        get_string('self_registration', 'local_ubionai'),
        get_string('self_registration_desc', 'local_ubionai'),
        1
    ));
    // Easy Connect spike 2: per-user OAuth through the hub. Both values come from the hub operator.
    $page->add(new admin_setting_configtext(
        'local_ubionai/hub_site_id',
        get_string('hub_site_id', 'local_ubionai'),
        get_string('hub_site_id_desc', 'local_ubionai'),
        '',
        '/^([a-z0-9][a-z0-9-]{1,62})?$/'
    ));
    $page->add(new admin_setting_configpasswordunmask(
        'local_ubionai/oauth_secret',
        get_string('oauth_secret', 'local_ubionai'),
        get_string('oauth_secret_desc', 'local_ubionai'),
        ''
    ));
    $ADMIN->add('localplugins', $page);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ubionai_connection',
        get_string('connectionpage', 'local_ubionai'),
        new moodle_url('/local/ubionai/connect.php'),
        'local/ubionai:manageconnection'
    ));
}
