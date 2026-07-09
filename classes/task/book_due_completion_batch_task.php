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
 * Ad-hoc task booking one page of the cohort a date criterion falls due for.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

use local_instantcoursecompletion\course_booker;
use local_instantcoursecompletion\due_scheduler;
use local_instantcoursecompletion\scope_resolver;

/**
 * Batched due-time course-completion booking task.
 */
class book_due_completion_batch_task extends \core\task\adhoc_task {
    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:bookduebatch', 'local_instantcoursecompletion');
    }

    /**
     * Book one page of the users a date criterion has fallen due for.
     *
     * A date criterion falls due for every learner of the course at the same instant.
     * One task per learner would put a whole cohort into the queue at once, so the work
     * is paged instead: this task books at most one page and, if the page was full,
     * queues its own continuation from the last user it saw.
     *
     * Expected custom data: courseid, criteriaid and lastuserid, all integers.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $data = $this->get_custom_data();
        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $criteriaid = isset($data->criteriaid) ? (int)$data->criteriaid : 0;
        $lastuserid = isset($data->lastuserid) ? (int)$data->lastuserid : 0;

        if ($courseid <= 0 || $criteriaid <= 0) {
            return;
        }

        // The scope may have been narrowed between planning and execution.
        if (!scope_resolver::is_in_scope($courseid)) {
            return;
        }

        $booker = course_booker::for_course($courseid);
        if ($booker === null) {
            return;
        }

        $batchsize = due_scheduler::batch_size();
        $userids = due_scheduler::date_due_user_ids($courseid, $criteriaid, $lastuserid, $batchsize);
        if (empty($userids)) {
            return;
        }

        $failed = 0;
        foreach ($userids as $userid) {
            try {
                $booker->book($userid);
            } catch (\Throwable $e) {
                $failed++;
                debugging(
                    'local_instantcoursecompletion book_due_completion_batch_task:'
                    . " course={$courseid} user={$userid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        if ($failed > 0) {
            mtrace("local_instantcoursecompletion book_due_completion_batch_task:"
                . " course={$courseid} criterion={$criteriaid} failed={$failed}");
        }

        if (count($userids) >= $batchsize) {
            // The page was full, so more users may be waiting behind it.
            due_scheduler::queue_batch($courseid, $criteriaid, time(), (int)end($userids));
        }
    }
}
