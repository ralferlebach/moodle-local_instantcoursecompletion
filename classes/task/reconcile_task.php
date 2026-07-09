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
 * Scheduled safety-net task for completions no event can announce.
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
 * Scheduled reconcile task.
 */
class reconcile_task extends \core\task\scheduled_task {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /** @var string Config key holding the position to resume from. */
    protected const CURSOR = 'reconcilecursor';

    /** @var int Upper bound on the courses inspected in one run. */
    protected const MAX_COURSES_PER_RUN = 200;

    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:reconcile', self::COMPONENT);
    }

    /**
     * Evaluate pending completions for a bounded slice of the configured scope.
     *
     * The cursor carries a course and the last user examined inside it. Progress is
     * measured in users examined, never in completions booked: the users this task
     * exists for are precisely the ones that do not complete yet, and a run that only
     * advanced on success would examine the same slice forever and never reach the
     * courses behind it.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config(self::COMPONENT, 'reconcile_enabled')) {
            return;
        }

        $logging = (bool)get_config(self::COMPONENT, 'enablelogging');
        $budget = self::max_users_per_run();

        $cursor = $this->get_cursor();
        $courseids = $this->eligible_course_ids($cursor['courseid']);
        if (empty($courseids)) {
            $this->set_cursor(0, 0);
            return;
        }

        $scanned = 0;
        $booked = 0;
        $failed = 0;
        $exhausted = false;

        foreach ($courseids as $courseid) {
            if ($scanned >= $budget) {
                $exhausted = true;
                break;
            }

            if (!scope_resolver::is_in_scope($courseid)) {
                $this->set_cursor($courseid + 1, 0);
                continue;
            }

            $fromuserid = ($courseid === $cursor['courseid']) ? $cursor['lastuserid'] : 0;
            $result = $this->process_course($courseid, $fromuserid, $budget - $scanned);

            $scanned += $result['scanned'];
            $booked += $result['booked'];
            $failed += $result['failed'];

            if ($result['more']) {
                $this->set_cursor($courseid, $result['lastuserid']);
                $exhausted = true;
                break;
            }

            $this->set_cursor($courseid + 1, 0);
        }

        if (!$exhausted && count($courseids) < self::MAX_COURSES_PER_RUN) {
            // The last course of the site has been reached; start over next run.
            $this->set_cursor(0, 0);
        }

        if ($logging || $failed > 0) {
            mtrace('local_instantcoursecompletion reconcile_task:'
                . ' courses=' . count($courseids)
                . ' scanned=' . $scanned
                . ' booked=' . $booked
                . ' failed=' . $failed);
        }
    }

    /**
     * Upper bound on the users examined in one run.
     *
     * @return int
     */
    protected static function max_users_per_run(): int {
        $max = (int)get_config(self::COMPONENT, 'reconcilebudget');
        return $max > 0 ? $max : 5000;
    }

    /**
     * The position the next run resumes from.
     *
     * @return array{courseid: int, lastuserid: int}
     */
    protected function get_cursor(): array {
        $raw = get_config(self::COMPONENT, self::CURSOR);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'courseid' => (int)($data['courseid'] ?? 0),
            'lastuserid' => (int)($data['lastuserid'] ?? 0),
        ];
    }

    /**
     * Persist the position the next run resumes from.
     *
     * @param int $courseid   First course to examine, or 0 to start over.
     * @param int $lastuserid Last user examined in that course.
     * @return void
     */
    protected function set_cursor(int $courseid, int $lastuserid): void {
        set_config(self::CURSOR, json_encode([
            'courseid' => $courseid,
            'lastuserid' => $lastuserid,
        ]), self::COMPONENT);
    }

    /**
     * Return the next slice of courses that have completion criteria configured.
     *
     * The scope filter is applied per course in PHP rather than as an IN clause, so the
     * query never carries an unbounded parameter list.
     *
     * @param int $fromcourseid Lowest course ID to return; the cursor may point inside it.
     * @return int[] Ordered course IDs, at most MAX_COURSES_PER_RUN of them.
     */
    protected function eligible_course_ids(int $fromcourseid): array {
        global $DB;

        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.course <> :siteid AND cc.course >= :fromcourseid
           ORDER BY cc.course ASC",
            ['siteid' => SITEID, 'fromcourseid' => $fromcourseid],
            0,
            self::MAX_COURSES_PER_RUN
        );

        return array_map('intval', $courseids);
    }

    /**
     * Evaluate pending completions for one course, resuming after the given user.
     *
     * Only tracked users are considered: get_enrolled_sql() applies enrolment status,
     * start and end dates, the plugin-enabled state, and the capability that decides
     * whose progress Moodle follows at all.
     *
     * @param int $courseid   Course to process.
     * @param int $fromuserid Only users with a higher ID are examined.
     * @param int $budget     Maximum number of users to examine.
     * @return array{scanned: int, booked: int, failed: int, lastuserid: int, more: bool}
     */
    protected function process_course(int $courseid, int $fromuserid, int $budget): array {
        global $DB;

        // The course, its completion_info and its criteria are read once for the slice.
        $booker = course_booker::for_course($courseid);
        if ($booker === null) {
            return ['scanned' => 0, 'booked' => 0, 'failed' => 0, 'lastuserid' => $fromuserid, 'more' => false];
        }

        $context = \context_course::instance($courseid);
        [$enrolledsql, $params] = get_enrolled_sql($context, due_scheduler::TRACKED_CAPABILITY, 0, true);
        $params['courseid'] = $courseid;
        $params['fromuserid'] = $fromuserid;

        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
             LEFT JOIN {course_completions} cco
                    ON cco.userid = enrolled.id AND cco.course = :courseid
                 WHERE (cco.timecompleted IS NULL OR cco.timecompleted = 0)
                   AND enrolled.id > :fromuserid
              ORDER BY enrolled.id ASC";

        $scanned = 0;
        $booked = 0;
        $failed = 0;
        $lastuserid = $fromuserid;

        $recordset = $DB->get_recordset_sql($sql, $params, 0, $budget);
        foreach ($recordset as $record) {
            $scanned++;
            $lastuserid = (int)$record->userid;

            try {
                if ($booker->book($lastuserid)) {
                    $booked++;
                }
            } catch (\Throwable $e) {
                $failed++;
                debugging(
                    'local_instantcoursecompletion reconcile_task:'
                    . " course={$courseid} user={$lastuserid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
        $recordset->close();

        // A full page means there may be more users behind it.
        return [
            'scanned' => $scanned,
            'booked' => $booked,
            'failed' => $failed,
            'lastuserid' => $lastuserid,
            'more' => $scanned >= $budget,
        ];
    }
}
