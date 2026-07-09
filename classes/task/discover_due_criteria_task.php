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

use local_instantcoursecompletion\due_scheduler;
use local_instantcoursecompletion\scope_resolver;

/**
 * Due-criteria discovery task.
 */
class discover_due_criteria_task extends \core\task\scheduled_task {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /** @var string Config key holding the position to resume from. */
    protected const CURSOR = 'schedulecursor';

    /** @var int Upper bound on the courses inspected in one run. */
    protected const MAX_COURSES_PER_RUN = 200;

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
     * Nothing is evaluated here. The run decides which (course, user, due time) triples
     * need an ad-hoc task and leaves the evaluation to that task.
     *
     * A run is capped by the number of enrolment rows it examines, never by the number
     * of tasks it queues: a row that already has a task still consumes budget and still
     * advances the cursor. Otherwise a course whose first rows are all de-duplicated
     * would look finished, and the users behind them would never be planned.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;

        if (!due_scheduler::enabled()) {
            return;
        }
        require_once($CFG->libdir . '/completionlib.php');

        $now = time();
        $horizon = $now + due_scheduler::horizon_seconds();
        $budget = due_scheduler::max_tasks_per_run();
        $logging = (bool)get_config(self::COMPONENT, 'enablelogging');

        $cursor = $this->get_cursor();
        $courseids = $this->eligible_course_ids($cursor['courseid'], $horizon);
        if (empty($courseids)) {
            $this->set_cursor(0, 0, 0);
            return;
        }

        $this->load_pending_tasks($horizon);

        $scanned = 0;
        $queued = 0;
        $exhausted = false;

        foreach ($courseids as $courseid) {
            if ($scanned >= $budget) {
                $exhausted = true;
                break;
            }

            if (!scope_resolver::is_in_scope($courseid)) {
                $this->set_cursor($courseid + 1, 0, 0);
                continue;
            }

            $resume = ($courseid === $cursor['courseid'])
                ? [$cursor['criteriaid'], $cursor['lastuserid']]
                : [0, 0];

            $result = $this->schedule_course($courseid, $resume[0], $resume[1], $now, $horizon, $budget - $scanned);
            $queued += $result['queued'];
            $scanned += $result['scanned'];

            if ($result['stopped'] !== null) {
                $this->set_cursor($courseid, $result['stopped'][0], $result['stopped'][1]);
                $exhausted = true;
                break;
            }

            $this->set_cursor($courseid + 1, 0, 0);
        }

        if (!$exhausted && count($courseids) < self::MAX_COURSES_PER_RUN) {
            // The last course of the site has been reached; start over next run.
            $this->set_cursor(0, 0, 0);
        }

        if ($logging) {
            mtrace('local_instantcoursecompletion discover_due_criteria_task:'
                . ' courses=' . count($courseids)
                . ' scanned=' . $scanned
                . ' queued=' . $queued);
        }
    }

    /**
     * The position the next run resumes from.
     *
     * @return array{courseid: int, criteriaid: int, lastuserid: int}
     */
    protected function get_cursor(): array {
        $raw = get_config(self::COMPONENT, self::CURSOR);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'courseid' => (int)($data['courseid'] ?? 0),
            'criteriaid' => (int)($data['criteriaid'] ?? 0),
            'lastuserid' => (int)($data['lastuserid'] ?? 0),
        ];
    }

    /**
     * Persist the position the next run resumes from.
     *
     * @param int $courseid   First course to examine, or 0 to start over.
     * @param int $criteriaid First criterion of that course, or 0 for all of them.
     * @param int $lastuserid Last user examined for that criterion.
     * @return void
     */
    protected function set_cursor(int $courseid, int $criteriaid, int $lastuserid): void {
        set_config(self::CURSOR, json_encode([
            'courseid' => $courseid,
            'criteriaid' => $criteriaid,
            'lastuserid' => $lastuserid,
        ]), self::COMPONENT);
    }

    /**
     * Read the booking tasks already queued inside the horizon into memory.
     *
     * One query replaces the one that \core\task\manager would otherwise run per
     * enqueue. The read is capped, because the pending queue grows with the number of
     * due bookings and not with anything this task controls. When the cap is reached
     * the set is incomplete and the queue itself is asked instead.
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
                'latest' => $horizon + due_scheduler::JITTER_WINDOW,
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
     * @param int $fromcourseid Lowest course ID to return; the cursor may point inside it.
     * @param int $horizon      Latest due time being planned for.
     * @return int[] Ordered course IDs, at most MAX_COURSES_PER_RUN of them.
     */
    protected function eligible_course_ids(int $fromcourseid, int $horizon): array {
        global $DB;

        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.course <> :siteid AND cc.course >= :fromcourseid
                AND ((cc.criteriatype = :datetype AND cc.timeend > 0 AND cc.timeend <= :horizon)
                  OR (cc.criteriatype = :durationtype AND cc.enrolperiod > 0))
           ORDER BY cc.course ASC",
            [
                'siteid' => SITEID,
                'fromcourseid' => $fromcourseid,
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
     * Plan the due bookings of one course, resuming from the cursor.
     *
     * @param int $courseid         Course to plan.
     * @param int $resumecriteriaid Criterion to resume at, 0 for the first.
     * @param int $resumeuserid     Last user examined for that criterion.
     * @param int $now              Current time.
     * @param int $horizon          Latest due time being planned for.
     * @param int $budget           Maximum number of enrolment rows to examine.
     * @return array{queued: int, scanned: int, stopped: null|int[]} Position to resume at, or null when done.
     */
    protected function schedule_course(
        int $courseid,
        int $resumecriteriaid,
        int $resumeuserid,
        int $now,
        int $horizon,
        int $budget
    ): array {
        $queued = 0;
        $scanned = 0;

        foreach (due_scheduler::time_criteria($courseid) as $criterion) {
            $criteriaid = (int)$criterion->id;
            if ($criteriaid < $resumecriteriaid) {
                continue;
            }
            $fromuserid = ($criteriaid === $resumecriteriaid) ? $resumeuserid : 0;

            if ($scanned >= $budget) {
                return ['queued' => $queued, 'scanned' => $scanned, 'stopped' => [$criteriaid, $fromuserid]];
            }

            $remaining = $budget - $scanned;
            $result = $this->schedule_criterion($courseid, $criterion, $fromuserid, $now, $horizon, $remaining);
            $queued += $result['queued'];
            $scanned += $result['scanned'];

            if ($result['more']) {
                return ['queued' => $queued, 'scanned' => $scanned, 'stopped' => [$criteriaid, $result['lastuserid']]];
            }
        }

        return ['queued' => $queued, 'scanned' => $scanned, 'stopped' => null];
    }

    /**
     * Plan the due bookings of one criterion, resuming after the given user.
     *
     * @param int       $courseid   Course ID.
     * @param \stdClass $criterion  Criterion record.
     * @param int       $fromuserid Only users with a higher ID are examined.
     * @param int       $now        Current time.
     * @param int       $horizon    Latest due time being planned for.
     * @param int       $limit      Maximum number of enrolment rows to examine.
     * @return array{queued: int, scanned: int, lastuserid: int, more: bool}
     */
    protected function schedule_criterion(
        int $courseid,
        \stdClass $criterion,
        int $fromuserid,
        int $now,
        int $horizon,
        int $limit
    ): array {
        global $DB;

        $idle = ['queued' => 0, 'scanned' => 0, 'lastuserid' => $fromuserid, 'more' => false];
        $isdate = (int)$criterion->criteriatype === COMPLETION_CRITERIA_TYPE_DATE;
        $enrolperiod = (int)$criterion->enrolperiod;
        $duetime = (int)$criterion->timeend;

        if ($isdate) {
            if ($duetime <= 0 || $duetime > $horizon) {
                return $idle;
            }
            [$sql, $params] = $this->date_user_sql($courseid, (int)$criterion->id, $fromuserid);
        } else {
            if ($enrolperiod <= 0) {
                return $idle;
            }
            $latest = $horizon - $enrolperiod;
            [$sql, $params] = $this->duration_user_sql($courseid, (int)$criterion->id, $fromuserid, $latest);
        }

        $queued = 0;
        $scanned = 0;
        $lastuserid = $fromuserid;

        $recordset = $DB->get_recordset_sql($sql, $params, 0, $limit);
        foreach ($recordset as $record) {
            $scanned++;
            $lastuserid = (int)$record->userid;
            $due = $isdate ? $duetime : (int)$record->timeenrolled + $enrolperiod;

            if (due_scheduler::queue($courseid, $lastuserid, $due, $now, $this->pending, !$this->prefetchcomplete)) {
                $queued++;
            }
        }
        $recordset->close();

        // A full page means there may be more rows behind it.
        return ['queued' => $queued, 'scanned' => $scanned, 'lastuserid' => $lastuserid, 'more' => $scanned >= $limit];
    }

    /**
     * Tracked users of a course who still owe the given date criterion, after $fromuserid.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion ID.
     * @param int $fromuserid Only users with a higher ID are returned.
     * @return array{0: string, 1: array} SQL and parameters.
     */
    protected function date_user_sql(int $courseid, int $criteriaid, int $fromuserid): array {
        $context = \context_course::instance($courseid);
        [$enrolledsql, $params] = get_enrolled_sql($context, due_scheduler::TRACKED_CAPABILITY, 0, true);
        $params['courseid'] = $courseid;
        $params['criteriaid'] = $criteriaid;
        $params['fromuserid'] = $fromuserid;

        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
             LEFT JOIN {course_completions} cco
                    ON cco.userid = enrolled.id AND cco.course = :courseid
             LEFT JOIN {course_completion_crit_compl} ccc
                    ON ccc.userid = enrolled.id AND ccc.criteriaid = :criteriaid
                 WHERE (cco.timecompleted IS NULL OR cco.timecompleted = 0)
                   AND ccc.id IS NULL
                   AND enrolled.id > :fromuserid
              ORDER BY enrolled.id ASC";

        return [$sql, $params];
    }

    /**
     * Tracked users of a course whose duration criterion falls due by $latest, after $fromuserid.
     *
     * The earliest enrolment wins, and an enrolment without a start date counts from its
     * creation time; both rules match completion_criteria_duration::cron(). The horizon
     * is applied to the enrolment time rather than to the computed due time, so no
     * arithmetic is performed on the aggregate.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion ID.
     * @param int $fromuserid Only users with a higher ID are returned.
     * @param int $latest     Latest enrolment time that still falls due inside the horizon.
     * @return array{0: string, 1: array} SQL and parameters.
     */
    protected function duration_user_sql(int $courseid, int $criteriaid, int $fromuserid, int $latest): array {
        $context = \context_course::instance($courseid);
        [$enrolledsql, $params] = get_enrolled_sql($context, due_scheduler::TRACKED_CAPABILITY, 0, true);
        $params['courseid'] = $courseid;
        $params['courseid2'] = $courseid;
        $params['criteriaid'] = $criteriaid;
        $params['fromuserid'] = $fromuserid;
        $params['latest'] = $latest;

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
                   AND enrolled.id > :fromuserid
              GROUP BY enrolled.id
                HAVING $started > 0 AND $started <= :latest
              ORDER BY enrolled.id ASC";

        return [$sql, $params];
    }
}
