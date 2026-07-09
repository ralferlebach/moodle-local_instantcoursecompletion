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
 * Tests for the event observer trigger handling.
 *
 * Drives observer::handle_completion_trigger() directly, so the tests are
 * deterministic and do not depend on enrolment or activity-completion machinery
 * (which would trigger other installed plugins' observers).
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_completion_task;

/**
 * Observer trigger-handling tests.
 *
 * @covers \local_instantcoursecompletion\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Reset the per-request de-duplication registry before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        observer::reset_seen();
    }

    /**
     * Count queued booking ad-hoc tasks.
     *
     * @return int
     */
    protected function queued_tasks(): int {
        return count(\core\task\manager::get_adhoc_tasks(book_completion_task::class));
    }

    /**
     * An in-scope trigger enqueues exactly one booking task with the right data.
     *
     * @return void
     */
    public function test_in_scope_trigger_enqueues_task(): void {
        $this->resetAfterTest(true);
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
        set_config('processingmode', 'async', 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);

        $tasks = \core\task\manager::get_adhoc_tasks(book_completion_task::class);
        $this->assertCount(1, $tasks);

        $data = reset($tasks)->get_custom_data();
        $this->assertEquals($course->id, $data->courseid);
        $this->assertEquals($user->id, $data->userid);
    }

    /**
     * Repeated triggers for the same (course, user) collapse to one task.
     *
     * @return void
     */
    public function test_repeated_trigger_is_deduplicated(): void {
        $this->resetAfterTest(true);
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
        set_config('processingmode', 'async', 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);
        observer::handle_completion_trigger((int)$course->id, (int)$user->id);

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * An out-of-scope trigger enqueues nothing.
     *
     * @return void
     */
    public function test_out_of_scope_trigger_enqueues_nothing(): void {
        $this->resetAfterTest(true);
        // Categories scope with no category selected → nothing is in scope.
        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', '', 'local_instantcoursecompletion');
        set_config('processingmode', 'async', 'local_instantcoursecompletion');
        scope_resolver::purge_cache();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);

        $this->assertSame(0, $this->queued_tasks());
    }

    /**
     * Synchronous mode books in the request and queues no ad-hoc task.
     *
     * @return void
     */
    public function test_sync_mode_does_not_enqueue(): void {
        $this->resetAfterTest(true);
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
        set_config('processingmode', 'sync', 'local_instantcoursecompletion');

        // Course without completion enabled → booker returns quickly, no task queued.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);

        $this->assertSame(0, $this->queued_tasks());
    }
}
