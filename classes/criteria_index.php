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
 * Cached lookup of which completion criterion types a course uses.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Criteria type index.
 */
class criteria_index {
    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /**
     * The criterion types configured for a course.
     *
     * @param int $courseid Course ID.
     * @return array<int, bool> Map of criterion type constant to true.
     */
    public static function types_for_course(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }

        $cache = \cache::make(self::COMPONENT, 'coursecriteriatypes');
        $cached = $cache->get($courseid);
        if ($cached !== false) {
            return $cached;
        }

        $types = $DB->get_fieldset_sql(
            'SELECT DISTINCT criteriatype FROM {course_completion_criteria} WHERE course = :course',
            ['course' => $courseid]
        );
        $set = array_fill_keys(array_map('intval', $types), true);

        $cache->set($courseid, $set);
        return $set;
    }

    /**
     * Does the course use the given criterion type?
     *
     * @param int $courseid Course ID.
     * @param int $type     A COMPLETION_CRITERIA_TYPE_* constant.
     * @return bool
     */
    public static function has_type(int $courseid, int $type): bool {
        return isset(self::types_for_course($courseid)[$type]);
    }

    /**
     * Does the course use any criterion type other than activity completion?
     *
     * Core already marks and aggregates activity criteria on single-user updates, so a
     * course whose only criteria are activity criteria gains nothing from this plugin.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function has_non_activity_type(int $courseid): bool {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $types = self::types_for_course($courseid);
        unset($types[COMPLETION_CRITERIA_TYPE_ACTIVITY]);
        return !empty($types);
    }

    /**
     * Courses that require the given course as a prerequisite.
     *
     * Core evaluates prerequisite criteria only from cron, so completing the
     * prerequisite has to announce itself to the dependent courses.
     *
     * @param int $courseid The prerequisite course.
     * @return int[] IDs of the dependent, completion-enabled courses.
     */
    public static function dependent_course_ids(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        if ($courseid <= 0) {
            return [];
        }

        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cc.course
               FROM {course_completion_criteria} cc
               JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
              WHERE cc.criteriatype = :criteriatype AND cc.courseinstance = :courseid",
            ['criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE, 'courseid' => $courseid]
        );

        return array_map('intval', $courseids);
    }

    /**
     * Drop the cached types of one course, or of every course.
     *
     * @param int $courseid Course ID, or 0 to purge the whole index.
     * @return void
     */
    public static function purge(int $courseid = 0): void {
        $cache = \cache::make(self::COMPONENT, 'coursecriteriatypes');
        if ($courseid > 0) {
            $cache->delete($courseid);
            return;
        }
        $cache->purge();
    }
}
