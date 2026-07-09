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
 * Ad-hoc task re-evaluating the courses that require a just-completed course.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

use local_instantcoursecompletion\criteria_index;
use local_instantcoursecompletion\observer;

/**
 * Dependent-course notification task.
 */
class notify_dependent_courses_task extends \core\task\adhoc_task {
    /** @var int Upper bound on the dependent courses processed in one run. */
    protected const MAX_DEPENDENTS_PER_RUN = 200;

    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:notifydependentcourses', 'local_instantcoursecompletion');
    }

    /**
     * Re-evaluate the courses that name the completed course as a prerequisite.
     *
     * A course used as a gate by many programmes can have thousands of dependents.
     * Reacting to it in the request that completed it, or even in a single unbounded
     * ad-hoc task, would be an unbounded fan-out; this pages through them instead and
     * queues a continuation when a page is full.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $userid = isset($data->userid) ? (int)$data->userid : 0;
        $fromcourseid = isset($data->fromcourseid) ? (int)$data->fromcourseid : 0;

        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $dependents = criteria_index::dependent_course_ids($courseid, $fromcourseid, self::MAX_DEPENDENTS_PER_RUN);

        foreach ($dependents as $dependentid) {
            observer::handle_completion_trigger($dependentid, $userid);
        }

        if (count($dependents) >= self::MAX_DEPENDENTS_PER_RUN) {
            $this->queue_continuation($courseid, $userid, (int)end($dependents));
        }
    }

    /**
     * Queue the next page of dependents.
     *
     * @param int $courseid     The prerequisite course.
     * @param int $userid       User ID.
     * @param int $fromcourseid Resume after this dependent course ID.
     * @return void
     */
    protected function queue_continuation(int $courseid, int $userid, int $fromcourseid): void {
        $task = new self();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'fromcourseid' => $fromcourseid,
        ]);
        $task->set_userid($userid);

        try {
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: could not queue a dependent-course continuation for'
                . " course={$courseid} user={$userid}: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
