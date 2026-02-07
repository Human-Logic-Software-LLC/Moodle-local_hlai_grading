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
 * Grading logic class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

use moodle_exception;

/**
 * Grader class.
 */
class grader {
    /**
     * Grade a piece of text via gateway.
     *
     * @param string $question Question.
     * @param string $studenttext Studenttext.
     * @param string|null $rubricjson Rubricjson.
     * @param string $quality fast|balanced|best
     * @return array The result array.
     * @throws moodle_exception
     */
    public function grade_text(
        string $question,
        string $studenttext,
        ?string $rubricjson = null,
        string $quality = 'balanced'
    ): array {

        if (!gateway_client::is_ready()) {
            throw new moodle_exception('aiclientnotready', 'local_hlai_grading');
        }

        $payload = [
            'question' => $question,
            'submission' => $studenttext,
            'rubric_json' => $rubricjson,
        ];
        $response = gateway_client::grade('grade_text', $payload, $quality);

        $raw = $response['content'] ?? null;
        if (is_string($raw)) {
            $data = json_decode($raw, true);
        } else if (is_array($raw)) {
            $data = $raw;
        } else {
            $data = null;
        }
        if (empty($data)) {
            throw new moodle_exception(
                'invalidaigrade',
                'local_hlai_grading',
                '',
                null,
                'Gateway returned empty/invalid JSON'
            );
        }

        return $data;
    }
}
