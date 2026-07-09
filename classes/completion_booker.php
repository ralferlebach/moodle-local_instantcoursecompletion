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
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ($courseid <= 0 || $userid <= 0 || $courseid == SITEID) {
            return false;
        }

        try {
            $course = get_course($courseid);
        } catch (\dml_exception $e) {
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

        $criteria = $info->get_criteria();
        if (empty($criteria)) {
            return false;
        }

        self::mark_satisfied_criteria($info, $criteria, $userid);

        // Reload after the criterion writes: mark_inprogress() creates the row and
        // sets the reaggregate flag that aggregate_completions() selects on.
        $ccompletion = new \completion_completion(['course' => $courseid, 'userid' => $userid]);
        if (empty($ccompletion->id) || empty($ccompletion->reaggregate)) {
            self::log($courseid, $userid, 'criteria-not-met');
            return false;
        }

        aggregate_completions((int)$ccompletion->id);

        $booked = (new \completion_info($course))->is_course_complete($userid);
        self::log($courseid, $userid, $booked ? 'booked' : 'criteria-not-met');
        return $booked;
    }

    /**
     * Write a criterion completion record for every criterion the user newly satisfies.
     *
     * Self, role and unenrol criteria are skipped: their records are written by the
     * user action, the teacher action and the unenrolment observer respectively, and
     * their review() implementations cannot decide satisfaction from stored data.
     *
     * @param \completion_info $info     Completion info for the course.
     * @param array            $criteria Criteria as returned by completion_info::get_criteria().
     * @param int              $userid   User ID.
     * @return void
     */
    protected static function mark_satisfied_criteria(\completion_info $info, array $criteria, int $userid): void {
        $skiptypes = self::externally_marked_types();

        foreach ($criteria as $criterion) {
            $type = (int)$criterion->criteriatype;
            if (in_array($type, $skiptypes, true)) {
                continue;
            }

            $criterioncompletion = $info->get_user_completion($userid, $criterion);
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
     * The moment a duration criterion becomes satisfied for a user.
     *
     * completion_criteria_duration::review() reads ue.timestart only and therefore
     * never completes users whose enrolment carries no start date. The criterion's own
     * cron falls back to ue.timecreated in that case; this reproduces the cron rule so
     * that both code paths agree. The earliest enrolment wins, as it does in cron.
     *
     * @param \completion_criteria $criterion The duration criterion.
     * @param int                  $userid    User ID.
     * @return int|null Timestamp, or null when the user has no usable enrolment.
     */
    protected static function duration_due_time(\completion_criteria $criterion, int $userid): ?int {
        global $DB;

        $enrolperiod = (int)$criterion->enrolperiod;
        if ($enrolperiod <= 0) {
            return null;
        }

        $timeenrolled = $DB->get_field_sql(
            "SELECT MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => (int)$criterion->course, 'userid' => $userid]
        );

        if (empty($timeenrolled)) {
            return null;
        }
        return (int)$timeenrolled + $enrolperiod;
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
