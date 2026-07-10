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
require_once(__DIR__ . '/fixtures/book_due_completion_batch_task_zero_runtime.php');

/**
 * Batched due-booking task tests.
 *
 * @covers \local_instantcoursecompletion\task\book_due_completion_batch_task
 * @covers \local_instantcoursecompletion\due_candidate_repository
 * @covers \local_instantcoursecompletion\completion_booker::book_criterion
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
     * A due time comfortably in the past, so that its rounded-up window is too.
     *
     * @return int
     */
    protected function overdue_time(): int {
        return time() - DAYSECS;
    }

    /**
     * Create a course with an overdue date criterion and the given number of learners.
     *
     * @param int $learners How many tracked learners to enrol.
     * @return array Three elements: course, criterion ID, learner records.
     */
    protected function overdue_course(int $learners): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criterionid = $this->add_date_criterion($course, $this->overdue_time());

        $users = [];
        for ($i = 0; $i < $learners; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $user);
            $users[] = $user;
        }

        return [$course, $criterionid, $users];
    }

    /**
     * Plan the head batch for an overdue criterion.
     *
     * The due window is derived from the criterion's own due time, not from now: it is
     * rounded up, so the window of a due time in the future would also be in the future
     * and the task would not be runnable yet.
     *
     * @param \stdClass $course      The course.
     * @param int       $criterionid The criterion.
     * @return void
     */
    protected function plan_head_batch(\stdClass $course, int $criterionid): void {
        $bucket = due_scheduler::due_bucket($this->overdue_time());
        due_scheduler::queue_bucket((int)$course->id, $criterionid, $bucket);
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
        // The task manager caches the queue in process-level statics that PHPUnit does not
        // reset between tests, and the concurrency bookkeeping those statics feed is a
        // cron-runner concern with no place in a unit test.
        \core\task\manager::reset_state();

        $task = \core\task\manager::get_next_adhoc_task(time() + 1, false, book_due_completion_batch_task::class);
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

        $this->plan_head_batch($course, $criterionid);
        $this->assertNotNull($this->run_next_batch());

        foreach ($users as $user) {
            $this->assertTrue($this->is_complete($course, $user));
        }

        // The page was not full, so nothing follows it.
        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A full page books its users and hands the rest to a continuation.
     *
     * @return void
     */
    public function test_full_page_queues_a_continuation(): void {
        set_config('batchsize', 2, 'local_instantcoursecompletion');
        [$course, $criterionid, $users] = $this->overdue_course(5);

        $this->plan_head_batch($course, $criterionid);
        $this->assertNotNull($this->run_next_batch());

        $this->assertTrue($this->is_complete($course, $users[0]));
        $this->assertTrue($this->is_complete($course, $users[1]));
        $this->assertFalse($this->is_complete($course, $users[2]));

        $continuation = $this->single_queued_task();
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

        $this->plan_head_batch($course, $criterionid);

        // Three pages of two, the last one short.
        $pages = 0;
        while ($pages < 10 && $this->run_next_batch() !== null) {
            $pages++;
        }
        $this->assertSame(3, $pages);

        foreach ($users as $user) {
            $this->assertTrue($this->is_complete($course, $user));
        }
        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A criterion nobody owes any more is a no-op.
     *
     * @return void
     */
    public function test_batch_without_due_users_does_nothing(): void {
        [$course, $criterionid, $users] = $this->overdue_course(1);
        $this->mark_criterion_completed($course, $users[0], $criterionid);

        $this->plan_head_batch($course, $criterionid);
        $this->assertNotNull($this->run_next_batch());

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A teacher is never booked by the batch.
     *
     * @return void
     */
    public function test_batch_ignores_untracked_users(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criterionid = $this->add_date_criterion($course, $this->overdue_time());
        $teacher = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');

        $this->plan_head_batch($course, $criterionid);
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

        $this->plan_head_batch($course, $criterionid);
        $task = $this->single_queued_task();

        $this->restrict_scope_to_new_category();
        $task->execute();

        $this->assertFalse($this->is_complete($course, $users[0]));
    }

    /**
     * A criterion removed between planning and execution is not booked.
     *
     * @return void
     */
    public function test_batch_tolerates_a_removed_criterion(): void {
        global $DB;
        [$course, $criterionid, $users] = $this->overdue_course(1);

        $this->plan_head_batch($course, $criterionid);
        $DB->delete_records('course_completion_criteria', ['id' => $criterionid]);

        $this->assertNotNull($this->run_next_batch());

        $this->assertFalse($this->is_complete($course, $users[0]));
    }

    /**
     * Planning the same batch twice does not queue it twice.
     *
     * @return void
     */
    public function test_queue_bucket_is_idempotent(): void {
        [$course, $criterionid] = $this->overdue_course(1);

        $this->plan_head_batch($course, $criterionid);
        $this->plan_head_batch($course, $criterionid);

        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * A continuation is a different task from the head it follows.
     *
     * @return void
     */
    public function test_continuation_does_not_collide_with_the_head(): void {
        [$course, $criterionid, $users] = $this->overdue_course(1);
        $bucket = due_scheduler::due_bucket($this->overdue_time());

        due_scheduler::queue_bucket((int)$course->id, $criterionid, $bucket);
        due_scheduler::queue_continuation((int)$course->id, $criterionid, $bucket, (int)$users[0]->id);

        $this->assertCount(2, $this->queued_tasks());
        $this->assertCount(1, $this->queued_continuations());
    }

    /**
     * A run that exhausts its wall-clock budget books what it can and hands the rest on.
     *
     * @return void
     */
    public function test_execute_queues_a_continuation_when_it_runs_out_of_time(): void {
        [$course, $criterionid, $users] = $this->overdue_course(3);

        $task = new book_due_completion_batch_task_zero_runtime();
        $task->set_custom_data((object)[
            'courseid' => (int)$course->id,
            'criteriaid' => $criterionid,
            'duebucket' => due_scheduler::due_bucket($this->overdue_time()),
            'lastuserid' => 0,
        ]);
        $task->execute();
        $this->resetDebugging();

        // The first learner is booked; the run breaks after one and queues a continuation
        // that resumes after them, so nobody is skipped and nobody is re-booked.
        $this->assertTrue($this->is_complete($course, $users[0]));
        $this->assertFalse($this->is_complete($course, $users[1]));

        $continuations = $this->queued_continuations();
        $this->assertCount(1, $continuations);
        $this->assertSame((int)$users[0]->id, (int)$continuations[0]->get_custom_data()->lastuserid);
    }
}
