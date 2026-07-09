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
 * Tests for the batched due-booking task.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_due_completion_batch_task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');

/**
 * Batched due-booking task tests.
 *
 * @covers \local_instantcoursecompletion\task\book_due_completion_batch_task
 * @covers \local_instantcoursecompletion\course_booker
 */
final class book_due_completion_batch_task_test extends \advanced_testcase {
    use completion_test_trait;

    /**
     * Load completionlib, reset state and enable scheduling.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest(true);
        $this->enable_due_scheduling();
    }

    /**
     * Create a course with an overdue date criterion and the given number of learners.
     *
     * @param int $learners How many tracked learners to enrol.
     * @return array Three elements: course, criterion ID, learner records.
     */
    protected function overdue_course(int $learners): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criterionid = $this->add_date_criterion($course, time() - DAYSECS);

        $users = [];
        for ($i = 0; $i < $learners; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $user);
            $users[] = $user;
        }

        return [$course, $criterionid, $users];
    }

    /**
     * Whether the course is complete for the user.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @return bool
     */
    protected function is_complete(\stdClass $course, \stdClass $user): bool {
        return (new \completion_info($course))->is_course_complete((int)$user->id);
    }

    /**
     * Run the next due batch task the way cron does, and remove it from the queue.
     *
     * Calling execute() on its own leaves the record behind; a continuation would then
     * be indistinguishable from the head that queued it.
     *
     * @return \core\task\adhoc_task|null The task that ran, or null when none was due.
     */
    protected function run_next_batch(): ?\core\task\adhoc_task {
        $task = \core\task\manager::get_next_adhoc_task(time() + 1, true, book_due_completion_batch_task::class);
        if ($task === null) {
            return null;
        }

        $task->execute();
        \core\task\manager::adhoc_task_complete($task);
        $this->resetDebugging();

        return $task;
    }

    /**
     * A batch smaller than the page size books everyone and queues no continuation.
     *
     * @return void
     */
    public function test_single_page_books_the_whole_cohort(): void {
        set_config('batchsize', 10, 'local_instantcoursecompletion');
        [$course, $criterionid, $users] = $this->overdue_course(3);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        $this->assertNotNull($this->run_next_batch());

        foreach ($users as $user) {
            $this->assertTrue($this->is_complete($course, $user));
        }

        // The page was not full, so nothing follows it.
        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A full page books its users and hands the rest to a continuation.
     *
     * @return void
     */
    public function test_full_page_queues_a_continuation(): void {
        set_config('batchsize', 2, 'local_instantcoursecompletion');
        [$course, $criterionid, $users] = $this->overdue_course(5);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        $this->assertNotNull($this->run_next_batch());

        $this->assertTrue($this->is_complete($course, $users[0]));
        $this->assertTrue($this->is_complete($course, $users[1]));
        $this->assertFalse($this->is_complete($course, $users[2]));

        $continuation = $this->single_queued_batch_task();
        $this->assertSame((int)$users[1]->id, (int)$continuation->get_custom_data()->lastuserid);
    }

    /**
     * Consecutive pages book every learner exactly once.
     *
     * @return void
     */
    public function test_continuations_book_the_whole_cohort(): void {
        set_config('batchsize', 2, 'local_instantcoursecompletion');
        [$course, $criterionid, $users] = $this->overdue_course(5);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());

        // Three pages of two, the last one short.
        $pages = 0;
        while ($this->run_next_batch() !== null && $pages < 10) {
            $pages++;
        }
        $this->assertSame(3, $pages);

        foreach ($users as $user) {
            $this->assertTrue($this->is_complete($course, $user));
        }
        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A criterion nobody owes any more is a no-op.
     *
     * @return void
     */
    public function test_batch_without_due_users_does_nothing(): void {
        [$course, $criterionid, $users] = $this->overdue_course(1);
        $this->mark_criterion_completed($course, $users[0], $criterionid);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        $this->assertNotNull($this->run_next_batch());

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A teacher is never booked by the batch.
     *
     * @return void
     */
    public function test_batch_ignores_untracked_users(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criterionid = $this->add_date_criterion($course, time() - DAYSECS);
        $teacher = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        $this->assertNotNull($this->run_next_batch());

        $this->assertFalse($this->is_complete($course, $teacher));
    }

    /**
     * A course that left the scope between planning and execution is not booked.
     *
     * @return void
     */
    public function test_batch_rechecks_the_scope(): void {
        [$course, $criterionid, $users] = $this->overdue_course(1);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        $task = $this->single_queued_batch_task();

        $this->restrict_scope_to_new_category();
        $task->execute();

        $this->assertFalse($this->is_complete($course, $users[0]));
    }

    /**
     * Planning the same batch twice does not queue it twice.
     *
     * @return void
     */
    public function test_queue_batch_is_idempotent(): void {
        [$course, $criterionid] = $this->overdue_course(1);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        due_scheduler::queue_batch((int)$course->id, $criterionid, time());

        $this->assertCount(1, $this->queued_batch_tasks());
    }

    /**
     * A continuation is a different task from the head it follows.
     *
     * @return void
     */
    public function test_continuation_does_not_collide_with_the_head(): void {
        [$course, $criterionid, $users] = $this->overdue_course(1);

        due_scheduler::queue_batch((int)$course->id, $criterionid, time());
        due_scheduler::queue_batch((int)$course->id, $criterionid, time(), (int)$users[0]->id);

        $this->assertCount(2, $this->queued_batch_tasks());
    }
}
