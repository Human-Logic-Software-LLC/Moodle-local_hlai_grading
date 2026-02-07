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
 * Dashboard redirect page.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$demomode = optional_param('demo', 0, PARAM_BOOL);

if ($courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course);
    $context = context_course::instance($courseid);
    $PAGE->set_course($course);
    $urlparams = ['courseid' => $courseid];
    if ($demomode) {
        $urlparams['demo'] = 1;
    }
    $PAGE->set_url(new moodle_url('/local/hlai_grading/dashboard.php', $urlparams));
    $PAGE->set_context($context);
    
    // Check capability for teacher view
    require_capability('mod/quiz:viewreports', $context); // Or mod/quiz:grade
    $viewtype = 'teacher';
    $title = get_string('pluginname', 'local_hlai_grading') . ': ' . $course->shortname;
} else {
    require_login();
    $context = context_system::instance();
    $urlparams = [];
    if ($demomode) {
        $urlparams['demo'] = 1;
    }
    $PAGE->set_url(new moodle_url('/local/hlai_grading/dashboard.php', $urlparams));
    $PAGE->set_context($context);

    // Check capability for admin view
    require_capability('moodle/site:config', $context);
    $viewtype = 'admin';
    $title = get_string('pluginname', 'local_hlai_grading') . ': ' . get_string('administration');
}

$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->set_pagelayout('standard'); // Handles navigation automatically
$PAGE->requires->css('/local/hlai_grading/styles.css');

$output = $PAGE->get_renderer('local_hlai_grading');
$page = new \local_hlai_grading\output\dashboard_page($viewtype, $courseid ?: null, $demomode);

echo $output->header();

// We can add a specialized renderer method if we want, or just render the renderable directly
// if the default renderer supports it (which it usually does via render_renderable).
// But standard practice is to have a render method in the renderer. 
// However, since we defined export_for_template in dashboard_page, we can use $output->render($page).

echo $output->render($page);

echo $output->footer();
