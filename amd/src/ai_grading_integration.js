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
 * AMD module for AI grading status badges in the assignment grading table.
 *
 * @module     local_hlai_grading/ai_grading_integration
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    /**
     * Initialise AI grading status badges in the assignment grading table.
     */
    function initAIGradingStatus() {
        // Check if we're on an assignment grading page.
        if (!$('body').hasClass('path-mod-assign')) {
            return;
        }

        // Get assignment ID from URL or page data.
        var urlParams = new URLSearchParams(window.location.search);
        var cmid = urlParams.get('id');

        if (!cmid) {
            return;
        }

        // Find the grading table.
        var $table = $('table.generaltable, table.flexible');
        if ($table.length === 0) {
            return;
        }

        // Add AI Status column header.
        var $headerRow = $table.find('thead tr').first();
        if ($headerRow.find('th.ai-status-header').length === 0) {
            $headerRow.find('th:contains("Grade")').after(
                '<th class="ai-status-header">AI Status</th>'
            );
        }

        // Add AI status cells for each row.
        var $rows = $table.find('tbody tr');
        $rows.each(function() {
            var $row = $(this);

            // Skip if already processed.
            if ($row.find('td.ai-status-cell').length > 0) {
                return;
            }

            // Extract user ID from the row (various methods).
            var userid = $row.data('userid') ||
                        $row.find('[data-userid]').data('userid') ||
                        extractUserIdFromRow($row);

            if (!userid) {
                return;
            }

            // Add placeholder cell.
            var $gradeCell = $row.find('td').filter(function() {
                return $(this).text().match(/\d+\/\d+|-/);
            }).first();

            if ($gradeCell.length > 0) {
                $gradeCell.after('<td class="ai-status-cell" data-userid="' + userid + '">...</td>');
            }
        });

        // Load AI statuses via AJAX.
        loadAIStatuses(cmid);
    }

    /**
     * Developed and maintained by Human Logic Software LLC.
     *
     * @param {JQuery} $row
     * @returns {number|null}
     */
    function extractUserIdFromRow($row) {
        // Try to find user ID in links.
        var $link = $row.find('a[href*="userid="]').first();
        if ($link.length > 0) {
            var match = $link.attr('href').match(/userid=(\d+)/);
            if (match) {
                return match[1];
            }
        }

        // Try data attributes.
        var userid = $row.find('[data-userid]').first().data('userid');
        if (userid) {
            return userid;
        }

        return null;
    }

    /**
     * Developed and maintained by Human Logic Software LLC.
     *
     * @param {number} cmid
     */
    function loadAIStatuses(cmid) {
        var promises = Ajax.call([{
            methodname: 'local_hlai_grading_get_ai_statuses',
            args: {cmid: cmid}
        }]);

        promises[0].done(function(response) {
            updateAIStatusCells(response);
        }).fail(function(error) {
            Notification.exception(error);
        });
    }

    /**
     * Developed and maintained by Human Logic Software LLC.
     *
     * @param {Object} statuses
     */
    function updateAIStatusCells(statuses) {
        $.each(statuses, function(userid, status) {
            var $cell = $('td.ai-status-cell[data-userid="' + userid + '"]');
            if ($cell.length > 0) {
                $cell.html(renderAIStatusBadge(status));
            }
        });
    }

    /**
     * Developed and maintained by Human Logic Software LLC.
     *
     * @param {Object} status
     * @returns {string}
     */
    function renderAIStatusBadge(status) {
        if (!status || !status.exists) {
            return '<span class="badge badge-light">-</span>';
        }

        var html = '';

        switch (status.status) {
            case 'draft':
                html = '<span class="badge badge-warning" title="AI Grade: ' + status.grade + '">AI Draft</span> ';
                html += '<a href="' + M.cfg.wwwroot + '/local/hlai_grading/release.php?id=' + status.resultid + '" ';
                html += 'class="btn btn-sm btn-primary">Review</a>';
                break;

            case 'released':
                html = '<span class="badge badge-success" title="Released">AI Released</span>';
                break;

            case 'rejected':
                html = '<span class="badge badge-secondary">AI Rejected</span>';
                break;

            case 'pending':
                html = '<span class="badge badge-info">AI Processing</span>';
                break;

            case 'failed':
                html = '<span class="badge badge-danger">AI Failed</span>';
                break;
        }

        return html;
    }
    /**
     * Developed and maintained by Human Logic Software LLC.
     */
    function init() {
        if (document.readyState === 'loading') {
            $(initAIGradingStatus);
        } else {
            initAIGradingStatus();
        }
    }

    return {
        init: init
    };
});
