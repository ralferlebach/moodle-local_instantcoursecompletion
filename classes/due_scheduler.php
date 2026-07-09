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
use local_instantcoursecompletion\task\book_due_completion_task;

/**
 * Due-time scheduler.
 */
class due_scheduler {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /** @var int Seconds over which bookings due at the same instant are spread. */
    public const JITTER_WINDOW = 900;

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
     * How many users a batch task books before handing on to its continuation.
     *
     * @return int
     */
    public static function batch_size(): int {
        $size = (int)get_config(self::COMPONENT, 'batchsize');
        return $size > 0 ? $size : 500;
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

        if (!self::user_can_own_a_task($userid)) {
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

        $now = time();
        $horizon = $now + self::horizon_seconds();
        $planned = 0;

        try {
            foreach (self::time_criteria($courseid) as $criterion) {
                if (self::criterion_recorded((int)$criterion->id, $userid)) {
                    continue;
                }

                $duetime = self::due_time($criterion, $userid);
                if ($duetime === null || $duetime > $horizon) {
                    continue;
                }

                if (self::queue($courseid, (int)$criterion->id, $userid, $duetime, $now)) {
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
     * Whether an ad-hoc task may be attributed to this user.
     *
     * queue_adhoc_task() rejects users that are not real, are deleted, are suspended or
     * cannot log in. The task carries the user so that the queue can find an identical
     * one through the indexed userid column instead of scanning every row.
     *
     * @param int $userid User ID.
     * @return bool
     */
    protected static function user_can_own_a_task(int $userid): bool {
        if (!\core_user::is_real_user($userid)) {
            return false;
        }

        $user = \core_user::get_user($userid, 'id, deleted, suspended, auth');
        return $user && !$user->deleted && !$user->suspended && $user->auth !== 'nologin';
    }

    /**
     * When a time-based criterion falls due for a user.
     *
     * @param \stdClass $criterion Criterion record.
     * @param int       $userid    User ID.
     * @return int|null Timestamp, or null when the criterion can never fall due.
     */
    public static function due_time(\stdClass $criterion, int $userid): ?int {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ((int)$criterion->criteriatype === COMPLETION_CRITERIA_TYPE_DATE) {
            $timeend = (int)$criterion->timeend;
            return $timeend > 0 ? $timeend : null;
        }

        $enrolperiod = (int)$criterion->enrolperiod;
        if ($enrolperiod <= 0) {
            return null;
        }

        $timeenrolled = self::time_enrolled((int)$criterion->course, $userid);
        return $timeenrolled === null ? null : $timeenrolled + $enrolperiod;
    }

    /**
     * The moment a user's enrolment in a course starts counting.
     *
     * The earliest enrolment wins, and an enrolment without a start date counts from its
     * creation time. Both rules match completion_criteria_duration::cron(), whose review()
     * counterpart reads ue.timestart alone and therefore never completes such users.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return int|null Timestamp, or null when the user has no usable enrolment.
     */
    public static function time_enrolled(int $courseid, int $userid): ?int {
        global $DB;

        $timeenrolled = $DB->get_field_sql(
            "SELECT MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );

        return empty($timeenrolled) ? null : (int)$timeenrolled;
    }

    /**
     * Whether the user already has a completion record for the criterion.
     *
     * @param int $criteriaid Criterion ID.
     * @param int $userid     User ID.
     * @return bool
     */
    protected static function criterion_recorded(int $criteriaid, int $userid): bool {
        global $DB;

        return $DB->record_exists('course_completion_crit_compl', [
            'criteriaid' => $criteriaid,
            'userid' => $userid,
        ]);
    }

    /**
     * Tracked users of a course who still owe the given date criterion, after $fromuserid.
     *
     * Shared by the discovery task, which asks for a single row to decide whether a batch
     * is worth planning at all, and by the batch task, which asks for a whole page.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion ID.
     * @param int $fromuserid Only users with a higher ID are returned.
     * @param int $limit      Maximum number of users to return.
     * @return int[] Ordered ascending.
     */
    public static function date_due_user_ids(int $courseid, int $criteriaid, int $fromuserid, int $limit): array {
        global $DB;

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }

        [$enrolledsql, $params] = get_enrolled_sql($context, self::TRACKED_CAPABILITY, 0, true);
        $params['courseid'] = $courseid;
        $params['criteriaid'] = $criteriaid;
        $params['fromuserid'] = $fromuserid;

        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
                  JOIN {user} u ON u.id = enrolled.id AND u.deleted = 0 AND u.suspended = 0
             LEFT JOIN {course_completions} cco
                    ON cco.userid = enrolled.id AND cco.course = :courseid
             LEFT JOIN {course_completion_crit_compl} ccc
                    ON ccc.userid = enrolled.id AND ccc.criteriaid = :criteriaid
                 WHERE (cco.timecompleted IS NULL OR cco.timecompleted = 0)
                   AND ccc.id IS NULL
                   AND enrolled.id > :fromuserid
              ORDER BY enrolled.id ASC";

        return array_map('intval', $DB->get_fieldset_sql($sql, $params, 0, $limit));
    }

    /**
     * Plan the batch that books a date criterion for the whole course.
     *
     * A date criterion falls due for every learner at the same instant, so it gets one
     * paged task rather than one task per learner. The page position is part of the
     * de-duplication key, which is what lets a continuation coexist with the head task
     * a later discovery run may re-plan.
     *
     * No user is attached, so the queue lookup cannot use the indexed userid column.
     * That is affordable here: one call per criterion per run, not one per learner.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion whose due time this batch waits for.
     * @param int $duetime    Time the criterion falls due.
     * @param int $lastuserid Last user booked by the preceding page, 0 for the first.
     * @return bool Whether the batch was planned.
     */
    public static function queue_batch(int $courseid, int $criteriaid, int $duetime, int $lastuserid = 0): bool {
        $task = new book_due_completion_batch_task();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'criteriaid' => $criteriaid,
            'lastuserid' => $lastuserid,
        ]);
        $task->set_next_run_time(max(time(), $duetime));

        try {
            \core\task\manager::reschedule_or_queue_adhoc_task($task);
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: could not plan a batch for'
                . " course={$courseid} criterion={$criteriaid}: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }

        return true;
    }

    /**
     * Plan one booking task, or move an existing one to a new due time.
     *
     * The custom data is the de-duplication key, compared as a string by
     * \core\task\manager, so its keys are written in a fixed order and the due time is
     * deliberately not among them: the due time lives in nextruntime, which
     * reschedule_or_queue_adhoc_task() updates in place when an enrolment start moves.
     * queue_adhoc_task()'s own $checkforexisting is documented for ASAP tasks only.
     *
     * The user is attached to the task so that the queue lookup uses the indexed userid
     * column; customdata carries no index and would otherwise be scanned in full.
     *
     * Bookings falling due at the same instant are spread over a window to keep a single
     * cron run from processing a whole cohort at once. The jitter is deterministic and,
     * being part of nextruntime rather than the key, never splits a task in two.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion whose due time this task waits for.
     * @param int $userid     User ID.
     * @param int $duetime    Time the criterion falls due.
     * @param int $now        Current time.
     * @return bool Whether the task was planned.
     */
    public static function queue(int $courseid, int $criteriaid, int $userid, int $duetime, int $now): bool {
        $task = new book_due_completion_task();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'criteriaid' => $criteriaid,
            'userid' => $userid,
        ]);
        $task->set_userid($userid);
        $task->set_next_run_time(max($now, $duetime) + ($userid % self::JITTER_WINDOW));

        try {
            \core\task\manager::reschedule_or_queue_adhoc_task($task);
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: could not plan a booking for'
                . " course={$courseid} user={$userid}: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }

        return true;
    }
}
