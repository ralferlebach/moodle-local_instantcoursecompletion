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

        $scanned = 0;
        $planned = 0;
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

            // An enrolment observer may be planning this course right now.
            $lock = due_scheduler::course_lock($courseid, 0);
            if (!$lock) {
                $exhausted = true;
                break;
            }

            $resume = ($courseid === $cursor['courseid'])
                ? [$cursor['criteriaid'], $cursor['lastuserid']]
                : [0, 0];

            try {
                $result = $this->schedule_course($courseid, $resume[0], $resume[1], $now, $horizon, $budget - $scanned);
            } finally {
                $lock->release();
            }

            $planned += $result['planned'];
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
                . ' planned=' . $planned);
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
     * @return array{planned: int, scanned: int, stopped: null|int[]} Position to resume at, or null when done.
     */
    protected function schedule_course(
        int $courseid,
        int $resumecriteriaid,
        int $resumeuserid,
        int $now,
        int $horizon,
        int $budget
    ): array {
        $planned = 0;
        $scanned = 0;

        foreach (due_scheduler::time_criteria($courseid) as $criterion) {
            $criteriaid = (int)$criterion->id;
            if ($criteriaid < $resumecriteriaid) {
                continue;
            }
            $fromuserid = ($criteriaid === $resumecriteriaid) ? $resumeuserid : 0;

            if ($scanned >= $budget) {
                return ['planned' => $planned, 'scanned' => $scanned, 'stopped' => [$criteriaid, $fromuserid]];
            }

            $remaining = $budget - $scanned;
            $result = $this->schedule_criterion($courseid, $criterion, $fromuserid, $now, $horizon, $remaining);
            $planned += $result['planned'];
            $scanned += $result['scanned'];

            if ($result['more']) {
                return ['planned' => $planned, 'scanned' => $scanned, 'stopped' => [$criteriaid, $result['lastuserid']]];
            }
        }

        return ['planned' => $planned, 'scanned' => $scanned, 'stopped' => null];
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
     * @return array{planned: int, scanned: int, lastuserid: int, more: bool}
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

        $criteriaid = (int)$criterion->id;
        $idle = ['planned' => 0, 'scanned' => 0, 'lastuserid' => $fromuserid, 'more' => false];
        $enrolperiod = (int)$criterion->enrolperiod;
        $duetime = (int)$criterion->timeend;

        if ((int)$criterion->criteriatype === COMPLETION_CRITERIA_TYPE_DATE) {
            // The whole course falls due at one instant, so it gets one paged batch task
            // rather than one task per learner. No enrolment row is examined here.
            if ($duetime <= 0 || $duetime > $horizon) {
                return $idle;
            }

            // Ask for a single row: a criterion nobody owes any more needs no batch.
            if (empty(due_scheduler::date_due_user_ids($courseid, $criteriaid, 0, 1))) {
                return ['planned' => 0, 'scanned' => 1, 'lastuserid' => 0, 'more' => false];
            }

            $planned = due_scheduler::queue_batch($courseid, $criteriaid, $duetime) ? 1 : 0;

            return ['planned' => $planned, 'scanned' => 1, 'lastuserid' => 0, 'more' => false];
        }

        // A duration criterion falls due per learner, so it is planned per learner.
        if ($enrolperiod <= 0) {
            return $idle;
        }
        $latest = $horizon - $enrolperiod;
        [$sql, $params] = $this->duration_user_sql($courseid, $criteriaid, $fromuserid, $latest);

        $planned = 0;
        $scanned = 0;
        $lastuserid = $fromuserid;

        $recordset = $DB->get_recordset_sql($sql, $params, 0, $limit);
        foreach ($recordset as $record) {
            $scanned++;
            $lastuserid = (int)$record->userid;
            $due = (int)$record->timeenrolled + $enrolperiod;

            if (due_scheduler::queue($courseid, $criteriaid, $lastuserid, $due, $now)) {
                $planned++;
            }
        }
        $recordset->close();

        // A full page means there may be more rows behind it.
        return ['planned' => $planned, 'scanned' => $scanned, 'lastuserid' => $lastuserid, 'more' => $scanned >= $limit];
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
                  JOIN {user} u ON u.id = enrolled.id AND u.deleted = 0 AND u.suspended = 0
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
