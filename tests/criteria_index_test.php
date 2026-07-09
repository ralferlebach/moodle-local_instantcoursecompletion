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
 * Tests for the criteria type index.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Criteria index tests.
 *
 * @covers \local_instantcoursecompletion\criteria_index
 */
final class criteria_index_test extends \advanced_testcase {
    /**
     * Load completionlib and reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest(true);
    }

    /**
     * Insert a criterion of the given type into a course.
     *
     * @param int   $courseid Course ID.
     * @param int   $type     A COMPLETION_CRITERIA_TYPE_* constant.
     * @param array $extra    Additional record fields.
     * @return int The criterion ID.
     */
    protected function add_criterion(int $courseid, int $type, array $extra = []): int {
        global $DB;

        return (int)$DB->insert_record('course_completion_criteria', (object)array_merge([
            'course' => $courseid,
            'criteriatype' => $type,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ], $extra));
    }

    /**
     * A course without criteria reports an empty type set.
     *
     * @return void
     */
    public function test_types_for_course_without_criteria(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $this->assertSame([], criteria_index::types_for_course((int)$course->id));
        $this->assertFalse(criteria_index::has_non_activity_type((int)$course->id));
    }

    /**
     * Configured criterion types are reported, de-duplicated.
     *
     * @return void
     */
    public function test_types_for_course_reports_distinct_types(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, ['moduleinstance' => 1]);
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, ['moduleinstance' => 2]);
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_GRADE, ['gradepass' => 50.0]);
        criteria_index::purge();

        $types = criteria_index::types_for_course((int)$course->id);

        $this->assertCount(2, $types);
        $this->assertArrayHasKey(COMPLETION_CRITERIA_TYPE_ACTIVITY, $types);
        $this->assertArrayHasKey(COMPLETION_CRITERIA_TYPE_GRADE, $types);
        $this->assertTrue(criteria_index::has_type((int)$course->id, COMPLETION_CRITERIA_TYPE_GRADE));
        $this->assertFalse(criteria_index::has_type((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE));
    }

    /**
     * A course whose only criteria are activity criteria needs no extra evaluation.
     *
     * @return void
     */
    public function test_has_non_activity_type(): void {
        $activityonly = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_criterion((int)$activityonly->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, ['moduleinstance' => 1]);

        $mixed = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_criterion((int)$mixed->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, ['moduleinstance' => 2]);
        $this->add_criterion((int)$mixed->id, COMPLETION_CRITERIA_TYPE_DATE, ['timeend' => time() + DAYSECS]);

        criteria_index::purge();

        $this->assertFalse(criteria_index::has_non_activity_type((int)$activityonly->id));
        $this->assertTrue(criteria_index::has_non_activity_type((int)$mixed->id));
    }

    /**
     * Dependent courses are those carrying a prerequisite criterion for the course.
     *
     * @return void
     */
    public function test_dependent_course_ids(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $dependent = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $unrelated = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $this->add_criterion((int)$dependent->id, COMPLETION_CRITERIA_TYPE_COURSE, [
            'courseinstance' => (int)$prerequisite->id,
        ]);

        $dependents = criteria_index::dependent_course_ids((int)$prerequisite->id);

        $this->assertSame([(int)$dependent->id], $dependents);
        $this->assertSame([], criteria_index::dependent_course_ids((int)$unrelated->id));
    }

    /**
     * A dependent course with completion switched off is not returned.
     *
     * @return void
     */
    public function test_dependent_course_ids_ignores_disabled_courses(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $dependent = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);

        $this->add_criterion((int)$dependent->id, COMPLETION_CRITERIA_TYPE_COURSE, [
            'courseinstance' => (int)$prerequisite->id,
        ]);

        $this->assertSame([], criteria_index::dependent_course_ids((int)$prerequisite->id));
    }

    /**
     * Purging one course leaves the other cached entries alone.
     *
     * @return void
     */
    public function test_purge_single_course(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criterionid = $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE, [
            'timeend' => time() + DAYSECS,
        ]);
        criteria_index::purge();

        $this->assertTrue(criteria_index::has_type((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE));

        $DB->delete_records('course_completion_criteria', ['id' => $criterionid]);

        // Still cached.
        $this->assertTrue(criteria_index::has_type((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE));

        criteria_index::purge((int)$course->id);
        $this->assertFalse(criteria_index::has_type((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE));
    }
}
