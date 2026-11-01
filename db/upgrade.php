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
 * Upgrade steps for local_ubionai.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the local_ubionai plugin.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_local_ubionai_upgrade($oldversion): bool {
    global $DB;

    if ($oldversion < 2026103101) {
        // Rename the legacy 'grokbot' quiz question categories to 'ubionai', skipping any
        // context that already has a sibling 'ubionai' category.
        $sql = "SELECT qc.id, qc.contextid
                  FROM {question_categories} qc
                  JOIN {context} ctx ON ctx.id = qc.contextid
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE qc.name = 'grokbot'
                   AND ctx.contextlevel = :contextlevel
                   AND qc.parent = (SELECT id FROM {question_categories}
                                     WHERE contextid = qc.contextid AND parent = 0)";
        foreach ($DB->get_records_sql($sql, ['contextlevel' => CONTEXT_MODULE]) as $cat) {
            $conflict = $DB->record_exists(
                'question_categories',
                ['contextid' => $cat->contextid, 'name' => 'ubionai']
            );
            if (!$conflict) {
                $DB->set_field('question_categories', 'name', 'ubionai', ['id' => $cat->id]);
            }
        }
        upgrade_plugin_savepoint(true, 2026103101, 'local', 'ubionai');
    }

    if ($oldversion < 2026103104) {
        // 0.4.0: tokens are now tracked by row id in the issued_token_ids config. Existing
        // tokens from earlier versions are not adopted (they may have been issued manually);
        // disconnect will leave them in place and warn. Start tracking from an empty list.
        if (get_config('local_ubionai', 'issued_token_ids') === false) {
            set_config('issued_token_ids', '', 'local_ubionai');
        }
        upgrade_plugin_savepoint(true, 2026103104, 'local', 'ubionai');
    }

    if ($oldversion < 2026103105) {
        // 0.5.0: no data change. Connect is now idempotent and lazily adopts existing
        // 'ubionai-mcp' tokens (token_action: reused|adopted|created); the new read
        // function local_ubionai_list_course_assignments is attached to the service by
        // re-running Connect, because core registers external_functions only after this
        // upgrade hook runs (upgrade_component_updated -> external_update_descriptions).
        upgrade_plugin_savepoint(true, 2026103105, 'local', 'ubionai');
    }

    if ($oldversion < 2026103106) {
        // 0.5.1: no data change. Connect no longer adopts untracked 'ubionai-mcp' tokens; it mints
        // a new token for the hub instead (token_action: rotated) and leaves the legacy token in
        // place unless --revoke-legacy is given. A token adopted by 0.5.0 stays tracked because it
        // cannot be told apart from one this plugin minted; the README describes how to rotate it.
        upgrade_plugin_savepoint(true, 2026103106, 'local', 'ubionai');
    }

    return true;
}
