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
 * AI gateway client class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

/**
 * Gateway_client class.
 */
class gateway_client {
    /** @var string Fixed gateway endpoint (locked by design). */
    private const GATEWAY_URL = 'https://ai.human-logic.com/ai';

    /**
     * Return the gateway base URL.
     *
     * @return string The gateway URL.
     */
    public static function get_gateway_url(): string {
        return self::GATEWAY_URL;
    }

    /**
     * Return configured gateway key.
     *
     * @return string The string value.
     */
    public static function get_gateway_key(): string {
        return trim((string)get_config('local_hlai_grading', 'gatewaykey'));
    }

    /**
     * Whether the gateway client is configured for processing.
     *
     * @return bool True on success, false otherwise.
     */
    public static function is_ready(): bool {
        return self::get_gateway_key() !== '';
    }

    /**
     * Send a grading operation payload to the gateway.
     *
     * @param string $operation Operation.
     * @param array $payload Payload.
     * @param string $quality Quality.
     * @return array{provider:string,content:mixed} The result.
     * @throws \moodle_exception
     */
    public static function grade(string $operation, array $payload, string $quality = 'balanced'): array {
        global $CFG;

        if (!self::is_ready()) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading');
        }

        $request = [
            'operation' => $operation,
            'quality' => $quality,
            'payload' => $payload,
            'plugin' => 'local_hlai_grading',
        ];

        require_once($CFG->libdir . '/filelib.php');

        $url = rtrim(self::get_gateway_url(), '/') . '/grade';
        $key = self::get_gateway_key();
        $curl = new \curl();
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
            'X-HL-Plugin: local_hlai_grading',
        ];

        try {
            $curl->setHeader($headers);
            $response = $curl->post($url, json_encode($request));
        } catch (\Throwable $e) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading', '', null, 'Gateway request failed');
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading', '', null, 'Gateway response was not valid JSON');
        }

        if (!empty($decoded['error'])) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading', '', null, 'Gateway rejected request');
        }

        $content = $decoded['content'] ?? $decoded['result'] ?? $decoded;
        $provider = trim((string)($decoded['provider'] ?? 'gateway'));
        if ($provider === '') {
            $provider = 'gateway';
        }

        return [
            'provider' => $provider,
            'content' => $content,
        ];
    }
}
