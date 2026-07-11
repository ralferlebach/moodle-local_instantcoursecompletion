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
 * Selects the courses the scheduled tasks walk through.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Course selection for the scheduled tasks.
 */
final class completion_course_repository {
    /**
     * Course IDs carrying any completion criterion, from a cursor position onward.
     *
     * The reconcile task's safety net walks every completion-enabled course this way.
     *
     * @param int $fromcourseid Lowest course ID to return; the cursor may point inside it.
     * @param int $limit        Maximum number of course IDs to return.
     * @return int[] Ordered ascending.
     */
    public static function get_course_ids_after(int $fromcourseid, int $limit): array {
        return self::course_ids($fromcourseid, $limit, '', []);
    }

    /**
     * Course IDs carrying a time-based criterion that falls due within the horizon.
     *
     * A date criterion counts when its end is in the future but no later than the horizon;
     * a duration criterion counts whenever it has an enrolment period. The discovery task
     * plans time-based bookings for exactly these courses.
     *
     * @param int $fromcourseid Lowest course ID to return; the cursor may point inside it.
     * @param int $horizon      Latest due time being planned for.
     * @param int $limit        Maximum number of course IDs to return.
     * @return int[] Ordered ascending.
     */
    public static function get_time_criteria_course_ids_after(int $fromcourseid, int $horizon, int $limit): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $filter = "AND ((cc.criteriatype = :datetype AND cc.timeend > 0 AND cc.timeend <= :horizon)
                     OR (cc.criteriatype = :durationtype AND cc.enrolperiod > 0))";
        $params = [
            'datetype' => COMPLETION_CRITERIA_TYPE_DATE,
            'horizon' => $horizon,
            'durationtype' => COMPLETION_CRITERIA_TYPE_DURATION,
        ];

        return self::course_ids($fromcourseid, $limit, $filter, $params);
    }

    /**
     * The shared course-selection query.
     *
     * @param int    $fromcourseid Lowest course ID to return.
     * @param int    $limit        Maximum number of course IDs to return.
     * @param string $filter       Extra criterion filter, or an empty string.
     * @param array  $extraparams  Parameters the filter refers to.
     * @return int[] Ordered ascending.
     */
    private static function course_ids(int $fromcourseid, int $limit, string $filter, array $extraparams): array {
        global $DB;

        $params = ['siteid' => SITEID, 'fromcourseid' => $fromcourseid] + $extraparams;

        // A fieldset query takes no limit; get_records_sql() keys by the first column.
        $records = $DB->get_records_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.course <> :siteid AND cc.course >= :fromcourseid
                $filter
           ORDER BY cc.course ASC",
            $params,
            0,
            $limit
        );

        return array_map('intval', array_keys($records));
    }
}
