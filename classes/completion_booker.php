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
     * Guard order (all using stable public completion API):
     *   1. Course completion must be enabled.
     *   2. Skip if the user is already marked complete (idempotent).
     *   3. Review each completion criterion for the user (marks criterion-level
     *      completion instantly, mirroring core's cron review).
     *
     * NOTE (Phase 1 / stub): the criterion review above is implemented and safe.
     * The final course-level aggregation-and-mark step is intentionally delegated
     * to core here (see the block comment below), because the exact aggregation
     * call sequence differs between Moodle 4.5 and 5.x and must be pinned per
     * version and covered by integration tests before it is enabled. Until then
     * this method accelerates criterion completion and leaves the course-level
     * mark to core, rather than shipping a subtly incorrect aggregation.
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

        // Guard 1: completion enabled for this course.
        if (!$info->is_enabled()) {
            return false;
        }

        // Guard 2: already complete → nothing to do (idempotent).
        if ($info->is_course_complete($userid)) {
            self::log($courseid, $userid, 'already-complete');
            return true;
        }

        // Guard: the course must actually have completion criteria to evaluate.
        $criteria = $info->get_criteria();
        if (empty($criteria)) {
            return false;
        }

        // Step 3: review each criterion for this user.
        foreach ($criteria as $criterion) {
            $completions = $info->get_completions($userid, $criterion->criteriatype);
            foreach ($completions as $completion) {
                if ($completion->criteriaid == $criterion->id && !$completion->is_complete()) {
                    // Re-check the criterion and mark it complete if the user now meets it.
                    $criterion->review($completion);
                }
            }
        }

        // Phase 2 (deferred): course-level aggregation and mark.
        //
        // After criterion review, core aggregates criteria into the overall course
        // completion inside the scheduled task. To make that instant, the scoped
        // equivalent belongs here: construct a completion_completion for this course
        // and user and call mark_complete() once the configured aggregation
        // (ALL vs ANY, per criteria type) is satisfied.
        //
        // That aggregation must mirror core exactly and is version-sensitive between
        // Moodle 4.5 and 5.x, so it is left for Phase 2 with dedicated integration
        // tests rather than shipping a subtly incorrect aggregation here. See docs.

        self::log($courseid, $userid, 'reviewed');
        return false;
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
