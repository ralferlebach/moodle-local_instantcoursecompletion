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
        if (!$context || !is_enrolled($context, $userid, '', true)) {
            return 0;
        }

        $timecompleted = $DB->get_field('course_completions', 'timecompleted', [
            'course' => $courseid,
            'userid' => $userid,
        ]);
        if (!empty($timecompleted)) {
            return 0;
        }

        $now = time();
        $horizon = $now + self::horizon_seconds();
        $queued = 0;

        foreach (self::time_criteria($courseid) as $criterion) {
            if (self::criterion_recorded((int)$criterion->id, $userid)) {
                continue;
            }

            $duetime = self::due_time($criterion, $userid);
            if ($duetime === null || $duetime > $horizon) {
                continue;
            }

            if (self::queue($courseid, $userid, $duetime, $now)) {
                $queued++;
            }
        }

        return $queued;
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
     * Queue one booking task unless an identical one is already pending.
     *
     * The custom data is the de-duplication key, compared as a string by
     * \core\task\manager, so its keys are written in a fixed order. Bookings that fall
     * due at the same instant are spread over a window, to keep a single cron run from
     * having to process a whole cohort at once; the jitter is deterministic and stays
     * out of the key.
     *
     * @param int        $courseid      Course ID.
     * @param int        $userid        User ID.
     * @param int        $duetime       Time the criterion falls due.
     * @param int        $now           Current time.
     * @param array|null $pending       Custom-data keys already queued; updated in place.
     * @param bool       $checkexisting Whether to ask the queue as well as $pending.
     * @return bool Whether a task was queued.
     */
    public static function queue(
        int $courseid,
        int $userid,
        int $duetime,
        int $now,
        ?array &$pending = null,
        bool $checkexisting = true
    ): bool {
        $customdata = (object)[
            'courseid' => $courseid,
            'duetime' => $duetime,
            'userid' => $userid,
        ];
        $key = json_encode($customdata);

        if ($pending !== null && isset($pending[$key])) {
            return false;
        }

        $task = new book_due_completion_task();
        $task->set_custom_data($customdata);
        $task->set_next_run_time(max($now, $duetime) + ($userid % self::JITTER_WINDOW));

        // The queue returns false when the existence check finds an identical twin.
        if (\core\task\manager::queue_adhoc_task($task, $checkexisting) === false) {
            return false;
        }

        if ($pending !== null) {
            $pending[$key] = true;
        }

        return true;
    }
}
