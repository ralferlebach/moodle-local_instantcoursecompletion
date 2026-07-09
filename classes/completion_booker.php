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
 * re-implement completion criteria — it re-runs the review that core normally
 * defers to the scheduled task \core\task\completion_regular_task, but scoped to
 * exactly one user and one course so it can happen instantly.
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
     * Evaluate and, if criteria are met, book the course completion for the user.
     *
     * Processing order:
     *   1. Course completion must be enabled.
     *   2. Skip if the user is already marked complete (idempotent).
     *   3. Skip if no completion criteria are configured.
     *   4. Pass 1 — review each criterion via completion_criteria::review(); marks
     *      criterion-level completions in the DB when their conditions are met.
     *   5. Pass 2 — course-level aggregation: re-reads the post-review DB state and
     *      applies the configured ALL / ANY aggregation per criteria type and across
     *      types. If all conditions are satisfied, calls
     *      completion_completion::mark_complete() to record the course completion and
     *      fire course_completed (which triggers downstream processes such as
     *      certificates, local_adele learning-path progress, etc.).
     *
     * Aggregation mirrors the logic applied by core's completion_regular_task but is
     * scoped to exactly one (course, user) pair, making it instantaneous and
     * low-cost. The aggregation method (COMPLETION_AGGREGATION_ALL or _ANY) is read
     * per criteria type and for the overall course via completion_info.
     *
     * Note: date- and duration-based criteria cannot be reliably detected via events
     * and remain the responsibility of core's scheduled task (or the optional
     * reconcile_task safety net provided by this plugin).
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

        // Pass 1: review each criterion for this user.
        // review() marks criterion-level completions in the DB when criteria are met.
        // Results are cached per type to avoid redundant DB queries within this pass.
        $cachedcompletions = [];
        foreach ($criteria as $criterion) {
            $type = (int)$criterion->criteriatype;
            if (!isset($cachedcompletions[$type])) {
                $cachedcompletions[$type] = $info->get_completions($userid, $type);
            }
            foreach ($cachedcompletions[$type] as $completion) {
                if ((int)$completion->criteriaid === (int)$criterion->id && !$completion->is_complete()) {
                    $criterion->review($completion);
                }
            }
        }

        // Early-out after review: core may have marked the course complete internally.
        if ($info->is_course_complete($userid)) {
            self::log($courseid, $userid, 'already-complete');
            return true;
        }

        // Pass 2: course-level aggregation.
        // Re-read criterion completions from DB — Pass 1 may have updated records.
        // Build: criteriatype => [criteriaid => is_complete].
        // Criteria with no DB record yet default to not complete.
        $fresh = [];
        foreach ($criteria as $criterion) {
            $type = (int)$criterion->criteriatype;
            if (!isset($fresh[$type])) {
                $fresh[$type] = [];
                foreach ($info->get_completions($userid, $type) as $compl) {
                    $fresh[$type][(int)$compl->criteriaid] = (bool)$compl->is_complete();
                }
            }
            if (!isset($fresh[$type][(int)$criterion->id])) {
                $fresh[$type][(int)$criterion->id] = false;
            }
        }

        if (empty($fresh)) {
            return false;
        }

        // Per-type aggregation: is each criteria group satisfied?
        $typesatisfied = [];
        foreach ($fresh as $type => $map) {
            $values = array_values($map);
            if ($info->get_aggregation_method($type) == COMPLETION_AGGREGATION_ALL) {
                $typesatisfied[$type] = !in_array(false, $values, true);
            } else {
                $typesatisfied[$type] = in_array(true, $values, true);
            }
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
        // Mark the course complete — this inserts/updates course_completions and
        // fires \core\event\course_completed, which triggers downstream processes.
        $cc = new \completion_completion(['userid' => $userid, 'course' => $courseid]);
        $cc->mark_complete();
        self::log($courseid, $userid, 'booked');
        return true;
    }

    /**
     * Optional debug logging, gated by the enablelogging setting.
     *
     * @param int    $courseid Course ID.
     * @param int    $userid   User ID.
     * @param string $outcome  Short outcome tag.
     * @return void
     */
    protected static function log(int $courseid, int $userid, string $outcome): void {
        if (get_config('local_instantcoursecompletion', 'enablelogging')) {
            mtrace("local_instantcoursecompletion: course={$courseid} user={$userid} outcome={$outcome}");
        }
    }
}
