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
 * Rubric analysis and scoring class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading;

defined('MOODLE_INTERNAL') || die();

/**
 * Rubric_analyzer class.
 */
class rubric_analyzer {
    /**
     * Check whether the activity currently uses a rubric-compatible grading method.
     *
     * @param string $modulename Modulename.
     * @param int $instanceid Instanceid.
     * @param int|null $cmid Cmid.
     * @return bool True on success, false otherwise.
     */
    public static function has_rubric(string $modulename, int $instanceid, ?int $cmid = null): bool {
        return (bool)self::get_rubric($modulename, $instanceid, $cmid);
    }

    /**
     * Fetch and normalize a rubric definition for the given activity.
     *
     * @param string $modulename Currently only 'assign' is supported.
     * @param int $instanceid Instanceid.
     * @param int|null $cmid Cmid.
     * @return array|null The result.
     */
    public static function get_rubric(string $modulename, int $instanceid, ?int $cmid = null): ?array {
        global $CFG;

        require_once($CFG->dirroot . '/grade/grading/lib.php');

        try {
            switch ($modulename) {
                case 'assign':
                    $cm = self::resolve_assign_cm($instanceid, $cmid);
                    if (!$cm) {
                        return null;
                    }
                    $context = \context_module::instance($cm->id);
                    $manager = get_grading_manager($context, 'mod_assign', 'submissions');
                    break;

                default:
                    return null;
            }
        } catch (\Throwable $e) {
            debugging('[rubric_analyzer] Failed to resolve grading manager: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        $method = $manager->get_active_method();
        if (!in_array($method, ['rubric', 'rubric_ranges'], true)) {
            return null;
        }

        try {
            $controller = $manager->get_controller($method);
            $definition = $controller->get_definition();
        } catch (\Throwable $e) {
            debugging('[rubric_analyzer] Failed to load rubric definition: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        if (empty($definition) || empty($definition->rubric_criteria)) {
            return null;
        }

        $criteria = [];
        $totalmax = 0.0;
        foreach ($definition->rubric_criteria as $criterionid => $criteriondata) {
            $readablename = self::clean_label($criteriondata['description'] ?? '');
            if ($readablename === '') {
                $readablename = get_string('criterion', 'gradingform_rubric');
            }

            $normalized = self::normalize_name($readablename);
            $levels = [];
            $criterionmax = 0.0;
            foreach ($criteriondata['levels'] ?? [] as $levelid => $leveldata) {
                $leveldescription = self::clean_label($leveldata['definition'] ?? '');
                $levelscore = (float)($leveldata['score'] ?? 0);
                $criterionmax = max($criterionmax, $levelscore);
                $levels[] = [
                    'id' => (int)$levelid,
                    'label' => $leveldescription !== '' ? $leveldescription : get_string('level', 'gradingform_rubric'),
                    'description' => trim(strip_tags($leveldata['definition'] ?? '')),
                    'score' => $levelscore,
                ];
            }

            $totalmax += $criterionmax;
            $criteria[$criterionid] = [
                'id' => (int)$criterionid,
                'name' => $readablename,
                'maxscore' => $criterionmax,
                'levels' => $levels,
                'normalized' => $normalized,
            ];
        }

        if (empty($criteria)) {
            return null;
        }

        $snapshot = [
            'modulename' => $modulename,
            'instanceid' => $instanceid,
            'cmid' => $cm->id ?? $cmid,
            'definitionid' => (int)$definition->id,
            'method' => $method,
            'name' => format_string($definition->name ?? '', true, ['context' => $context ?? null]),
            'version' => (int)($definition->version ?? 0),
            'criteria' => $criteria,
            'maxscore' => $totalmax,
        ];
        $snapshot['hash'] = sha1(json_encode(
            $snapshot['criteria'],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));

        return $snapshot;
    }

    /**
     * Convert a rubric snapshot to JSON suitable for prompts.
     *
     * @param array|null $rubric Rubric.
     * @return string|null The result.
     */
    public static function rubric_to_json(?array $rubric): ?string {
        if (empty($rubric) || empty($rubric['criteria'])) {
            return null;
        }

        $export = [
            'name' => $rubric['name'] ?? 'Assignment Rubric',
            'max_score' => $rubric['maxscore'] ?? 0,
            'criteria' => [],
        ];

        foreach ($rubric['criteria'] as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] as $level) {
                $levels[] = [
                    'label' => $level['label'],
                    'score' => $level['score'],
                    'description' => $level['description'],
                ];
            }

            $export['criteria'][] = [
                'name' => $criterion['name'],
                'max_score' => $criterion['maxscore'],
                'levels' => $levels,
            ];
        }

        return json_encode(
            $export,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Validate an AI rubric response and map scores to Moodle criterion IDs.
     *
     * @param array $airesponse Result from process_queue::normalize_ai_response()
     * @param array $rubric Snapshot produced by get_rubric()
     * @return array{criteria:array,warnings:array,score:float,max_score:float,calculated_score:float} The result.
     */
    public static function map_scores_to_rubric(array $airesponse, array $rubric): array {
        $result = [
            'criteria' => [],
            'warnings' => [],
            'score' => (float)($airesponse['score'] ?? 0),
            'max_score' => (float)($airesponse['max_score'] ?? ($rubric['maxscore'] ?? 0)),
            'calculated_score' => 0.0,
        ];

        $aicriteria = $airesponse['criteria'] ?? [];
        if (!is_array($aicriteria)) {
            $aicriteria = [];
        }

        $usedindexes = [];
        foreach ($rubric['criteria'] as $criterion) {
            $matchindex = self::find_matching_ai_criterion($criterion, $aicriteria, $usedindexes);
            if ($matchindex === null) {
                $result['warnings'][] = get_string('error_rubric_missing_criteria', 'local_hlai_grading', $criterion['name']);
                $score = 0.0;
                $feedback = '';
            } else {
                $usedindexes[$matchindex] = true;
                $match = $aicriteria[$matchindex];
                $score = (float)($match['score'] ?? 0);
                $feedback = trim((string)($match['feedback'] ?? ''));
            }

            if ($score < 0) {
                $score = 0.0;
            }

            $maxscore = (float)$criterion['maxscore'];
            if ($maxscore > 0 && $score > $maxscore) {
                $result['warnings'][] = get_string('warning_rubric_changed', 'local_hlai_grading');
                $score = $maxscore;
            }

            $result['criteria'][] = [
                'criterionid' => $criterion['id'],
                'name' => $criterion['name'],
                'score' => $score,
                'max_score' => $maxscore,
                'feedback' => $feedback,
            ];

            $result['calculated_score'] += $score;
        }

        foreach ($aicriteria as $idx => $criterion) {
            if (!array_key_exists($idx, $usedindexes)) {
                $label = trim((string)($criterion['name'] ?? ''));
                $result['warnings'][] = get_string('warning_rubric_changed', 'local_hlai_grading') . ': ' .
                    ($label ?: 'criterion #' . ($idx + 1));
            }
        }

        if (empty($airesponse['score'])) {
            $result['score'] = $result['calculated_score'];
        }

        if (empty($airesponse['max_score']) && !empty($rubric['maxscore'])) {
            $result['max_score'] = (float)$rubric['maxscore'];
        }

        if (!empty($result['warnings'])) {
            debugging('[rubric_analyzer] ' . implode(' | ', $result['warnings']), DEBUG_NORMAL);
        }

        return $result;
    }

    /**
     * Resolve the course module for an assignment.
     *
     * @param int $instanceid Instanceid.
     * @param int|null $cmid Cmid.
     * @return \stdClass|null The object.
     */
    protected static function resolve_assign_cm(int $instanceid, ?int $cmid = null): ?\stdClass {
        if ($cmid) {
            $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                return $cm;
            }
        }

        return get_coursemodule_from_instance('assign', $instanceid, 0, false, IGNORE_MISSING);
    }

    /**
     * Attempt to match an AI criterion to the Moodle rubric.
     *
     * @param array $criterion Criterion.
     * @param array $aicriteria Aicriteria.
     * @param array $usedindexes Usedindexes.
     * @return int|null The result.
     */
    protected static function find_matching_ai_criterion(array $criterion, array $aicriteria, array $usedindexes): ?int {
        $target = $criterion['normalized'] ?? self::normalize_name($criterion['name']);

        foreach ($aicriteria as $idx => $aicriterion) {
            if (isset($usedindexes[$idx])) {
                continue;
            }

            $ainame = self::normalize_name($aicriterion['name'] ?? '');
            if ($ainame !== '' && $ainame === $target) {
                return $idx;
            }

            // Secondary fuzzy check: substring match if AI included score/emoji etc.
            $rawname = self::clean_label($aicriterion['name'] ?? '');
            if ($rawname !== '' && stripos($criterion['name'], $rawname) !== false) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Clean raw text for presentation.
     *
     * @param string|null $text Text.
     * @return string The string value.
     */
    protected static function clean_label(?string $text): string {
        $text = trim((string)$text);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Normalize a label so we can perform stable comparisons.
     *
     * @param string $text Text.
     * @return string The string value.
     */
    protected static function normalize_name(string $text): string {
        $text = self::clean_label($text);
        if ($text === '') {
            return '';
        }

        $text = \core_text::strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', '', $text);
        return trim($text);
    }
}
