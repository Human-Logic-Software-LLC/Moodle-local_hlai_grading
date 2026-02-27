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
 * Backup plugin for local_hlai_grading.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/moodle2/backup_local_plugin.class.php');

/**
 * Backup plugin class for local_hlai_grading.
 *
 * Backs up per-activity AI grading settings stored in local_hlai_grading_act_settings.
 */
class backup_local_hlai_grading_plugin extends backup_local_plugin {
    /**
     * Define the plugin structure for module backup.
     *
     * @return backup_plugin_element The plugin element.
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $settings = new backup_nested_element('hlai_grading_settings', ['id'], [
            'modulename', 'instanceid', 'enabled', 'quality',
            'custominstructions', 'autorelease', 'rubricid',
            'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($settings);

        $cm = $this->task->get_moduleid();
        $modulename = $this->task->get_modulename();
        $activityid = $this->task->get_activityid();

        $settings->set_source_table('local_hlai_grading_act_settings', [
            'modulename' => backup_helper::is_sqlparam($modulename),
            'instanceid' => backup::VAR_ACTIVITYID,
        ]);

        return $plugin;
    }
}
