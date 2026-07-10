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
 * Plans ad-hoc bookings for time-based completion criteria.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_due_completion_batch_task;

/**
 * Due-time scheduler.
 */
class due_scheduler {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /**
     * The granularity due times are rounded up to.
     *
     * Every criterion falling due inside one window shares a single batch task. The
     * window replaces the per-user jitter of earlier versions and delays a booking by
     * no more than the jitter already did.
     *
     * @var int
     */
    public const BATCH_WINDOW = 900;

    /**
     * The capability that decides whose course progress Moodle follows.
     *
     * completion_info::is_tracked_user() and get_tracked_users() gate on it, so a user
     * without it never appears in a completion report and must never be booked. Note
     * that Moodle's own criteria cron is looser: completion_criteria_duration::cron()
     * reads {user_enrolments} with no capability filter at all.
     *
     * @var string
     */
    public const TRACKED_CAPABILITY = 'moodle/course:isincompletionreports';

    /** @var string Lock type serialising the schedulers against each other. */
    public const LOCK_TYPE = 'local_instantcoursecompletion_schedule';

    /**
     * Whether time-based criteria are planned in advance at all.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool)get_config(self::COMPONENT, 'schedulingenabled');
    }

    /**
     * How far ahead bookings are planned.
     *
     * The horizon bounds the number of rows that can enter the ad-hoc queue. It has to
     * exceed the interval between two discovery runs, or due times pass unplanned.
     *
     * @return int Seconds.
     */
    public static function horizon_seconds(): int {
        $horizon = (int)get_config(self::COMPONENT, 'schedulinghorizon');
        return $horizon > 0 ? $horizon : WEEKSECS;
    }

    /**
     * Upper bound on the ad-hoc tasks queued in one discovery run.
     *
     * @return int
     */
    public static function max_tasks_per_run(): int {
        $max = (int)get_config(self::COMPONENT, 'maxtasksperrun');
        return $max > 0 ? $max : 5000;
    }

    /**
     * Number of users one batch task books before handing on to a continuation.
     *
     * @return int
     */
    public static function batch_size(): int {
        $size = (int)get_config(self::COMPONENT, 'batchsize');
        return $size > 0 ? $size : 500;
    }

    /**
     * Round a due time up to the start of the batch window that contains it.
     *
     * Rounding up rather than down keeps a task from running before its criterion is
     * actually satisfied.
     *
     * @param int $duetime The moment the criterion falls due.
     * @return int
     */
    public static function due_bucket(int $duetime): int {
        return (int)(ceil($duetime / self::BATCH_WINDOW) * self::BATCH_WINDOW);
    }

    /**
     * The time-based criteria of a course.
     *
     * @param int $courseid Course ID.
     * @return \stdClass[] Criterion records, keyed by ID.
     */
    public static function time_criteria(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        [$typesql, $params] = $DB->get_in_or_equal(
            [COMPLETION_CRITERIA_TYPE_DATE, COMPLETION_CRITERIA_TYPE_DURATION],
            SQL_PARAMS_NAMED,
            'ct'
        );
        $params['course'] = $courseid;

        return $DB->get_records_select(
            'course_completion_criteria',
            "course = :course AND criteriatype $typesql",
            $params,
            'id ASC'
        );
    }

    /**
     * Plan the due bookings of one user in one course.
     *
     * This is the event-driven counterpart of the discovery task: an enrolment that is
     * created or re-dated changes when a duration criterion falls due, and there is no
     * reason to wait for the next discovery run to find out.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return int Number of tasks queued.
     */
    public static function schedule_user(int $courseid, int $userid): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        if (!self::enabled() || $courseid <= 0 || $userid <= 0 || $courseid == SITEID) {
            return 0;
        }

        // The cached index answers this without touching the criteria table.
        $hasdate = criteria_index::has_type($courseid, COMPLETION_CRITERIA_TYPE_DATE);
        $hasduration = criteria_index::has_type($courseid, COMPLETION_CRITERIA_TYPE_DURATION);
        if (!$hasdate && !$hasduration) {
            return 0;
        }

        if (!scope_resolver::is_in_scope($courseid)) {
            return 0;
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || !is_enrolled($context, $userid, self::TRACKED_CAPABILITY, true)) {
            return 0;
        }

        $timecompleted = $DB->get_field('course_completions', 'timecompleted', [
            'course' => $courseid,
            'userid' => $userid,
        ]);
        if (!empty($timecompleted)) {
            return 0;
        }

        // Serialise against a discovery run planning the same course right now.
        $lock = self::course_lock($courseid, 2);
        if (!$lock) {
            return 0;
        }

        $horizon = time() + self::horizon_seconds();
        $planned = 0;

        try {
            $criteria = self::time_criteria($courseid);

            // One query for the criteria the user is already booked for, and the enrolment
            // time computed once and shared by every duration criterion, rather than a pair
            // of queries per criterion.
            $recorded = self::recorded_criteria_ids(array_keys($criteria), $userid);
            $timeenrolled = false;

            foreach ($criteria as $criterion) {
                $criteriaid = (int)$criterion->id;
                if (isset($recorded[$criteriaid])) {
                    continue;
                }

                if ((int)$criterion->criteriatype === COMPLETION_CRITERIA_TYPE_DATE) {
                    $timeend = (int)$criterion->timeend;
                    $duetime = $timeend > 0 ? $timeend : null;
                } else {
                    $enrolperiod = (int)$criterion->enrolperiod;
                    if ($enrolperiod <= 0) {
                        continue;
                    }
                    if ($timeenrolled === false) {
                        $timeenrolled = due_candidate_repository::get_enrolment_time($courseid, $userid);
                    }
                    $duetime = ($timeenrolled === null) ? null : $timeenrolled + $enrolperiod;
                }

                if ($duetime === null || $duetime > $horizon) {
                    continue;
                }

                if (self::queue_bucket($courseid, $criteriaid, self::due_bucket($duetime))) {
                    $planned++;
                }
            }
        } finally {
            $lock->release();
        }

        return $planned;
    }

    /**
     * Acquire the lock that serialises the schedulers for one course.
     *
     * Both the discovery task and the enrolment observers plan the same course. Core's
     * reschedule_or_queue_adhoc_task() reads and then writes, which is not atomic, so
     * the two are kept apart rather than left to race.
     *
     * The lock separates processes, not call sites: both the PostgreSQL advisory lock
     * and the MySQL GET_LOCK are re-entrant inside one database session. Cron and a web
     * request never share one, which is exactly where the race lives.
     *
     * @param int $courseid Course to lock.
     * @param int $timeout  Seconds to wait; 0 tries once and gives up.
     * @return \core\lock\lock|null The lock, or null when it is held elsewhere.
     */
    public static function course_lock(int $courseid, int $timeout): ?\core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock('course_' . $courseid, $timeout);

        return $lock ?: null;
    }

    /**
     * The criteria among the given set the user already has a completion record for.
     *
     * One query replaces a per-criterion existence check. The result is a set keyed by
     * criterion ID, so membership is an isset() away.
     *
     * @param int[] $criteriaids Criterion IDs to test.
     * @param int   $userid      User ID.
     * @return array Keys are the recorded criterion IDs.
     */
    protected static function recorded_criteria_ids(array $criteriaids, int $userid): array {
        global $DB;

        if (empty($criteriaids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($criteriaids, SQL_PARAMS_NAMED, 'cid');
        $params['userid'] = $userid;

        $records = $DB->get_records_sql(
            "SELECT criteriaid
               FROM {course_completion_crit_compl}
              WHERE criteriaid $insql AND userid = :userid",
            $params
        );

        return array_flip(array_map('intval', array_keys($records)));
    }

    /**
     * Plan the batch task for one criterion and one due window.
     *
     * The custom data is the de-duplication key, compared as a string by
     * \core\task\manager. The due window is part of it, so a duration criterion whose
     * users fall due at different times gets one task per window rather than one per
     * user. The window is also the run time, which reschedule_or_queue_adhoc_task()
     * updates in place should the window ever move.
     *
     * No user is attached: the task is course-wide, and there are now few enough of them
     * that an unindexed customdata comparison costs nothing.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion whose due time this task waits for.
     * @param int $duebucket  Start of the window the due times fall into.
     * @return bool Whether the task was planned.
     */
    public static function queue_bucket(int $courseid, int $criteriaid, int $duebucket): bool {
        return self::queue_task($courseid, $criteriaid, $duebucket, 0, $duebucket);
    }

    /**
     * Plan the continuation of a batch task that filled its page.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion being booked.
     * @param int $duebucket  Window the parent task belonged to.
     * @param int $lastuserid Last user the parent task booked.
     * @return bool Whether the task was planned.
     */
    public static function queue_continuation(int $courseid, int $criteriaid, int $duebucket, int $lastuserid): bool {
        return self::queue_task($courseid, $criteriaid, $duebucket, $lastuserid, null);
    }

    /**
     * Build and enqueue a batch task.
     *
     * A first task for a window carries lastuserid 0 and runs at the window; a continuation
     * carries the last user booked and runs as soon as possible (a null run time).
     *
     * @param int      $courseid    Course ID.
     * @param int      $criteriaid  Criterion being booked.
     * @param int      $duebucket   Window the task belongs to.
     * @param int      $lastuserid  User to resume after; 0 for a fresh window.
     * @param int|null $nextruntime Run time to set, or null to leave it as soon as possible.
     * @return bool Whether the task was planned.
     */
    private static function queue_task(
        int $courseid,
        int $criteriaid,
        int $duebucket,
        int $lastuserid,
        ?int $nextruntime
    ): bool {
        $task = new book_due_completion_batch_task();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'criteriaid' => $criteriaid,
            'duebucket' => $duebucket,
            'lastuserid' => $lastuserid,
        ]);
        if ($nextruntime !== null) {
            $task->set_next_run_time($nextruntime);
        }

        return self::enqueue($task, $courseid);
    }

    /**
     * Hand a task to the queue, turning a refusal into a skipped booking.
     *
     * @param book_due_completion_batch_task $task     The task.
     * @param int                            $courseid Course ID, for the log line.
     * @return bool
     */
    protected static function enqueue(book_due_completion_batch_task $task, int $courseid): bool {
        try {
            \core\task\manager::reschedule_or_queue_adhoc_task($task);
        } catch (\Throwable $e) {
            debugging(
                "local_instantcoursecompletion: could not plan a booking for course={$courseid}: "
                . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }

        return true;
    }
}
