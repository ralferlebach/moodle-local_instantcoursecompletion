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
use local_instantcoursecompletion\scope_resolver;

/**
 * Scheduled reconcile task.
 */
class reconcile_task extends \core\task\scheduled_task {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /** @var string Config key holding the ID of the last fully processed course. */
    protected const CURSOR = 'reconcilecursor';

    /** @var int Upper bound on the (course, user) pairs evaluated in one run. */
    protected const MAX_PAIRS_PER_RUN = 5000;

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
     * Progress is kept in a cursor over course IDs, so consecutive runs advance
     * through the site instead of restarting. The cursor wraps once the last course
     * has been reached.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config(self::COMPONENT, 'reconcile_enabled')) {
            return;
        }

        $logging = (bool)get_config(self::COMPONENT, 'enablelogging');
        $cursor = (int)get_config(self::COMPONENT, self::CURSOR);
        $budget = self::MAX_PAIRS_PER_RUN;
        $courseids = $this->eligible_course_ids($cursor);

        if (empty($courseids)) {
            $this->set_cursor(0);
            if ($logging) {
                mtrace('local_instantcoursecompletion reconcile_task: no eligible courses, cursor reset.');
            }
            return;
        }

        $booked = 0;
        $courses = 0;
        $exhausted = false;

        foreach ($courseids as $courseid) {
            $limit = $budget;
            [$coursebooked, $processed] = $this->process_course((int)$courseid, $limit);
            $booked += $coursebooked;
            $budget -= $processed;
            $courses++;

            if ($processed >= $limit) {
                // The course may hold further users; resume from the same course next run.
                $exhausted = true;
                break;
            }

            $this->set_cursor((int)$courseid);
            if ($budget <= 0) {
                $exhausted = true;
                break;
            }
        }

        if (!$exhausted && count($courseids) < self::MAX_COURSES_PER_RUN) {
            // The last course of the site has been reached; start over next run.
            $this->set_cursor(0);
        }

        if ($logging) {
            mtrace('local_instantcoursecompletion reconcile_task:'
                . ' courses=' . $courses
                . ' booked=' . $booked
                . ' remainingbudget=' . max(0, $budget));
        }
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
     * Return the next slice of courses that have completion criteria configured.
     *
     * The scope filter is applied in PHP rather than as an IN clause, so the query
     * never carries an unbounded parameter list.
     *
     * @param int $cursor Only courses with a higher ID are returned.
     * @return int[] Ordered course IDs, at most MAX_COURSES_PER_RUN of them.
     */
    protected function eligible_course_ids(int $cursor): array {
        global $DB;

        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.course <> :siteid AND cc.course > :cursor
           ORDER BY cc.course ASC",
            ['siteid' => SITEID, 'cursor' => $cursor],
            0,
            self::MAX_COURSES_PER_RUN
        );

        if (scope_resolver::get_mode() === scope_resolver::SCOPE_ALL) {
            return array_map('intval', $courseids);
        }

        $scope = scope_resolver::get_scope_course_ids();
        return array_values(array_filter(
            array_map('intval', $courseids),
            static fn($courseid) => isset($scope[$courseid])
        ));
    }

    /**
     * Evaluate pending completions for one course, up to the remaining budget.
     *
     * Only users whose enrolment is active right now are considered; get_enrolled_sql()
     * applies enrolment status, start and end dates and the plugin-enabled state.
     *
     * @param int $courseid Course to process.
     * @param int $budget   Maximum number of users to evaluate.
     * @return int[] Two elements: bookings made, users processed.
     */
    protected function process_course(int $courseid, int $budget): array {
        global $DB;

        [$enrolledsql, $params] = get_enrolled_sql(\context_course::instance($courseid), '', 0, true);
        $params['courseid'] = $courseid;

        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
             LEFT JOIN {course_completions} cco
                    ON cco.userid = enrolled.id AND cco.course = :courseid
                 WHERE cco.timecompleted IS NULL OR cco.timecompleted = 0
              ORDER BY enrolled.id ASC";

        $booked = 0;
        $processed = 0;
        $recordset = $DB->get_recordset_sql($sql, $params, 0, $budget);

        foreach ($recordset as $record) {
            $processed++;
            try {
                if (completion_booker::book($courseid, (int)$record->userid)) {
                    $booked++;
                }
            } catch (\Throwable $e) {
                debugging(
                    'local_instantcoursecompletion reconcile_task:'
                    . " course={$courseid} user={$record->userid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
        $recordset->close();

        return [$booked, $processed];
    }
}
