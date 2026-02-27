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
 * Event observer class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Observer class.
 *
 * Event callbacks are kept lightweight and delegate heavy processing
 * (content extraction, rubric loading, queue insertion) to ad-hoc tasks.
 */
class observer {
    /**
     * Fired when an assignment submission is made.
     *
     * Queues an ad-hoc task for the heavy processing instead of running it
     * synchronously inside the event callback.
     *
     * @param \mod_assign\event\assessable_submitted $event Event.
     * @return bool True on success, false otherwise.
     */
    public static function assign_submitted($event): bool {
        global $USER;

        $data = $event->get_data();

        $context = $event->get_context();
        if ($context && $context->contextlevel == CONTEXT_MODULE) {
            $cmid = $context->instanceid;
        } else {
            $cmid = $data['contextinstanceid'] ?? $data['other']['cmid'] ?? null;
        }

        $courseid = $data['courseid'] ?? null;
        $userid = $event->relateduserid ?? $data['userid'] ?? $USER->id;

        // Quick check: resolve the assign instance to see if AI grading is enabled.
        $assignid = null;
        if ($cmid && $courseid) {
            $cm = get_coursemodule_from_id('assign', $cmid, $courseid, false, IGNORE_MISSING);
            if ($cm && !empty($cm->instance)) {
                $assignid = (int)$cm->instance;
            }
        }

        if (!$assignid || !\local_hlai_grading_is_activity_enabled('assign', $assignid)) {
            return true;
        }

        // Delegate heavy processing to an ad-hoc task.
        $task = new \local_hlai_grading\task\process_assign_submission();
        $task->set_custom_data((object)[
            'eventdata' => $data,
            'eventname' => $event->eventname,
            'cmid' => $cmid,
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        \core\task\manager::queue_adhoc_task($task);

        return true;
    }

    /**
     * Fired when a quiz attempt is submitted.
     *
     * Queues an ad-hoc task for the heavy processing instead of running it
     * synchronously inside the event callback.
     *
     * @param \mod_quiz\event\attempt_submitted $event Event.
     * @return bool True on success, false otherwise.
     */
    public static function quiz_attempt_submitted($event): bool {
        global $DB;

        $attemptid = $event->objectid ?? 0;
        if (!$attemptid) {
            return true;
        }

        // Quick check: verify attempt exists and AI grading is enabled.
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz', IGNORE_MISSING);
        if (!$attempt) {
            return true;
        }

        $quizid = (int)$attempt->quiz;
        if (!\local_hlai_grading_is_activity_enabled('quiz', $quizid)) {
            return true;
        }

        // Delegate heavy processing to an ad-hoc task.
        $task = new \local_hlai_grading\task\process_quiz_attempt();
        $task->set_custom_data((object)[
            'attemptid' => $attemptid,
            'eventname' => $event->eventname,
        ]);
        \core\task\manager::queue_adhoc_task($task);

        return true;
    }
}
