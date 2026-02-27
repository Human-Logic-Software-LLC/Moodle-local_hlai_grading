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
 * Plugin index page.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/hlai_grading:viewresults', $context);

$PAGE->set_url('/local/hlai_grading/index.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_hlai_grading'));
$PAGE->set_heading(get_string('pluginname', 'local_hlai_grading'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_hlai_grading'));
echo $OUTPUT->notification(get_string('plugininstalledtest', 'local_hlai_grading'), 'notifysuccess');
echo $OUTPUT->footer();
