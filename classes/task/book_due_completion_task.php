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
 * Ad-hoc task that books a completion once a time-based criterion falls due.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

use local_instantcoursecompletion\completion_booker;
use local_instantcoursecompletion\scope_resolver;

/**
 * Due-time course-completion booking task.
 */
class book_due_completion_task extends \core\task\adhoc_task {
    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:bookduecompletion', 'local_instantcoursecompletion');
    }

    /**
     * Book the completion for the scheduled course and user.
     *
     * Expected custom data: courseid, duetime and userid, all integers. The due time
     * is part of the de-duplication key and is not read here; the criteria are
     * re-evaluated from their own data sources.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $userid = isset($data->userid) ? (int)$data->userid : 0;

        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        // The scope may have been narrowed between scheduling and execution.
        if (!scope_resolver::is_in_scope($courseid)) {
            return;
        }

        completion_booker::book($courseid, $userid);
    }
}
