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
 * Ad-hoc task that books a course completion off the web request.
 *
 * Queued by \local_instantcoursecompletion\observer in async mode. Identical
 * pending tasks (same custom data + user) are collapsed by the scheduler.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

use local_instantcoursecompletion\completion_booker;

/**
 * Ad-hoc course-completion booking task.
 */
class book_completion_task extends \core\task\adhoc_task {
    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:bookcompletion', 'local_instantcoursecompletion');
    }

    /**
     * Execute the booking.
     *
     * Expected custom data: {courseid:int, userid:int}.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $userid   = isset($data->userid) ? (int)$data->userid : 0;

        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        try {
            completion_booker::book($courseid, $userid);
        } catch (\Throwable $e) {
            // Log and let the scheduler retry per its normal policy.
            mtrace('local_instantcoursecompletion book_completion_task failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
