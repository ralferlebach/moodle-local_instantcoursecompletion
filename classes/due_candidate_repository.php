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
 * Finds the tracked users a time-based criterion has fallen due for but not yet booked.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Repository of due, not-yet-booked completion candidates.
 *
 * The definition of a "due, not yet recorded" user lives here once: a tracked enrolment
 * whose course is not complete and whose criterion has no course_completion_crit_compl
 * record yet. Discovery, the batch task and the enrolment observers all read it through
 * these four methods rather than each carrying their own copy of the join.
 */
final class due_candidate_repository {
    /** @var string The course-completion-not-recorded predicate. */
    private const NOT_COMPLETED = '(cco.timecompleted IS NULL OR cco.timecompleted = 0)';

    /**
     * The moment an enrolment starts counting: the earliest one wins, and a start-less
     * enrolment counts from its creation. Both rules match completion_criteria_duration::cron().
     *
     * @var string
     */
    private const ENROLMENT_START = 'MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END)';

    /**
     * Does any tracked user still owe this date criterion?
     *
     * A date criterion is course-wide, so one existence check replaces a scan over every
     * enrolled user, and one batch task replaces one task per user.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion ID.
     * @return bool
     */
    public static function has_pending_date_users(int $courseid, int $criteriaid): bool {
        global $DB;

        [$enrolledsql, $params] = self::tracked_enrolled($courseid);
        $params['pendingcourseid'] = $courseid;
        $params['criteriaid'] = $criteriaid;

        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
                  " . self::pending_joins() . "
                 WHERE " . self::NOT_COMPLETED . " AND ccc.id IS NULL";

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Tracked users of a course whose duration criterion falls due by $latest, after $fromuserid.
     *
     * The horizon is applied to the enrolment time rather than to the computed due time,
     * so no arithmetic is performed on the aggregate. Each row carries the enrolment time
     * so the caller can place it in a batch window.
     *
     * @param int $courseid   Course ID.
     * @param int $criteriaid Criterion ID.
     * @param int $fromuserid Only users with a higher ID are returned.
     * @param int $latest     Latest enrolment time that still falls due inside the horizon.
     * @param int $limit      Maximum number of rows to return.
     * @return \moodle_recordset Rows of {userid, timeenrolled}, ordered by user ID ascending.
     */
    public static function get_duration_candidates(
        int $courseid,
        int $criteriaid,
        int $fromuserid,
        int $latest,
        int $limit
    ): \moodle_recordset {
        global $DB;

        [$enrolledsql, $params] = self::tracked_enrolled($courseid);
        $params['enrolcourseid'] = $courseid;
        $params['pendingcourseid'] = $courseid;
        $params['criteriaid'] = $criteriaid;
        $params['fromuserid'] = $fromuserid;
        $params['latest'] = $latest;

        $started = self::ENROLMENT_START;
        $sql = "SELECT enrolled.id AS userid, $started AS timeenrolled
                  FROM ($enrolledsql) enrolled
                  JOIN {user_enrolments} ue ON ue.userid = enrolled.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :enrolcourseid
                  " . self::pending_joins() . "
                 WHERE " . self::NOT_COMPLETED . " AND ccc.id IS NULL AND enrolled.id > :fromuserid
              GROUP BY enrolled.id
                HAVING $started > 0 AND $started <= :latest
              ORDER BY enrolled.id ASC";

        return $DB->get_recordset_sql($sql, $params, 0, $limit);
    }

    /**
     * Tracked users of the course for whom this criterion has fallen due by now.
     *
     * A date criterion is due for everyone once its end has passed; a duration criterion is
     * due for a user once the enrolment period has elapsed since their enrolment started.
     *
     * @param int                  $courseid   Course ID.
     * @param \completion_criteria $criterion  The criterion.
     * @param int                  $fromuserid Only users with a higher ID are returned.
     * @param int                  $limit      Maximum number of users to return.
     * @return int[] Ordered ascending.
     */
    public static function get_due_user_ids(
        int $courseid,
        \completion_criteria $criterion,
        int $fromuserid,
        int $limit
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        [$enrolledsql, $params] = self::tracked_enrolled($courseid);
        $params['pendingcourseid'] = $courseid;
        $params['criteriaid'] = (int)$criterion->id;
        $params['fromuserid'] = $fromuserid;

        if ((int)$criterion->criteriatype === COMPLETION_CRITERIA_TYPE_DATE) {
            if ((int)$criterion->timeend > time()) {
                return [];
            }

            $sql = "SELECT enrolled.id AS userid
                      FROM ($enrolledsql) enrolled
                      " . self::pending_joins() . "
                     WHERE " . self::NOT_COMPLETED . " AND ccc.id IS NULL AND enrolled.id > :fromuserid
                  ORDER BY enrolled.id ASC";

            return self::limited_ids($sql, $params, $limit);
        }

        $enrolperiod = (int)$criterion->enrolperiod;
        if ($enrolperiod <= 0) {
            return [];
        }
        $params['enrolcourseid'] = $courseid;
        $params['latest'] = time() - $enrolperiod;

        $started = self::ENROLMENT_START;
        $sql = "SELECT enrolled.id AS userid
                  FROM ($enrolledsql) enrolled
                  JOIN {user_enrolments} ue ON ue.userid = enrolled.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :enrolcourseid
                  " . self::pending_joins() . "
                 WHERE " . self::NOT_COMPLETED . " AND ccc.id IS NULL AND enrolled.id > :fromuserid
              GROUP BY enrolled.id
                HAVING $started > 0 AND $started <= :latest
              ORDER BY enrolled.id ASC";

        return self::limited_ids($sql, $params, $limit);
    }

    /**
     * The moment a user's enrolment in a course starts counting.
     *
     * The review() counterpart in core reads ue.timestart alone and therefore never
     * completes a user without a start date; this follows the cron() rule instead.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return int|null Timestamp, or null when the user has no usable enrolment.
     */
    public static function get_enrolment_time(int $courseid, int $userid): ?int {
        global $DB;

        $timeenrolled = $DB->get_field_sql(
            "SELECT " . self::ENROLMENT_START . "
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );

        return empty($timeenrolled) ? null : (int)$timeenrolled;
    }

    /**
     * The tracked-enrolled subquery for a course, and its parameters.
     *
     * @param int $courseid Course ID.
     * @return array{0: string, 1: array} SQL and parameters.
     */
    private static function tracked_enrolled(int $courseid): array {
        $context = \context_course::instance($courseid);
        return get_enrolled_sql($context, due_scheduler::TRACKED_CAPABILITY, 0, true);
    }

    /**
     * The joins and their aliases that decide a user has not been booked yet.
     *
     * Refers to :pendingcourseid and :criteriaid, which the caller must bind.
     *
     * @return string
     */
    private static function pending_joins(): string {
        return "LEFT JOIN {course_completions} cco
                       ON cco.userid = enrolled.id AND cco.course = :pendingcourseid
                LEFT JOIN {course_completion_crit_compl} ccc
                       ON ccc.userid = enrolled.id AND ccc.criteriaid = :criteriaid";
    }

    /**
     * Run a user-ID query with a hard row limit and return the IDs.
     *
     * get_records_sql() keys its result by the first selected column, so the user IDs come
     * back as the keys; get_fieldset_sql() would accept no limit at all.
     *
     * @param string $sql    The query, selecting the user ID first.
     * @param array  $params Query parameters.
     * @param int    $limit  Maximum number of rows.
     * @return int[] Ordered ascending.
     */
    private static function limited_ids(string $sql, array $params, int $limit): array {
        global $DB;

        return array_map('intval', array_keys($DB->get_records_sql($sql, $params, 0, $limit)));
    }
}
