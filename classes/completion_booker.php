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
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Course-completion booker.
 */
class completion_booker {
    /** @var string Lock type serialising concurrent bookings of the same pair. */
    protected const LOCK_TYPE = 'local_instantcoursecompletion_booking';

    /** @var int Seconds to wait for the booking lock before giving up. */
    protected const LOCK_TIMEOUT = 3;

    /**
     * Evaluate the course's completion criteria for one user and aggregate the result.
     *
     * Runs the same three-stage pipeline core uses:
     *   1. Each criterion is reviewed; newly satisfied ones get a
     *      course_completion_crit_compl record.
     *   2. Writing such a record flags the course_completions row for reaggregation.
     *   3. aggregate_completions() applies the per-type and overall aggregation
     *      methods and marks the course complete with the latest criterion timestamp.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return bool True if the course is complete for the user after this call.
     */
    public static function book(int $courseid, int $userid): bool {
        $course = self::load_course($courseid);
        if (!$course) {
            return false;
        }

        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return false;
        }

        if ($info->is_course_complete($userid)) {
            self::log($courseid, $userid, 'already-complete');
            return true;
        }

        // Teachers and other roles without moodle/course:isincompletionreports never
        // appear in a completion report and must not receive a completion record.
        if (!$info->is_tracked_user($userid)) {
            return false;
        }

        $criteria = $info->get_criteria();
        if (empty($criteria)) {
            return false;
        }

        $lock = self::acquire_lock($courseid, $userid);
        if (!$lock) {
            return false;
        }

        try {
            // A parallel booking may have completed the course while this call waited
            // for the lock; re-checking here is what makes the lock useful.
            if ($info->is_course_complete($userid)) {
                self::log($courseid, $userid, 'already-complete');
                return true;
            }

            foreach ($criteria as $criterion) {
                self::mark_criterion($info, $criterion, $userid);
            }

            return self::aggregate($info, $courseid, $userid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Evaluate a single criterion for one user and aggregate the result.
     *
     * A due-time task knows exactly which criterion it is waiting for, so re-reviewing
     * the whole criteria set once per user would be wasted work. aggregate_completions()
     * still sees every criterion completion record that exists, so the outcome is the
     * same as book()'s.
     *
     * The completion_info is passed in rather than built here: a batch shares one across
     * all of its users, which is the point of the batch.
     *
     * @param \completion_info     $info      Completion info of the course.
     * @param int                  $courseid  Course ID.
     * @param \completion_criteria $criterion The criterion that fell due.
     * @param int                  $userid    User ID.
     * @return bool True if the course is complete for the user after this call.
     */
    public static function book_criterion(
        \completion_info $info,
        int $courseid,
        \completion_criteria $criterion,
        int $userid
    ): bool {
        if ($info->is_course_complete($userid)) {
            self::log($courseid, $userid, 'already-complete');
            return true;
        }

        if (!$info->is_tracked_user($userid)) {
            return false;
        }

        $lock = self::acquire_lock($courseid, $userid);
        if (!$lock) {
            return false;
        }

        try {
            if ($info->is_course_complete($userid)) {
                self::log($courseid, $userid, 'already-complete');
                return true;
            }

            self::mark_criterion($info, $criterion, $userid);

            return self::aggregate($info, $courseid, $userid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Take the lock that keeps two processes from booking the same pair at once.
     *
     * completion_booked is a plugin event, not a core data write, so nothing in Moodle
     * itself makes two concurrent bookings idempotent from an observer's point of view.
     * The lock is per (course, user), not per course: a page-wide lock would serialise
     * an entire batch behind whichever user happens to also be mid-booking elsewhere.
     *
     * A short timeout is deliberate. If the pair is genuinely being booked elsewhere,
     * that call will finish the work; there is nothing for this one to contribute by
     * waiting, and the criteria will be re-evaluated the next time anything asks again.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return \core\lock\lock|null The lock, or null when it could not be taken in time.
     */
    protected static function acquire_lock(int $courseid, int $userid): ?\core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($courseid . '_' . $userid, self::LOCK_TIMEOUT);

        return $lock ?: null;
    }

    /**
     * Load a course, tolerating one that has been deleted meanwhile.
     *
     * @param int $courseid Course ID.
     * @return \stdClass|null
     */
    public static function load_course(int $courseid): ?\stdClass {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ($courseid <= 0 || $courseid == SITEID) {
            return null;
        }

        try {
            return get_course($courseid);
        } catch (\dml_exception $e) {
            return null;
        }
    }

    /**
     * Apply the aggregation methods and report whether the course is now complete.
     *
     * @param \completion_info $info     Completion info of the course.
     * @param int              $courseid Course ID.
     * @param int              $userid   User ID.
     * @return bool
     */
    protected static function aggregate(\completion_info $info, int $courseid, int $userid): bool {
        // Reload after the criterion writes: mark_inprogress() creates the row and
        // sets the reaggregate flag that aggregate_completions() selects on.
        $ccompletion = new \completion_completion(['course' => $courseid, 'userid' => $userid]);
        if (empty($ccompletion->id) || empty($ccompletion->reaggregate)) {
            self::log($courseid, $userid, 'criteria-not-met');
            return false;
        }

        aggregate_completions((int)$ccompletion->id);

        $booked = $info->is_course_complete($userid);
        self::log($courseid, $userid, $booked ? 'booked' : 'criteria-not-met');
        return $booked;
    }

    /**
     * Write a criterion completion record if the user newly satisfies this criterion.
     *
     * Self, role and unenrol criteria are skipped: their records are written by the
     * user action, the teacher action and the unenrolment observer respectively, and
     * their review() implementations cannot decide satisfaction from stored data.
     *
     * @param \completion_info     $info      Completion info of the course.
     * @param \completion_criteria $criterion The criterion.
     * @param int                  $userid    User ID.
     * @return void
     */
    protected static function mark_criterion(\completion_info $info, \completion_criteria $criterion, int $userid): void {
        $type = (int)$criterion->criteriatype;
        if (in_array($type, self::externally_marked_types(), true)) {
            return;
        }

        $criterioncompletion = $info->get_user_completion($userid, $criterion);
        if ($criterioncompletion->is_complete()) {
            return;
        }

        if ($type === COMPLETION_CRITERIA_TYPE_DATE) {
            // The criterion is satisfied as of its end date, not as of now.
            if ($criterion->review($criterioncompletion, false)) {
                $criterioncompletion->mark_complete((int)$criterion->timeend);
            }
            return;
        }

        if ($type === COMPLETION_CRITERIA_TYPE_DURATION) {
            $duetime = self::duration_due_time($criterion, $userid);
            if ($duetime !== null && $duetime <= time()) {
                $criterioncompletion->mark_complete($duetime);
            }
            return;
        }

        // Let core decide and record the criterion; it also populates type-specific
        // fields such as gradefinal.
        $criterion->review($criterioncompletion, true);
    }

    /**
     * The moment a duration criterion becomes satisfied for a user.
     *
     * @param \completion_criteria $criterion The duration criterion.
     * @param int                  $userid    User ID.
     * @return int|null Timestamp, or null when the user has no usable enrolment.
     */
    protected static function duration_due_time(\completion_criteria $criterion, int $userid): ?int {
        $enrolperiod = (int)$criterion->enrolperiod;
        if ($enrolperiod <= 0) {
            return null;
        }

        $timeenrolled = due_scheduler::time_enrolled((int)$criterion->course, $userid);
        return $timeenrolled === null ? null : $timeenrolled + $enrolperiod;
    }

    /**
     * Criterion types whose completion records this plugin never writes itself.
     *
     * @return int[]
     */
    protected static function externally_marked_types(): array {
        return [
            COMPLETION_CRITERIA_TYPE_SELF,
            COMPLETION_CRITERIA_TYPE_ROLE,
            COMPLETION_CRITERIA_TYPE_UNENROL,
        ];
    }

    /**
     * Optional logging, gated by the enablelogging setting.
     *
     * @param int    $courseid Course ID.
     * @param int    $userid   User ID.
     * @param string $outcome  Outcome tag: booked, criteria-not-met or already-complete.
     * @return void
     */
    protected static function log(int $courseid, int $userid, string $outcome): void {
        if (!get_config('local_instantcoursecompletion', 'enablelogging')) {
            return;
        }

        if (CLI_SCRIPT) {
            mtrace("local_instantcoursecompletion: course={$courseid} user={$userid} outcome={$outcome}");
        }

        if ($outcome === 'booked') {
            event\completion_booked::create([
                'objectid' => $courseid,
                'context' => \context_course::instance($courseid),
                'relateduserid' => $userid,
            ])->trigger();
        }
    }
}
