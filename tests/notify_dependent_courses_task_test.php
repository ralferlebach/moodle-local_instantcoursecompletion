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
 * Tests for the dependent-course notification task.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_completion_task;
use local_instantcoursecompletion\task\notify_dependent_courses_task;

/**
 * Dependent-course notification task tests.
 *
 * @covers \local_instantcoursecompletion\task\notify_dependent_courses_task
 * @covers \local_instantcoursecompletion\criteria_index::dependent_course_ids
 */
final class notify_dependent_courses_task_test extends \advanced_testcase {
    /**
     * Load completionlib and reset state.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest(true);
        observer::reset_seen();
        criteria_index::purge();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
    }

    /**
     * Ad-hoc booking tasks currently queued.
     *
     * @return \core\task\adhoc_task[]
     */
    protected function queued_bookings(): array {
        return \core\task\manager::get_adhoc_tasks(book_completion_task::class);
    }

    /**
     * Create a course that requires the given course as a prerequisite.
     *
     * @param int $prerequisiteid The prerequisite course.
     * @return \stdClass The dependent course.
     */
    protected function create_dependent_course(int $prerequisiteid): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE,
            'courseinstance' => $prerequisiteid,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        return $course;
    }

    /**
     * Build the task the observer would queue.
     *
     * @param int $courseid     Prerequisite course.
     * @param int $userid       User ID.
     * @param int $fromcourseid Resume position.
     * @return notify_dependent_courses_task
     */
    protected function make_task(int $courseid, int $userid, int $fromcourseid = 0): notify_dependent_courses_task {
        $task = new notify_dependent_courses_task();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'fromcourseid' => $fromcourseid,
        ]);

        return $task;
    }

    /**
     * Every dependent course is triggered for the user.
     *
     * @return void
     */
    public function test_execute_triggers_every_dependent_course(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $first = $this->create_dependent_course((int)$prerequisite->id);
        $second = $this->create_dependent_course((int)$prerequisite->id);
        criteria_index::purge();

        $this->make_task((int)$prerequisite->id, (int)$user->id)->execute();
        $this->resetDebugging();

        $courseids = [];
        foreach ($this->queued_bookings() as $task) {
            $courseids[] = (int)$task->get_custom_data()->courseid;
        }
        sort($courseids);

        $expected = [(int)$first->id, (int)$second->id];
        sort($expected);
        $this->assertSame($expected, $courseids);
    }

    /**
     * A course nothing depends on triggers nothing.
     *
     * @return void
     */
    public function test_execute_without_dependents(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $this->make_task((int)$prerequisite->id, (int)$user->id)->execute();

        $this->assertCount(0, $this->queued_bookings());
    }

    /**
     * The resume position skips the dependents already handled.
     *
     * @return void
     */
    public function test_execute_resumes_after_fromcourseid(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $first = $this->create_dependent_course((int)$prerequisite->id);
        $second = $this->create_dependent_course((int)$prerequisite->id);
        criteria_index::purge();

        $this->make_task((int)$prerequisite->id, (int)$user->id, (int)$first->id)->execute();
        $this->resetDebugging();

        $bookings = $this->queued_bookings();
        $this->assertCount(1, $bookings);
        $this->assertSame((int)$second->id, (int)reset($bookings)->get_custom_data()->courseid);
    }

    /**
     * The paging query returns the dependents in ascending order, bounded by the limit.
     *
     * @return void
     */
    public function test_dependent_course_ids_is_pageable(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $dependents = [];
        for ($i = 0; $i < 3; $i++) {
            $dependents[] = (int)$this->create_dependent_course((int)$prerequisite->id)->id;
        }
        sort($dependents);

        $firstpage = criteria_index::dependent_course_ids((int)$prerequisite->id, 0, 2);
        $this->assertSame(array_slice($dependents, 0, 2), $firstpage);

        $secondpage = criteria_index::dependent_course_ids((int)$prerequisite->id, (int)end($firstpage), 2);
        $this->assertSame(array_slice($dependents, 2), $secondpage);
    }

    /**
     * A dependent course with completion switched off is not returned.
     *
     * @return void
     */
    public function test_dependent_course_ids_ignores_disabled_courses(): void {
        global $DB;

        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $dependent = $this->create_dependent_course((int)$prerequisite->id);
        $DB->set_field('course', 'enablecompletion', 0, ['id' => (int)$dependent->id]);

        $this->assertSame([], criteria_index::dependent_course_ids((int)$prerequisite->id));
    }
}
