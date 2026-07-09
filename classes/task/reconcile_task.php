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
 * Optional scheduled safety-net task.
 *
 * Catches completions that event triggers cannot detect (e.g. date / duration
 * criteria) by periodically re-checking in-scope courses. Disabled by default;
 * runs only when local_instantcoursecompletion/reconcile_enabled is set.
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
    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:reconcile', 'local_instantcoursecompletion');
    }

    /**
     * Execute the reconcile pass.
     *
     * Iterates over all in-scope, completion-enabled courses that have at least
     * one completion criterion configured. For each such course, retrieves the
     * active enrolled users who have not yet completed the course and calls
     * completion_booker::book() for each (course, user) pair.
     *
     * This catches criterion completions that event triggers cannot detect
     * reliably — notably date-based and duration-based criteria, whose conditions
     * become true as time passes rather than in response to a user action.
     *
     * The task is idempotent: book() guards against double-booking via an
     * is_course_complete() check before any write.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_instantcoursecompletion', 'reconcile_enabled')) {
            // Feature off by default — exit cheaply without touching the DB.
            return;
        }

        $logging = (bool)get_config('local_instantcoursecompletion', 'enablelogging');

        $courseids = $this->eligible_course_ids();
        if (empty($courseids)) {
            if ($logging) {
                mtrace('local_instantcoursecompletion reconcile_task: no eligible courses.');
            }
            return;
        }

        $booked  = 0;
        $skipped = 0;

        foreach ($courseids as $courseid) {
            $counts  = $this->process_course((int)$courseid);
            $booked  += $counts[0];
            $skipped += $counts[1];
        }

        if ($logging) {
            mtrace('local_instantcoursecompletion reconcile_task:'
                . ' courses=' . count($courseids)
                . ' booked=' . $booked
                . ' skipped=' . $skipped);
        }
    }

    /**
     * Return the IDs of courses eligible for reconciliation.
     *
     * Eligible means: completion tracking enabled, at least one criterion
     * configured, and within the configured observer scope.
     *
     * For SCOPE_ALL, the DB is queried directly because
     * scope_resolver::get_scope_course_ids() intentionally returns an empty
     * array in that mode (materialising every course ID is wasteful on the
     * request path; here it is done once per run of the scheduled task).
     *
     * @return int[]
     */
    protected function eligible_course_ids(): array {
        global $DB;

        if (scope_resolver::get_mode() === scope_resolver::SCOPE_ALL) {
            return $DB->get_fieldset_sql(
                "SELECT DISTINCT cc.course
                   FROM {course_completion_criteria} cc
                   JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
                  WHERE cc.course <> :siteid",
                ['siteid' => SITEID]
            );
        }

        // SCOPE_CATEGORIES / SCOPE_ADELE — use the cached resolver, then filter
        // to courses that actually have completion enabled and criteria configured.
        $scopeset = scope_resolver::get_scope_course_ids();
        if (empty($scopeset)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($scopeset), SQL_PARAMS_NAMED, 'scope');
        $inparams['siteid'] = SITEID;

        return $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.course <> :siteid AND cc.course {$insql}",
            $inparams
        );
    }

    /**
     * Reconcile course completions for a single course.
     *
     * Queries active enrolled users who have not yet completed the course and
     * calls completion_booker::book() for each. Exceptions per user are caught
     * and logged (DEBUG_DEVELOPER) so a single failure does not abort the pass.
     *
     * @param int $courseid Course to process.
     * @return int[] Two-element array: [booked, skipped].
     */
    protected function process_course(int $courseid): array {
        global $DB;

        // Active enrolled users who have not yet completed this course.
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
          LEFT JOIN {course_completions} co
                     ON co.userid = ue.userid AND co.course = :courseid2
              WHERE ue.status = 0
                AND e.status = 0
                AND (co.timecompleted IS NULL OR co.timecompleted = 0)",
            ['courseid' => $courseid, 'courseid2' => $courseid]
        );

        $booked  = 0;
        $skipped = 0;

        foreach ($userids as $userid) {
            try {
                if (completion_booker::book($courseid, (int)$userid)) {
                    $booked++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                debugging(
                    'local_instantcoursecompletion reconcile_task:'
                    . " course={$courseid} user={$userid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return [$booked, $skipped];
    }
}
