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

use local_instantcoursecompletion\completion_booker;
use local_instantcoursecompletion\completion_course_repository;
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

    /** @var int Seconds of wall-clock time one run books before persisting its cursor and stopping. */
    protected const MAX_RUNTIME = 30;

    /**
     * The wall-clock budget for one run.
     *
     * A seam for tests: when a run hits it, process_course() reports there is more to do,
     * the cursor is persisted, and the next scheduled run resumes there.
     *
     * @return int Seconds.
     */
    protected function max_runtime(): int {
        return self::MAX_RUNTIME;
    }

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
        $courseids = completion_course_repository::get_course_ids_after($cursor['courseid'], self::MAX_COURSES_PER_RUN);
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

            try {
                $result = $this->process_course($courseid, $fromuserid, $budget - $scanned);
            } catch (\dml_exception | \coding_exception $e) {
                // A database or programming error, as opposed to one criterion behaving
                // oddly for one user, is not something retrying the next user fixes. The
                // cursor is left where it was, so the same course is retried next run, and
                // the exception propagates so cron surfaces the failure instead of a scan
                // silently limping through the rest of the site.
                $failed++;
                if ($logging || $failed > 0) {
                    mtrace('local_instantcoursecompletion reconcile_task:'
                        . " aborted on course={$courseid}: " . $e->getMessage());
                }
                throw $e;
            }

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

        // The course, its completion_info and its criteria are read once here and reused
        // for every user of this page. Booking each user through completion_booker::book()
        // instead would reload the course and the criteria set per user, which is the very
        // fan-out this task is meant to drain, not create.
        $booker = completion_booker::for_course($courseid);
        if (!$booker) {
            return [
                'scanned' => 0,
                'booked' => 0,
                'failed' => 0,
                'lastuserid' => $fromuserid,
                'more' => false,
            ];
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
        $started = microtime(true);
        $timedout = false;

        $recordset = $DB->get_recordset_sql($sql, $params, 0, $budget);
        foreach ($recordset as $record) {
            $scanned++;
            $lastuserid = (int)$record->userid;

            try {
                if ($booker->book_user($lastuserid)) {
                    $booked++;
                }
            } catch (\dml_exception | \coding_exception $e) {
                $recordset->close();
                throw $e;
            } catch (\Throwable $e) {
                $failed++;
                debugging(
                    'local_instantcoursecompletion reconcile_task:'
                    . " course={$courseid} user={$lastuserid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

            if (microtime(true) - $started > $this->max_runtime()) {
                $timedout = true;
                break;
            }
        }
        $recordset->close();

        // A full page, or a run that ran out of time, may have users behind it; either way
        // the cursor is left at the last user examined so the next run resumes there.
        return [
            'scanned' => $scanned,
            'booked' => $booked,
            'failed' => $failed,
            'lastuserid' => $lastuserid,
            'more' => $timedout || $scanned >= $budget,
        ];
    }
}
