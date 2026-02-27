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
 * Restore plugin for local_hlai_grading.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/moodle2/restore_local_plugin.class.php');

/**
 * Restore plugin class for local_hlai_grading.
 *
 * Restores per-activity AI grading settings from local_hlai_grading_act_settings.
 */
class restore_local_hlai_grading_plugin extends restore_local_plugin {
    /**
     * Define the plugin structure for module restore.
     *
     * @return restore_path_element[] The restore path elements.
     */
    protected function define_module_plugin_structure() {
        $paths = [];

        $elepath = $this->get_pathfor('/hlai_grading_settings');
        $paths[] = new restore_path_element('hlai_grading_settings', $elepath);

        return $paths;
    }

    /**
     * Process a single hlai_grading_settings element during restore.
     *
     * @param array $data The data from the backup file.
     * @return void
     */
    public function process_hlai_grading_settings($data) {
        global $DB;

        $data = (object)$data;

        $newinstanceid = $this->task->get_activityid();
        $modulename = $this->task->get_modulename();

        $existing = $DB->get_record('local_hlai_grading_act_settings', [
            'modulename' => $modulename,
            'instanceid' => $newinstanceid,
        ]);

        if ($existing) {
            return;
        }

        $now = time();
        $record = (object)[
            'modulename' => $modulename,
            'instanceid' => $newinstanceid,
            'enabled' => $data->enabled,
            'quality' => $data->quality,
            'custominstructions' => $data->custominstructions ?? '',
            'autorelease' => $data->autorelease ?? 0,
            'rubricid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $DB->insert_record('local_hlai_grading_act_settings', $record);
    }
}
