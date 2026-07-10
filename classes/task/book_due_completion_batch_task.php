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
 * Ad-hoc task booking one due criterion for a page of users.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

use local_instantcoursecompletion\completion_booker;
use local_instantcoursecompletion\due_candidate_repository;
use local_instantcoursecompletion\due_scheduler;
use local_instantcoursecompletion\scope_resolver;

/**
 * Due-criterion batch booking task.
 */
class book_due_completion_batch_task extends \core\task\adhoc_task {
    /** @var string Lock type serialising batch runs of the same course criterion. */
    private const LOCK_TYPE = 'local_instantcoursecompletion_batch';

    /** @var int Seconds of wall-clock time one run books before handing on to a continuation. */
    protected const MAX_RUNTIME = 30;

    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:bookduecompletionbatch', 'local_instantcoursecompletion');
    }

    /**
     * The wall-clock budget for one run.
     *
     * A seam for tests: a run that hits it queues a continuation and stops, so that a
     * batch of expensive aggregations never holds a cron slot open indefinitely.
     *
     * @return int Seconds.
     */
    protected function max_runtime(): int {
        return self::MAX_RUNTIME;
    }

    /**
     * Take the lock that serialises batch runs of one course criterion.
     *
     * A cron that fell behind can leave several due windows of the same criterion runnable
     * at once, each selecting the same "due now" cohort. Separates processes, not call
     * sites: a PostgreSQL advisory lock and a MySQL GET_LOCK are re-entrant within one
     * database session, so the contention cannot be exercised single-process. A zero
     * timeout is deliberate — a run that cannot take the lock is a duplicate window another
     * run is already draining, so it steps aside; discovery or reconcile picks the criterion
     * up again, so nothing is lost.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion ID.
     * @return \core\lock\lock|null The lock, or null when it is held elsewhere.
     */
    private function acquire_lock(int $courseid, int $criteriaid): ?\core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($courseid . '_' . $criteriaid, 0);

        return $lock ?: null;
    }

    /**
     * Book the criterion for every tracked user it has fallen due for.
     *
     * Expected custom data: courseid, criteriaid, duebucket and lastuserid, all integers.
     * The due window only decides when this task runs; at run time the criterion is
     * re-evaluated per user, so a window that has been overtaken by another task simply
     * finds nothing left to do.
     *
     * The course and its completion_info are built once for the whole page. That is the
     * entire point of the batch: booking a cohort user by user would rebuild the
     * criteria set and the module cache for each of them.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $criteriaid = isset($data->criteriaid) ? (int)$data->criteriaid : 0;
        $duebucket = isset($data->duebucket) ? (int)$data->duebucket : 0;
        $lastuserid = isset($data->lastuserid) ? (int)$data->lastuserid : 0;

        if ($courseid <= 0 || $criteriaid <= 0) {
            return;
        }

        // The scope may have been narrowed between scheduling and execution.
        if (!scope_resolver::is_in_scope($courseid)) {
            return;
        }

        $booker = completion_booker::for_course($courseid);
        if (!$booker) {
            return;
        }

        $criterion = $booker->get_criterion($criteriaid);
        if (!$criterion) {
            // The criterion was removed while the task waited for its due time.
            return;
        }

        $lock = $this->acquire_lock($courseid, $criteriaid);
        if (!$lock) {
            return;
        }

        try {
            $this->book_due_users($booker, $criterion, $duebucket, $lastuserid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Book every tracked user the criterion has fallen due for, one page at a time.
     *
     * Runs under the course-criterion lock taken in execute(): the course, its
     * completion_info and its criteria are already loaded into the booker, so each user
     * only costs the booking itself.
     *
     * @param completion_booker    $booker     Booker for the course.
     * @param \completion_criteria $criterion  The due criterion.
     * @param int                  $duebucket  Window the task belongs to.
     * @param int                  $lastuserid User to resume after; 0 for a fresh window.
     * @return void
     */
    private function book_due_users(
        completion_booker $booker,
        \completion_criteria $criterion,
        int $duebucket,
        int $lastuserid
    ): void {
        $courseid = (int)$booker->get_course()->id;
        $criteriaid = (int)$criterion->id;

        $batchsize = due_scheduler::batch_size();
        $userids = due_candidate_repository::get_due_user_ids($courseid, $criterion, $lastuserid, $batchsize);

        $booked = 0;
        $failed = 0;
        $processeduserid = $lastuserid;
        $started = microtime(true);
        $timedout = false;

        foreach ($userids as $userid) {
            try {
                if ($booker->book_criterion($criterion, $userid)) {
                    $booked++;
                }
                $processeduserid = $userid;
            } catch (\dml_exception | \coding_exception $e) {
                // A database or programming error is not something the next user in
                // this page fixes. The continuation resumes after the last user that
                // was actually processed, so nobody is skipped, and the exception
                // propagates so the task is visibly retried rather than silently short.
                due_scheduler::queue_continuation($courseid, $criteriaid, $duebucket, $processeduserid);
                mtrace('local_instantcoursecompletion book_due_completion_batch_task:'
                    . " aborted on course={$courseid} user={$userid}: " . $e->getMessage());
                throw $e;
            } catch (\Throwable $e) {
                $failed++;
                $processeduserid = $userid;
                debugging(
                    'local_instantcoursecompletion book_due_completion_batch_task:'
                    . " course={$courseid} user={$userid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

            if (microtime(true) - $started > $this->max_runtime()) {
                $timedout = true;
                break;
            }
        }

        if ($timedout || count($userids) >= $batchsize) {
            // A full page, or a run that ran out of time, may have users behind it. The
            // continuation resumes after the last user processed, so a timed-out run does
            // not re-book the ones it already did.
            due_scheduler::queue_continuation($courseid, $criteriaid, $duebucket, $processeduserid);
        }

        if (get_config('local_instantcoursecompletion', 'enablelogging') || $failed > 0) {
            mtrace('local_instantcoursecompletion book_due_completion_batch_task:'
                . " course={$courseid} criterion={$criteriaid}"
                . ' examined=' . count($userids)
                . " booked={$booked} failed={$failed}");
        }
    }
}
