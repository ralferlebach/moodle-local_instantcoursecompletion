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
 * Scheduled task that plans ad-hoc bookings for time-based completion criteria.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

use local_instantcoursecompletion\scope_resolver;

/**
 * Due-criteria discovery task.
 */
class discover_due_criteria_task extends \core\task\scheduled_task {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /** @var string Config key holding the ID of the last fully processed course. */
    protected const CURSOR = 'schedulecursor';

    /** @var int Upper bound on the courses inspected in one run. */
    protected const MAX_COURSES_PER_RUN = 200;

    /** @var int Seconds over which bookings due at the same instant are spread. */
    protected const JITTER_WINDOW = 900;

    /** @var int Upper bound on the pending tasks pre-loaded for de-duplication. */
    protected const MAX_PENDING_PREFETCH = 50000;

    /** @var array<string, bool> Custom data of the booking tasks already queued. */
    protected $pending = [];

    /** @var bool Whether $pending holds every queued task inside the horizon. */
    protected $prefetchcomplete = true;

    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:discoverduecriteria', self::COMPONENT);
    }

    /**
     * Plan bookings for every time-based criterion falling due within the horizon.
     *
     * Nothing is evaluated here. The run only decides which (course, user, due time)
     * triples need an ad-hoc task, and leaves the evaluation to that task. Both the
     * number of courses and the number of tasks per run are capped; a cursor over
     * course IDs carries the position into the next run.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;

        if (!get_config(self::COMPONENT, 'schedulingenabled')) {
            return;
        }
        require_once($CFG->libdir . '/completionlib.php');

        $now = time();
        $horizon = $now + self::horizon_seconds();
        $budget = self::max_tasks_per_run();
        $logging = (bool)get_config(self::COMPONENT, 'enablelogging');

        $courseids = $this->eligible_course_ids((int)get_config(self::COMPONENT, self::CURSOR), $horizon);
        if (empty($courseids)) {
            $this->set_cursor(0);
            return;
        }

        $this->load_pending_tasks($horizon);

        $queued = 0;
        $exhausted = false;

        foreach ($courseids as $courseid) {
            $remaining = $budget - $queued;
            if ($remaining <= 0) {
                $exhausted = true;
                break;
            }

            if (!scope_resolver::is_in_scope($courseid)) {
                $this->set_cursor($courseid);
                continue;
            }

            $made = $this->schedule_course($courseid, $now, $horizon, $remaining);
            $queued += $made;

            if ($made >= $remaining) {
                // The course may hold further due users; resume from it next run.
                $exhausted = true;
                break;
            }
            $this->set_cursor($courseid);
        }

        if (!$exhausted && count($courseids) < self::MAX_COURSES_PER_RUN) {
            // The last course of the site has been reached; start over next run.
            $this->set_cursor(0);
        }

        if ($logging) {
            mtrace('local_instantcoursecompletion discover_due_criteria_task:'
                . ' courses=' . count($courseids)
                . ' queued=' . $queued);
        }
    }

    /**
     * How far ahead bookings are planned.
     *
     * The horizon bounds the number of rows this task can add to the ad-hoc queue.
     * It has to exceed the interval between two runs, or due times pass unplanned.
     *
     * @return int Seconds.
     */
    protected static function horizon_seconds(): int {
        $horizon = (int)get_config(self::COMPONENT, 'schedulinghorizon');
        return $horizon > 0 ? $horizon : WEEKSECS;
    }

    /**
     * Upper bound on the ad-hoc tasks queued in one run.
     *
     * @return int
     */
    protected static function max_tasks_per_run(): int {
        $max = (int)get_config(self::COMPONENT, 'maxtasksperrun');
        return $max > 0 ? $max : 5000;
    }

    /**
     * Persist the cursor position.
     *
     * @param int $courseid Last fully processed course ID, or 0 to restart.
     * @return void
     */
    protected function set_cursor(int $courseid): void {
        set_config(self::CURSOR, $courseid, self::COMPONENT);
    }

    /**
     * Read the booking tasks already queued inside the horizon into memory.
     *
     * One query replaces the one that \core\task\manager would otherwise run per
     * enqueue. The read is capped, because the pending queue grows with the number of
     * due bookings and not with anything this task controls. When the cap is reached
     * the set is incomplete and queue_due_task() asks the database instead.
     *
     * @param int $horizon Latest due time being planned for.
     * @return void
     */
    protected function load_pending_tasks(int $horizon): void {
        global $DB;

        $customdata = $DB->get_fieldset_sql(
            'SELECT customdata
               FROM {task_adhoc}
              WHERE classname = :classname AND component = :component AND nextruntime <= :latest
           ORDER BY id ASC',
            [
                'classname' => '\\' . book_due_completion_task::class,
                'component' => self::COMPONENT,
                'latest' => $horizon + self::JITTER_WINDOW,
            ],
            0,
            self::MAX_PENDING_PREFETCH
        );

        $this->pending = array_fill_keys($customdata, true);
        $this->prefetchcomplete = count($customdata) < self::MAX_PENDING_PREFETCH;
    }

    /**
     * Courses carrying a time-based criterion that falls due within the horizon.
     *
     * @param int $cursor  Only courses with a higher ID are returned.
     * @param int $horizon Latest due time being planned for.
     * @return int[] Ordered course IDs, at most MAX_COURSES_PER_RUN of them.
     */
    protected function eligible_course_ids(int $cursor, int $horizon): array {
        global $DB;

        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.course <> :siteid AND cc.course > :cursor
                AND ((cc.criteriatype = :datetype AND cc.timeend > 0 AND cc.timeend <= :horizon)
                  OR (cc.criteriatype = :durationtype AND cc.enrolperiod > 0))
           ORDER BY cc.course ASC",
            [
                'siteid' => SITEID,
                'cursor' => $cursor,
                'datetype' => COMPLETION_CRITERIA_TYPE_DATE,
                'horizon' => $horizon,
                'durationtype' => COMPLETION_CRITERIA_TYPE_DURATION,
            ],
            0,
            self::MAX_COURSES_PER_RUN
        );

        return array_map('intval', $courseids);
    }

    /**
     * Queue the due bookings of one course, up to the remaining budget.
     *
     * @param int $courseid Course to plan.
     * @param int $now      Current time.
     * @param int $horizon  Latest due time being planned for.
     * @param int $budget   Maximum number of tasks to queue.
     * @return int Number of tasks queued.
     */
    protected function schedule_course(int $courseid, int $now, int $horizon, int $budget): int {
        global $DB;

        [$typesql, $params] = $DB->get_in_or_equal(
            [COMPLETION_CRITERIA_TYPE_DATE, COMPLETION_CRITERIA_TYPE_DURATION],
            SQL_PARAMS_NAMED,
            'ct'
        );
        $params['course'] = $courseid;
        $criteria = $DB->get_records_select(
            'course_completion_criteria',
            "course = :course AND criteriatype $typesql",
            $params,
            'id ASC'
        );

        $queued = 0;
        foreach ($criteria as $criterion) {
            $limit = $budget - $queued;
            if ($limit <= 0) {
                break;
            }

            if ((int)$criterion->criteriatype === COMPLETION_CRITERIA_TYPE_DATE) {
                $queued += $this->schedule_date_criterion($courseid, $criterion, $now, $horizon, $limit);
            } else {
                $queued += $this->schedule_duration_criterion($courseid, $criterion, $now, $horizon, $limit);
            }
        }

        return $queued;
    }

    /**
     * Queue bookings for a date criterion, which falls due for everyone at once.
     *
     * @param int       $courseid  Course ID.
     * @param \stdClass $criterion Criterion record.
     * @param int       $now       Current time.
     * @param int       $horizon   Latest due time being planned for.
     * @param int       $budget    Maximum number of tasks to queue.
     * @return int Number of tasks queued.
     */
    protected function schedule_date_criterion(int $courseid, \stdClass $criterion, int $now, int $horizon, int $budget): int {
        global $DB;

        $duetime = (int)$criterion->timeend;
        if ($duetime <= 0 || $duetime > $horizon) {
            return 0;
        }

        [$enrolledsql, $params] = get_enrolled_sql(\context_course::instance($courseid), '', 0, true);
        $params['courseid'] = $courseid;
        $params['criteriaid'] = (int)$criterion->id;

        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
             LEFT JOIN {course_completions} cco
                    ON cco.userid = enrolled.id AND cco.course = :courseid
             LEFT JOIN {course_completion_crit_compl} ccc
                    ON ccc.userid = enrolled.id AND ccc.criteriaid = :criteriaid
                 WHERE (cco.timecompleted IS NULL OR cco.timecompleted = 0)
                   AND ccc.id IS NULL
              ORDER BY enrolled.id ASC";

        $queued = 0;
        $recordset = $DB->get_recordset_sql($sql, $params, 0, $budget);
        foreach ($recordset as $record) {
            if ($this->queue_due_task($courseid, (int)$record->userid, $duetime, $now)) {
                $queued++;
            }
        }
        $recordset->close();

        return $queued;
    }

    /**
     * Queue bookings for a duration criterion, which falls due per user.
     *
     * The earliest enrolment wins, and an enrolment without a start date counts from
     * its creation time; both rules match completion_criteria_duration::cron(). The
     * horizon is applied to the enrolment time rather than to the computed due time,
     * so no arithmetic is performed on the aggregate.
     *
     * @param int       $courseid  Course ID.
     * @param \stdClass $criterion Criterion record.
     * @param int       $now       Current time.
     * @param int       $horizon   Latest due time being planned for.
     * @param int       $budget    Maximum number of tasks to queue.
     * @return int Number of tasks queued.
     */
    protected function schedule_duration_criterion(int $courseid, \stdClass $criterion, int $now, int $horizon, int $budget): int {
        global $DB;

        $enrolperiod = (int)$criterion->enrolperiod;
        if ($enrolperiod <= 0) {
            return 0;
        }

        [$enrolledsql, $params] = get_enrolled_sql(\context_course::instance($courseid), '', 0, true);
        $params['courseid'] = $courseid;
        $params['courseid2'] = $courseid;
        $params['criteriaid'] = (int)$criterion->id;
        $params['latest'] = $horizon - $enrolperiod;

        $started = 'MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END)';

        $sql = "SELECT enrolled.id AS userid, $started AS timeenrolled
                  FROM ($enrolledsql) enrolled
                  JOIN {user_enrolments} ue ON ue.userid = enrolled.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
             LEFT JOIN {course_completions} cco
                    ON cco.userid = enrolled.id AND cco.course = :courseid2
             LEFT JOIN {course_completion_crit_compl} ccc
                    ON ccc.userid = enrolled.id AND ccc.criteriaid = :criteriaid
                 WHERE (cco.timecompleted IS NULL OR cco.timecompleted = 0)
                   AND ccc.id IS NULL
              GROUP BY enrolled.id
                HAVING $started > 0 AND $started <= :latest
              ORDER BY enrolled.id ASC";

        $queued = 0;
        $recordset = $DB->get_recordset_sql($sql, $params, 0, $budget);
        foreach ($recordset as $record) {
            $duetime = (int)$record->timeenrolled + $enrolperiod;
            if ($this->queue_due_task($courseid, (int)$record->userid, $duetime, $now)) {
                $queued++;
            }
        }
        $recordset->close();

        return $queued;
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
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @param int $duetime  Time the criterion falls due.
     * @param int $now      Current time.
     * @return bool Whether a task was queued.
     */
    protected function queue_due_task(int $courseid, int $userid, int $duetime, int $now): bool {
        $customdata = (object)[
            'courseid' => $courseid,
            'duetime' => $duetime,
            'userid' => $userid,
        ];
        $key = json_encode($customdata);

        if (isset($this->pending[$key])) {
            return false;
        }

        $task = new book_due_completion_task();
        $task->set_custom_data($customdata);
        $task->set_next_run_time(max($now, $duetime) + ($userid % self::JITTER_WINDOW));

        // With an incomplete prefetch the queue itself has to be asked, at the cost of
        // one query per enqueue. queue_adhoc_task() returns false when it finds a twin.
        if (\core\task\manager::queue_adhoc_task($task, !$this->prefetchcomplete) === false) {
            return false;
        }
        $this->pending[$key] = true;

        return true;
    }
}
