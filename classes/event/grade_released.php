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
 * Grade released event class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Grade_released class.
 */
class grade_released extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'hlai_grading_results';
    }

    public static function get_name(): string {
        return get_string('event_grade_released', 'local_hlai_grading');
    }

    public function get_description(): string {
        return "AI grade {$this->objectid} was released to user {$this->relateduserid}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/release.php', ['id' => $this->objectid]);
    }
}
