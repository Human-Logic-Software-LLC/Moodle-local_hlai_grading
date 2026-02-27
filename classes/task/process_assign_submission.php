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
 * Ad-hoc task to process an assignment submission for AI grading.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_hlai_grading\local\queuer;
use local_hlai_grading\local\content_extractor;
use local_hlai_grading\rubric_analyzer;

/**
 * Ad-hoc task for processing assignment submissions.
 */
class process_assign_submission extends \core\task\adhoc_task {
    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $USER, $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $taskdata = $this->get_custom_data();
        $data = (array)$taskdata->eventdata;
        $eventname = $taskdata->eventname;

        $context = null;
        $cmid = $taskdata->cmid ?? null;
        $courseid = $taskdata->courseid ?? null;
        $userid = $taskdata->userid ?? $USER->id;

        $assignid = null;
        $submissiontext = '';
        $assignmentname = '';
        $keytext = '';
        $filesummary = [];
        $submission = null;

        if ($cmid && $courseid) {
            $cm = get_coursemodule_from_id('assign', $cmid, $courseid, false, IGNORE_MISSING);
            if ($cm && !empty($cm->instance)) {
                $assignid = (int)$cm->instance;

                try {
                    $assigncontext = \context_module::instance($cm->id);
                    $assign = new \assign($assigncontext, $cm, null);
                    $assignmentname = $assign->get_instance()->name;

                    $submission = $assign->get_user_submission($userid, false);

                    if ($submission) {
                        $extracted = content_extractor::extract_from_assignment($assign, $submission);
                        $submissiontext = $extracted['text'] ?? '';
                        $filesummary = $extracted['files'] ?? [];
                    }

                    $assigninstance = $assign->get_instance();
                    $graderinfo = '';
                    if ($assigninstance && property_exists($assigninstance, 'gradinginstructions')) {
                        $graderinfo = $assigninstance->gradinginstructions ?? '';
                    }
                    if ($graderinfo !== '') {
                        $formatted = format_text(
                            $graderinfo,
                            $assigninstance->gradinginstructionsformat ?? FORMAT_HTML,
                            ['context' => $assigncontext]
                        );
                        $keytext = trim(strip_tags($formatted));
                    }
                } catch (\Exception $e) {
                    $submissiontext = '';
                    $keytext = '';
                }
            }
        }

        if ($assignid && $keytext === '') {
            try {
                $settings = \local_hlai_grading_get_activity_settings('assign', (int)$assignid);
                $custom = trim((string)($settings->custominstructions ?? ''));
                if ($custom !== '') {
                    $keytext = $custom;
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        if (!$assignid || !\local_hlai_grading_is_activity_enabled('assign', $assignid)) {
            return;
        }

        $payload = $data;
        $payload['userid'] = $userid;
        $payload['courseid'] = $courseid;
        $payload['cmid'] = $cmid;
        $payload['assignid'] = $assignid;
        $payload['modulename'] = 'assign';
        $payload['instanceid'] = $assignid;
        $payload['submissiontext'] = $submissiontext;
        $payload['submissionid'] = $submission->id ?? null;
        $payload['assignment'] = $assignmentname;
        $payload['keytext'] = $keytext;
        if (!empty($filesummary)) {
            $payload['submissionfiles'] = $filesummary;
        }

        $rubricsnapshot = null;
        $rubricjson = null;
        if ($assignid) {
            try {
                $rubricsnapshot = rubric_analyzer::get_rubric('assign', $assignid, $cmid);
                if ($rubricsnapshot) {
                    $rubricjson = rubric_analyzer::rubric_to_json($rubricsnapshot);
                }
            } catch (\Throwable $e) {
                $rubricsnapshot = null;
            }
        }
        if ($rubricsnapshot) {
            $payload['rubric_snapshot'] = $rubricsnapshot;
            if ($rubricjson) {
                $payload['rubric_json'] = $rubricjson;
            }
        }

        $queuer = new queuer();
        $queuer->queue_submission(
            $userid,
            $courseid,
            $cmid,
            $eventname,
            $payload
        );

        if ($assignid && $userid) {
            \local_hlai_grading\local\workflow_manager::set_assign_state($assignid, $userid, 'inmarking');
        }
    }
}
