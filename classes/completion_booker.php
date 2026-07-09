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
 * Evaluates and books a course completion for a single (course, user) pair.
 *
 * This class is a thin wrapper around Moodle's own completion API. It does NOT
 * re-implement completion criteria — it evaluates existing criteria instantly
 * instead of waiting for the scheduled task completion_regular_task.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Course-completion booker.
 */
class completion_booker {
    /**
     * Evaluate and, if all criteria are met, book the course completion for the user.
     *
     * Each completion criterion is evaluated via completion_criteria::review() with
     * $mark = false. This reads the criterion's underlying data source (e.g.
     * course_modules_completion for activity criteria, grade_grades for grade criteria)
     * and returns a boolean without writing any criterion-level completion records.
     * Criterion-level record management is left to core's completion_regular_task
     * (Lesart A — no new completion logic in this plugin).
     *
     * The results are aggregated using the configured ALL / ANY method per criteria
     * type and overall. If all conditions are satisfied,
     * completion_completion::mark_complete() is called, which writes to
     * course_completions and fires course_completed.
     *
     * Guard order:
     *   1. Course completion must be enabled.
     *   2. Skip if the user is already marked complete (idempotent).
     *   3. Skip if no completion criteria are configured.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return bool True if a course completion was (or already is) booked; false otherwise.
     */
    public static function book(int $courseid, int $userid): bool {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ($courseid <= 0 || $userid <= 0 || $courseid == SITEID) {
            return false;
        }

        $course = get_course($courseid);
        $info = new \completion_info($course);

        // Guard 1: completion must be enabled for this course.
        if (!$info->is_enabled()) {
            return false;
        }

        // Guard 2: already complete — nothing to do (idempotent).
        if ($info->is_course_complete($userid)) {
            self::log($courseid, $userid, 'already-complete');
            return true;
        }

        // Guard 3: the course must have completion criteria configured.
        $criteria = $info->get_criteria();
        if (empty($criteria)) {
            return false;
        }

        // Evaluate each criterion with $mark = false so review() only checks the
        // underlying data source (course_modules_completion, grade_grades, …) without
        // writing criterion-level completion records.  Group results by criteriatype
        // so the per-type aggregation can be applied in the next step.
        $completion = new \completion_completion(['userid' => $userid, 'course' => $courseid]);
        $typecriteria = [];
        foreach ($criteria as $criterion) {
            $type = (int)$criterion->criteriatype;
            if (!isset($typecriteria[$type])) {
                $typecriteria[$type] = [];
            }
            $typecriteria[$type][] = (bool)$criterion->review($completion, false);
        }

        // Per-type aggregation: is each group of criteria satisfied?
        $typesatisfied = [];
        foreach ($typecriteria as $type => $results) {
            $values = array_values($results);
            if ($info->get_aggregation_method($type) == COMPLETION_AGGREGATION_ALL) {
                $typesatisfied[$type] = !in_array(false, $values, true);
            } else {
                $typesatisfied[$type] = in_array(true, $values, true);
            }
        }

        if (empty($typesatisfied)) {
            return false;
        }

        // Overall aggregation across types.
        if ($info->get_aggregation_method() == COMPLETION_AGGREGATION_ALL) {
            $allmet = !in_array(false, $typesatisfied, true);
        } else {
            $allmet = in_array(true, $typesatisfied, true);
        }

        if (!$allmet) {
            self::log($courseid, $userid, 'criteria-not-met');
            return false;
        }

        // All criteria satisfied per the configured aggregation.
        // Mark the course complete — inserts/updates course_completions and fires
        // core\event\course_completed, which triggers downstream processes.
        $cc = new \completion_completion(['userid' => $userid, 'course' => $courseid]);
        $cc->mark_complete();
        self::log($courseid, $userid, 'booked');
        return true;
    }

    /**
     * Optional logging, gated by the enablelogging setting.
     *
     * When outcome is 'booked', also fires a completion_booked event so that the
     * admin report (Site administration > Reports > Accelerated completions) can
     * surface accelerated completions from the standard logstore.
     *
     * @param int    $courseid Course ID.
     * @param int    $userid   User ID.
     * @param string $outcome  Short outcome tag ('booked' | 'criteria-not-met' | 'already-complete').
     * @return void
     */
    protected static function log(int $courseid, int $userid, string $outcome): void {
        if (!get_config('local_instantcoursecompletion', 'enablelogging')) {
            return;
        }
        mtrace("local_instantcoursecompletion: course={$courseid} user={$userid} outcome={$outcome}");
        if ($outcome === 'booked') {
            $event = event\completion_booked::create([
                'objectid'      => $courseid,
                'context'       => \context_course::instance($courseid),
                'relateduserid' => $userid,
            ]);
            $event->trigger();
        }
    }
}
