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

namespace mod_googlemeet;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * Teacher-renamed recording titles must survive sync (drivename semantics).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      ::sync_recordings
 */
class recording_name_sync_test extends \advanced_testcase {

    /**
     * Create a course, module and context.
     *
     * @return array
     */
    private function create_googlemeet_fixture(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Rename semantics room',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);

        return [$course, $googlemeet, $cm];
    }

    /**
     * Insert a recording row.
     *
     * @param int $googlemeetid Activity instance id.
     * @param string $driveid Drive file id.
     * @param array $overrides Field overrides.
     * @return int Recording row id.
     */
    private function create_recording(int $googlemeetid, string $driveid, array $overrides = []): int {
        global $DB;

        $now = time();
        $record = (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => $driveid,
            'name' => 'Recording ' . $driveid,
            'drivename' => 'Recording ' . $driveid,
            'createdtime' => $now - 100,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ], $overrides);

        return (int)$DB->insert_record('googlemeet_recordings', $record);
    }

    /**
     * Build a processed Drive file object as sync_recordings() expects it.
     *
     * @param string $driveid Drive file id.
     * @param string|null $name Recording name.
     * @param array $overrides Property overrides (webViewLink, createdTime, duration).
     * @return \stdClass
     */
    private function drive_file(string $driveid, ?string $name = null, array $overrides = []): \stdClass {
        return (object)array_merge([
            'recordingId' => $driveid,
            'name' => $name ?? ('Drive recording ' . $driveid),
            'createdTime' => time(),
            'duration' => '00:10:00',
            'webViewLink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
        ], $overrides);
    }

    /**
     * Import via sync sets name and drivename to the Drive file name; renaming
     * through the plugin rename path and re-syncing keeps the custom name.
     */
    public function test_imported_recording_keeps_teacher_rename_across_syncs(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet, $cm] = $this->create_googlemeet_fixture();

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Auto imported name')]);
        $this->assertSame(1, (int)$result['stats']['inserted']);
        $rowid = (int)$result['newrecordingids'][0];
        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Auto imported name', $row->name);
        $this->assertSame('Auto imported name', $row->drivename);

        // Teacher renames via the recording hub UI (web service).
        \mod_googlemeet_external::recording_edit_name($rowid, 'Tema 3: evaluación y criterios', $cm->id);
        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Tema 3: evaluación y criterios', $row->name);
        $this->assertSame('Auto imported name', $row->drivename);

        // Re-sync with the unchanged Drive file: the custom name survives. Feed
        // back the stored metadata so the sync has nothing real to update.
        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Auto imported name', [
            'webViewLink' => $row->webviewlink,
            'createdTime' => (int)$row->createdtime,
            'duration' => $row->duration,
        ])]);
        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Tema 3: evaluación y criterios', $row->name);
        $this->assertSame('Auto imported name', $row->drivename);
        $this->assertSame(0, (int)$result['stats']['updated']);
    }

    /**
     * Drive-side rename on a non-customised row propagates to the display name.
     */
    public function test_drive_rename_propagates_to_non_customised_row(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $rowid = $this->create_recording($googlemeet->id, 'drive-1', ['name' => 'Old Drive name']);
        // Non-customised: drivename still matches the auto-imported name.
        $DB->set_field('googlemeet_recordings', 'drivename', 'Old Drive name', ['id' => $rowid]);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'New Drive name')]);

        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('New Drive name', $row->name);
        $this->assertSame('New Drive name', $row->drivename);
        $this->assertGreaterThanOrEqual(1, (int)$result['stats']['updated']);
    }

    /**
     * Drive-side rename on a teacher-customised row does NOT change the display
     * name; only drivename (and other metadata) update.
     */
    public function test_drive_rename_does_not_overwrite_customised_name(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $rowid = $this->create_recording($googlemeet->id, 'drive-1', [
            'name' => 'Tema 3: evaluación y criterios',
            'drivename' => 'Auto imported name',
        ]);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Renamed in Drive')]);

        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Tema 3: evaluación y criterios', $row->name);
        $this->assertSame('Renamed in Drive', $row->drivename);
        // The skipped name overwrite is not an extra "update": only the drivename
        // change is a real change, so the stat stays at 1.
        $this->assertSame(1, (int)$result['stats']['updated']);
    }

    /**
     * Legacy rows (drivename = null, created before the column existed) keep
     * their stored — possibly customised — name on the first post-upgrade sync,
     * and drivename is initialised from the current Drive file name.
     */
    public function test_legacy_row_survives_first_post_upgrade_sync(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $rowid = $this->create_recording($googlemeet->id, 'drive-1', [
            'name' => 'Tema 3: evaluación y criterios',
            'drivename' => null,
        ]);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Auto imported name')]);

        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Tema 3: evaluación y criterios', $row->name);
        $this->assertSame('Auto imported name', $row->drivename);

        // Subsequent Drive renames must still not touch the personalised title.
        sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Renamed again in Drive')]);
        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Tema 3: evaluación y criterios', $row->name);
        $this->assertSame('Renamed again in Drive', $row->drivename);
    }

    /**
     * webviewlink / createdtime / duration keep updating unconditionally, even
     * on teacher-customised rows.
     */
    public function test_metadata_still_updates_on_customised_row(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $rowid = $this->create_recording($googlemeet->id, 'drive-1', [
            'name' => 'Tema 3: evaluación y criterios',
            'drivename' => 'Auto imported name',
            'createdtime' => time() - 500,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/drive-1/view',
        ]);

        $newtime = time() - 50;
        sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Auto imported name', [
            'webViewLink' => 'https://drive.google.com/file/d/drive-1/view?usp=sharing',
            'createdTime' => $newtime,
            'duration' => '01:23:45',
        ])]);

        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame('Tema 3: evaluación y criterios', $row->name);
        $this->assertSame('Auto imported name', $row->drivename);
        $this->assertSame('https://drive.google.com/file/d/drive-1/view?usp=sharing', $row->webviewlink);
        $this->assertSame($newtime, (int)$row->createdtime);
        $this->assertSame('01:23:45', $row->duration);
    }
}
