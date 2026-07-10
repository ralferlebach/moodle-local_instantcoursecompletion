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
 * Course-scoped booker for the completion criteria of one course.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Books course completions for the users of one course.
 *
 * The course record, its completion_info and its criteria are read once in for_course()
 * and reused for every user booked through the returned instance. Rebuilding them per
 * user is what turns a batch of a few hundred learners into a few thousand queries, so
 * every multi-user caller (the batch task, the reconcile task) opens one instance per
 * course and books each user against it.
 *
 * The static book() facade remains for single-pair callers such as the observer's
 * immediate booking task, where opening an instance for one user has no cost to amortise.
 */
final class completion_booker {
    /** @var string Lock type serialising concurrent bookings of the same pair. */
    private const LOCK_TYPE = 'local_instantcoursecompletion_booking';

    /** @var int Seconds to wait for the booking lock before giving up. */
    private const LOCK_TIMEOUT = 3;

    /** @var \stdClass The course. */
    private $course;

    /** @var \completion_info Completion info for the course. */
    private $info;

    /** @var array Criteria of the course, keyed by criterion ID. */
    private $criteria;

    /** @var int Course ID. */
    private $courseid;

    /**
     * Use for_course() instead.
     *
     * @param \stdClass        $course   The course.
     * @param \completion_info $info     Completion info for the course.
     * @param array            $criteria Criteria of the course, keyed by criterion ID.
     */
    private function __construct(\stdClass $course, \completion_info $info, array $criteria) {
        $this->course = $course;
        $this->info = $info;
        $this->criteria = $criteria;
        $this->courseid = (int)$course->id;
    }

    /**
     * Open a booker for a course, or fail when the course cannot be booked at all.
     *
     * @param int $courseid Course ID.
     * @return self|null Null when the course is gone, is the site course, or has completion disabled.
     */
    public static function for_course(int $courseid): ?self {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ($courseid <= 0 || $courseid == SITEID) {
            return null;
        }

        try {
            $course = get_course($courseid);
        } catch (\dml_exception $e) {
            return null;
        }

        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return null;
        }

        return new self($course, $info, $info->get_criteria());
    }

    /**
     * Evaluate a whole course for a single (course, user) pair and aggregate the result.
     *
     * A thin facade over for_course()->book_user() for callers that only ever book one
     * user of a course, so they need not manage an instance themselves.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return bool True if the course is complete for the user after this call.
     */
    public static function book(int $courseid, int $userid): bool {
        return self::for_course($courseid)?->book_user($userid) ?? false;
    }

    /**
     * The course this booker works on.
     *
     * @return \stdClass
     */
    public function get_course(): \stdClass {
        return $this->course;
    }

    /**
     * One criterion of the course by ID, or null when it is not part of this course.
     *
     * @param int $criteriaid Criterion ID.
     * @return \completion_criteria|null
     */
    public function get_criterion(int $criteriaid): ?\completion_criteria {
        return $this->criteria[$criteriaid] ?? null;
    }

    /**
     * Evaluate the course's completion criteria for one user and aggregate the result.
     *
     * Runs the same three-stage pipeline core uses:
     *   1. Each criterion is reviewed; newly satisfied ones get a
     *      course_completion_crit_compl record.
     *   2. Writing such a record flags the course_completions row for reaggregation.
     *   3. aggregate_completions() applies the per-type and overall aggregation methods
     *      and marks the course complete with the latest criterion timestamp.
     *
     * @param int $userid User ID.
     * @return bool True if the course is complete for the user after this call.
     */
    public function book_user(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }

        if ($this->info->is_course_complete($userid)) {
            $this->log($userid, 'already-complete');
            return true;
        }

        // Teachers and other roles without moodle/course:isincompletionreports never
        // appear in a completion report and must not receive a completion record.
        if (!$this->info->is_tracked_user($userid)) {
            return false;
        }

        if (empty($this->criteria)) {
            return false;
        }

        $lock = $this->acquire_lock($userid);
        if (!$lock) {
            return false;
        }

        try {
            // A parallel booking may have completed the course while this call waited
            // for the lock; re-checking here is what makes the lock useful.
            if ($this->info->is_course_complete($userid)) {
                $this->log($userid, 'already-complete');
                return true;
            }

            foreach ($this->criteria as $criterion) {
                $this->mark_criterion($criterion, $userid);
            }

            return $this->aggregate($userid);
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
     * same as book_user()'s.
     *
     * @param \completion_criteria $criterion The criterion that fell due.
     * @param int                  $userid    User ID.
     * @return bool True if the course is complete for the user after this call.
     */
    public function book_criterion(\completion_criteria $criterion, int $userid): bool {
        if ($userid <= 0) {
            return false;
        }

        if ($this->info->is_course_complete($userid)) {
            $this->log($userid, 'already-complete');
            return true;
        }

        if (!$this->info->is_tracked_user($userid)) {
            return false;
        }

        $lock = $this->acquire_lock($userid);
        if (!$lock) {
            return false;
        }

        try {
            if ($this->info->is_course_complete($userid)) {
                $this->log($userid, 'already-complete');
                return true;
            }

            $this->mark_criterion($criterion, $userid);

            return $this->aggregate($userid);
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
     * @param int $userid User ID.
     * @return \core\lock\lock|null The lock, or null when it could not be taken in time.
     */
    private function acquire_lock(int $userid): ?\core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($this->courseid . '_' . $userid, self::LOCK_TIMEOUT);

        return $lock ?: null;
    }

    /**
     * Apply the aggregation methods and report whether the course is now complete.
     *
     * @param int $userid User ID.
     * @return bool
     */
    private function aggregate(int $userid): bool {
        // Reload after the criterion writes: mark_inprogress() creates the row and
        // sets the reaggregate flag that aggregate_completions() selects on.
        $ccompletion = new \completion_completion(['course' => $this->courseid, 'userid' => $userid]);
        if (empty($ccompletion->id) || empty($ccompletion->reaggregate)) {
            $this->log($userid, 'criteria-not-met');
            return false;
        }

        aggregate_completions((int)$ccompletion->id);

        $booked = $this->info->is_course_complete($userid);
        $this->log($userid, $booked ? 'booked' : 'criteria-not-met');
        return $booked;
    }

    /**
     * Write a criterion completion record if the user newly satisfies this criterion.
     *
     * Self, role and unenrol criteria are skipped: their records are written by the
     * user action, the teacher action and the unenrolment observer respectively, and
     * their review() implementations cannot decide satisfaction from stored data.
     *
     * @param \completion_criteria $criterion The criterion.
     * @param int                  $userid    User ID.
     * @return void
     */
    private function mark_criterion(\completion_criteria $criterion, int $userid): void {
        $type = (int)$criterion->criteriatype;
        if (in_array($type, self::externally_marked_types(), true)) {
            return;
        }

        $criterioncompletion = $this->info->get_user_completion($userid, $criterion);
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
     * Criterion types whose completion records this plugin never writes itself.
     *
     * @return int[]
     */
    private static function externally_marked_types(): array {
        return [
            COMPLETION_CRITERIA_TYPE_SELF,
            COMPLETION_CRITERIA_TYPE_ROLE,
            COMPLETION_CRITERIA_TYPE_UNENROL,
        ];
    }

    /**
     * The moment a duration criterion becomes satisfied for a user.
     *
     * @param \completion_criteria $criterion The duration criterion.
     * @param int                  $userid    User ID.
     * @return int|null Timestamp, or null when the user has no usable enrolment.
     */
    private static function duration_due_time(\completion_criteria $criterion, int $userid): ?int {
        $enrolperiod = (int)$criterion->enrolperiod;
        if ($enrolperiod <= 0) {
            return null;
        }

        $timeenrolled = due_candidate_repository::get_enrolment_time((int)$criterion->course, $userid);
        return $timeenrolled === null ? null : $timeenrolled + $enrolperiod;
    }

    /**
     * Optional logging, gated by the enablelogging setting.
     *
     * @param int    $userid  User ID.
     * @param string $outcome Outcome tag: booked, criteria-not-met or already-complete.
     * @return void
     */
    private function log(int $userid, string $outcome): void {
        if (!get_config('local_instantcoursecompletion', 'enablelogging')) {
            return;
        }

        if (CLI_SCRIPT) {
            mtrace("local_instantcoursecompletion: course={$this->courseid} user={$userid} outcome={$outcome}");
        }

        if ($outcome === 'booked') {
            event\completion_booked::create([
                'objectid' => $this->courseid,
                'context' => \context_course::instance($this->courseid),
                'relateduserid' => $userid,
            ])->trigger();
        }
    }
}
