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

namespace local_ubionai\event;

/**
 * Event fired when the UBION AI web service connection is provisioned.
 *
 * @package    local_ubionai
 * @copyright  2026 scottnaddle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_connected extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'external_services';
    }

    /**
     * Create the event from an external service id.
     *
     * @param int $serviceid external service id
     * @param array $other additional event data
     * @return self
     */
    public static function create_from_service(int $serviceid, array $other = []): self {
        return static::create([
            'context' => \context_system::instance(),
            'objectid' => $serviceid,
            'other' => $other,
        ]);
    }

    /**
     * Get the event name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('event_site_connected', 'local_ubionai');
    }

    /**
     * Get the event description.
     *
     * @return string|null
     */
    public function get_description(): ?string {
        return get_string('event_site_connected_desc', 'local_ubionai');
    }

    /**
     * Get the event URL.
     *
     * @return \moodle_url|null
     */
    public function get_url(): ?\moodle_url {
        return new \moodle_url('/local/ubionai/connect.php');
    }
}
