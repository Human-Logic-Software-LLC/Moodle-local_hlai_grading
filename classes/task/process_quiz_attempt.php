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
 * Ad-hoc task to process a quiz attempt for AI grading.
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

/**
 * Ad-hoc task for processing quiz attempts.
 */
class process_quiz_attempt extends \core\task\adhoc_task {
    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $taskdata = $this->get_custom_data();
        $attemptid = $taskdata->attemptid ?? 0;
        $eventname = $taskdata->eventname ?? '';

        if (!$attemptid) {
            return;
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
        if (!$attempt) {
            return;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', IGNORE_MISSING);
        if (!$quiz) {
            return;
        }

        if (!\local_hlai_grading_is_activity_enabled('quiz', (int)$quiz->id)) {
            return;
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $activitiesettings = \local_hlai_grading_get_activity_settings('quiz', (int)$quiz->id);
        $rubricjson = null;
        if (!empty($activitiesettings->rubricid)) {
            $rubricjson = \local_hlai_grading_get_quiz_rubric_json((int)$activitiesettings->rubricid);
        }

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();

        if (empty($slots)) {
            return;
        }

        $queuer = new queuer();

        foreach ($slots as $slot) {
            if (!\question_engine::is_manual_grade_in_range($attempt->uniqueid, $slot)) {
                continue;
            }

            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();

            if (!$question || !in_array($question->get_type_name(), ['essay'], true)) {
                continue;
            }

            $answer = trim((string)$qa->get_last_qt_var('answer'));
            if ($answer === '') {
                $answer = trim((string)$qa->get_response_summary());
            }

            $submissionfiles = [];
            if ($answer === '' && method_exists($qa, 'get_last_qt_files')) {
                try {
                    $attachments = $qa->get_last_qt_files('attachments');
                } catch (\Throwable $e) {
                    $attachments = [];
                }
                if (!empty($attachments)) {
                    $filetext = [];
                    foreach ($attachments as $file) {
                        if ($file->is_directory()) {
                            continue;
                        }
                        $extracted = content_extractor::extract_file($file);
                        if (!empty($extracted['text'])) {
                            $filetext[] = $extracted['text'];
                            $submissionfiles[] = $file->get_filename();
                            continue;
                        }
                        $submissionfiles[] = $file->get_filename()
                            . (!empty($extracted['error'])
                                ? ' (error: ' . $extracted['error'] . ')' : '');
                    }
                    if (!empty($filetext)) {
                        $answer = trim(implode("\n\n", $filetext));
                    } else if (!empty($submissionfiles)) {
                        $answer = 'Student submitted the following files: ' . implode(', ', $submissionfiles) .
                            '. The system could not automatically extract full text. Please review them manually.';
                    }
                }
            }

            if ($answer === '') {
                continue;
            }

            $questiontext = strip_tags($question->questiontext, '<p><br><strong><em><ul><ol><li>');

            $graderinfo = '';
            if (property_exists($question, 'graderinfo')) {
                $graderinfo = $question->graderinfo ?? '';
            }
            $keytext = '';
            if ($graderinfo !== '') {
                $formatted = format_text(
                    $graderinfo,
                    $question->graderinfoformat ?? FORMAT_HTML,
                    ['context' => \context_module::instance($cm->id)]
                );
                $keytext = trim(strip_tags($formatted));
            }

            $payload = [
                'userid' => $attempt->userid,
                'courseid' => $quiz->course,
                'cmid' => $cm->id,
                'quizid' => $quiz->id,
                'attemptid' => $attempt->id,
                'questionid' => $question->id,
                'slot' => $slot,
                'modulename' => 'quiz',
                'instanceid' => $quiz->id,
                'question' => $questiontext ?: format_string($question->name),
                'questionname' => format_string($question->name),
                'submissiontext' => $answer,
                'submissionfiles' => $submissionfiles,
                'keytext' => $keytext,
                'maxmark' => $qa->get_max_mark(),
            ];
            if ($rubricjson) {
                $payload['rubric_json'] = $rubricjson;
            }

            $queuer->queue_submission(
                $attempt->userid,
                $quiz->course,
                $cm->id,
                $eventname,
                $payload
            );
        }
    }
}
