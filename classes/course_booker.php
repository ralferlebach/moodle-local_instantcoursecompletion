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
 * Books course completions for the users of one course.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Booking context for a single course.
 *
 * The course record, its completion_info and its criteria are read once and reused for
 * every user. Rebuilding them per user is what turns a batch of a few hundred learners
 * into a few thousand queries.
 */
final class course_booker {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \completion_info Completion info for the course. */
    protected $info;

    /** @var array Criteria of the course, keyed by criterion ID. */
    protected $criteria;

    /**
     * Use for_course() instead.
     *
     * @param \stdClass        $course   The course.
     * @param \completion_info $info     Completion info for the course.
     * @param array            $criteria Criteria of the course.
     */
    protected function __construct(\stdClass $course, \completion_info $info, array $criteria) {
        $this->course = $course;
        $this->info = $info;
        $this->criteria = $criteria;
    }

    /**
     * Open a booking context for a course, or fail when the course cannot be booked at all.
     *
     * @param int $courseid Course ID.
     * @return self|null Null when the course is gone, is the site course, or tracks nothing.
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
     * The course this context books for.
     *
     * @return \stdClass
     */
    public function get_course(): \stdClass {
        return $this->course;
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
    public function book(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }

        $courseid = (int)$this->course->id;

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

        $this->mark_satisfied_criteria($userid);

        // Reload after the criterion writes: mark_inprogress() creates the row and
        // sets the reaggregate flag that aggregate_completions() selects on.
        $ccompletion = new \completion_completion(['course' => $courseid, 'userid' => $userid]);
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
     * Write a criterion completion record for every criterion the user newly satisfies.
     *
     * Self, role and unenrol criteria are skipped: their records are written by the user
     * action, the teacher action and the unenrolment observer respectively, and their
     * review() implementations cannot decide satisfaction from stored data.
     *
     * @param int $userid User ID.
     * @return void
     */
    protected function mark_satisfied_criteria(int $userid): void {
        $skiptypes = self::externally_marked_types();

        foreach ($this->criteria as $criterion) {
            $type = (int)$criterion->criteriatype;
            if (in_array($type, $skiptypes, true)) {
                continue;
            }

            $criterioncompletion = $this->info->get_user_completion($userid, $criterion);
            if ($criterioncompletion->is_complete()) {
                continue;
            }

            if ($type === COMPLETION_CRITERIA_TYPE_DATE) {
                // The criterion is satisfied as of its end date, not as of now.
                if ($criterion->review($criterioncompletion, false)) {
                    $criterioncompletion->mark_complete((int)$criterion->timeend);
                }
                continue;
            }

            if ($type === COMPLETION_CRITERIA_TYPE_DURATION) {
                $duetime = self::duration_due_time($criterion, $userid);
                if ($duetime !== null && $duetime <= time()) {
                    $criterioncompletion->mark_complete($duetime);
                }
                continue;
            }

            // Let core decide and record the criterion; it also populates
            // type-specific fields such as gradefinal.
            $criterion->review($criterioncompletion, true);
        }
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
     * Optional logging, gated by the enablelogging setting.
     *
     * @param int    $userid  User ID.
     * @param string $outcome Outcome tag: booked, criteria-not-met or already-complete.
     * @return void
     */
    protected function log(int $userid, string $outcome): void {
        if (!get_config('local_instantcoursecompletion', 'enablelogging')) {
            return;
        }

        $courseid = (int)$this->course->id;

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
