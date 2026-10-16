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

namespace mod_googlemeet\event;

/**
 * The mod_googlemeet recordings bulk-trashed event.
 *
 * Fired when a teacher moves every recording of an activity to the trash via
 * delete_all_recordings(). Rows are soft-deleted (deleted = 1) and restorable.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recordings_bulk_trashed extends \core\event\base {

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'googlemeet';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordingsbulktrashed', 'mod_googlemeet');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' moved " . (int)($this->other['count'] ?? 0) .
            " recording(s) of the Google Meet activity with id '$this->objectid' to the trash.";
    }

    /**
     * Defines mapping of the 'objectid' property when restoring course logs.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'googlemeet', 'restore' => 'googlemeet'];
    }
}
