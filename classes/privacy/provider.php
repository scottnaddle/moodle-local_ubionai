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

namespace local_ubionai\privacy;

/**
 * Privacy provider for local_ubionai.
 *
 * The plugin itself stores no personal data in Moodle: it has no database tables and no user
 * preferences. Data is sent outbound to the UBION AI hub (and its LLM sub-processors), so the
 * deletion and export request methods are deliberate no-ops this iteration. Hub-side handling
 * of data subject requests is planned for a later iteration.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {
    /**
     * Describe the external location that data is sent to.
     *
     * @param \core_privacy\local\metadata\collection $collection the metadata collection
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(
            \core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_external_location_link('ubionai_hub', [
            'fullname' => 'privacy:metadata:ubionai_hub:fullname',
            'email' => 'privacy:metadata:ubionai_hub:email',
            'grades' => 'privacy:metadata:ubionai_hub:grades',
            'submissions' => 'privacy:metadata:ubionai_hub:submissions',
            'forumauthors' => 'privacy:metadata:ubionai_hub:forumauthors',
            'coursecontent' => 'privacy:metadata:ubionai_hub:coursecontent',
            'prompts' => 'privacy:metadata:ubionai_hub:prompts',
        ], 'privacy:metadata:ubionai_hub');
        // Ephemeral: a code lives at most two minutes and is deleted when the hub redeems it.
        $collection->add_database_table('local_ubionai_codes', [
            'userid' => 'privacy:metadata:local_ubionai_codes:userid',
            'timecreated' => 'privacy:metadata:local_ubionai_codes:timecreated',
        ], 'privacy:metadata:local_ubionai_codes');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * The plugin stores no user data in Moodle, so the list is always empty.
     *
     * @param int $userid the user id
     * @return \core_privacy\local\request\contextlist
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        return new \core_privacy\local\request\contextlist();
    }

    /**
     * Export all user data for the specified user in the specified contexts.
     *
     * The plugin stores no user data in Moodle, so there is nothing to export.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist the approved contexts
     */
    public static function export_user_data(
            \core_privacy\local\request\approved_contextlist $contextlist): void {
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * The plugin stores no user data in Moodle, so there is nothing to delete.
     *
     * @param \context $context the context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
    }

    /**
     * Delete all user data for the specified user in the specified contexts.
     *
     * The plugin stores no user data in Moodle, so there is nothing to delete.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist the approved contexts
     */
    public static function delete_data_for_user(
            \core_privacy\local\request\approved_contextlist $contextlist): void {
    }

    /**
     * Get the list of users who have data within a context.
     *
     * The plugin stores no user data in Moodle, so the list is left empty.
     *
     * @param \core_privacy\local\request\userlist $userlist the user list
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
    }

    /**
     * Delete all user data for the specified users in the specified context.
     *
     * The plugin stores no user data in Moodle, so there is nothing to delete.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist the approved user list
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
    }
}
