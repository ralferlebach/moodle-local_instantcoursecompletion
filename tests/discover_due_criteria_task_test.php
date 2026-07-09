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
 * Tests for the due-criteria discovery task.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\discover_due_criteria_task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');

/**
 * Discovery task tests.
 *
 * A date criterion falls due for the whole course at one instant and is planned as a
 * single batch task. A duration criterion falls due per learner and is planned per
 * learner; the cursor and budget tests therefore use duration criteria.
 *
 * @covers \local_instantcoursecompletion\task\discover_due_criteria_task
 */
final class discover_due_criteria_task_test extends \advanced_testcase {
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
        set_config('schedulinghorizon', WEEKSECS, 'local_instantcoursecompletion');
        set_config('maxtasksperrun', 5000, 'local_instantcoursecompletion');
    }

    /**
     * Run the discovery task once.
     *
     * @return void
     */
    protected function discover(): void {
        (new discover_due_criteria_task())->execute();
    }

    /**
     * Nothing is planned while scheduling is switched off.
     *
     * @return void
     */
    public function test_execute_exits_early_when_disabled(): void {
        set_config('schedulingenabled', 0, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A date criterion inside the horizon is planned once for the whole course.
     *
     * @return void
     */
    public function test_date_criterion_is_planned_as_one_batch(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criterionid = $this->add_date_criterion($course, time() + DAYSECS * 3);
        for ($i = 0; $i < 5; $i++) {
            $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());
        }

        $this->discover();

        // Five learners, one task.
        $this->assertCount(0, $this->queued_tasks());
        $task = $this->single_queued_batch_task();

        $data = $task->get_custom_data();
        $this->assertSame((int)$course->id, (int)$data->courseid);
        $this->assertSame($criterionid, (int)$data->criteriaid);
        $this->assertSame(0, (int)$data->lastuserid);
    }

    /**
     * The batch never runs before the criterion falls due.
     *
     * @return void
     */
    public function test_date_batch_waits_for_the_due_time(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $duetime = time() + DAYSECS * 3;
        $this->add_date_criterion($course, $duetime);
        $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());

        $this->discover();

        $this->assertSame($duetime, (int)$this->single_queued_batch_task()->get_next_run_time());
    }

    /**
     * An overdue date criterion is planned to run immediately.
     *
     * @return void
     */
    public function test_overdue_date_criterion_runs_without_delay(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() - DAYSECS);
        $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());

        $now = time();
        $this->discover();

        $this->assertLessThanOrEqual($now + 1, (int)$this->single_queued_batch_task()->get_next_run_time());
    }

    /**
     * A date criterion beyond the horizon is left for a later run.
     *
     * @return void
     */
    public function test_date_criterion_beyond_horizon_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() + WEEKSECS * 4);
        $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A criterion nobody owes any more is not planned at all.
     *
     * @return void
     */
    public function test_date_criterion_without_due_users_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $criterionid = $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);
        $this->mark_criterion_completed($course, $user, $criterionid);

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A user who already completed the course is not counted as due.
     *
     * @return void
     */
    public function test_completed_user_does_not_trigger_a_batch(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);
        $this->mark_course_completed($course, $user);

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A user whose enrolment expired is not counted as due.
     *
     * @return void
     */
    public function test_expired_enrolment_does_not_trigger_a_batch(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user, time() - DAYSECS * 10, time() - DAYSECS * 10, time() - DAYSECS);

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A teacher does not hold moodle/course:isincompletionreports and is not counted.
     *
     * @return void
     */
    public function test_untracked_users_do_not_trigger_a_batch(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * A suspended account cannot own a booking and is not counted.
     *
     * @return void
     */
    public function test_suspended_users_do_not_trigger_a_batch(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * Out-of-scope courses are never planned for.
     *
     * @return void
     */
    public function test_out_of_scope_course_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());
        $this->restrict_scope_to_new_category();

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
    }

    /**
     * Running twice does not queue the same batch twice.
     *
     * @return void
     */
    public function test_repeated_runs_do_not_duplicate_the_batch(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS * 3);
        $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());

        $this->discover();
        $this->discover();

        $this->assertCount(1, $this->queued_batch_tasks());
    }

    /**
     * A duration criterion falls due per user, from the enrolment start.
     *
     * @return void
     */
    public function test_duration_criterion_uses_enrolment_start(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timestart = time() - DAYSECS;
        $this->add_duration_criterion($course, DAYSECS * 3);
        $this->enrol_user_direct($course, $user, $timestart, time() - DAYSECS * 2);

        $this->discover();

        $this->assertCount(0, $this->queued_batch_tasks());
        $this->assert_due_at($this->single_queued_task(), $timestart + DAYSECS * 3);
    }

    /**
     * Without an enrolment start date, the enrolment creation time is used.
     *
     * @return void
     */
    public function test_duration_criterion_falls_back_to_timecreated(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timecreated = time() - DAYSECS;
        $this->add_duration_criterion($course, DAYSECS * 3);
        $this->enrol_user_direct($course, $user, 0, $timecreated);

        $this->discover();

        $this->assert_due_at($this->single_queued_task(), $timecreated + DAYSECS * 3);
    }

    /**
     * A duration criterion falling due beyond the horizon is not planned.
     *
     * @return void
     */
    public function test_duration_criterion_beyond_horizon_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_duration_criterion($course, WEEKSECS * 8);
        $this->enrol_user_direct($course, $user, time(), time());

        $this->discover();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A moved enrolment start moves the existing task instead of adding a second one.
     *
     * @return void
     */
    public function test_rescheduling_moves_the_existing_task(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timestart = time() - DAYSECS;
        $this->add_duration_criterion($course, DAYSECS * 3);
        $this->enrol_user_direct($course, $user, $timestart, $timestart);

        $this->discover();
        $this->assert_due_at($this->single_queued_task(), $timestart + DAYSECS * 3);

        $moved = $timestart + DAYSECS;
        $DB->set_field('user_enrolments', 'timestart', $moved, ['userid' => (int)$user->id]);

        $this->discover();

        $this->assertCount(1, $this->queued_tasks());
        $this->assert_due_at($this->single_queued_task(), $moved + DAYSECS * 3);
    }

    /**
     * The per-run budget caps the enrolment records examined.
     *
     * @return void
     */
    public function test_budget_caps_records_examined_per_run(): void {
        set_config('maxtasksperrun', 2, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_duration_criterion($course, DAYSECS);
        for ($i = 0; $i < 5; $i++) {
            $this->enrol_user_direct($course, $this->getDataGenerator()->create_user(), time(), time());
        }

        $this->discover();

        $this->assertCount(2, $this->queued_tasks());
    }

    /**
     * Consecutive runs plan every user exactly once, never re-examining the first page.
     *
     * Progress is measured in records examined. A run that measured it in tasks planned
     * would see zero on its second pass over an already-planned page, conclude that the
     * course was finished, and leave the users behind that page unplanned forever.
     *
     * @return void
     */
    public function test_consecutive_runs_plan_every_user_exactly_once(): void {
        set_config('maxtasksperrun', 2, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_duration_criterion($course, DAYSECS);

        $userids = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $user, time(), time());
            $userids[] = (int)$user->id;
        }
        sort($userids);

        $this->discover();
        $this->assertCount(2, $this->queued_tasks());

        $this->discover();
        $this->assertCount(4, $this->queued_tasks());

        $this->discover();
        $this->assertCount(5, $this->queued_tasks());

        $this->assertSame($userids, $this->queued_user_ids());
    }

    /**
     * A course that fills the budget does not block the courses behind it.
     *
     * @return void
     */
    public function test_consecutive_runs_reach_the_next_course(): void {
        set_config('maxtasksperrun', 2, 'local_instantcoursecompletion');

        $first = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_duration_criterion($first, DAYSECS);
        for ($i = 0; $i < 2; $i++) {
            $this->enrol_user_direct($first, $this->getDataGenerator()->create_user(), time(), time());
        }

        $second = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_duration_criterion($second, DAYSECS);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($second, $user, time(), time());

        $this->discover();
        $this->discover();

        $this->assertCount(3, $this->queued_tasks());
        $this->assertContains((int)$user->id, $this->queued_user_ids());
    }

    /**
     * The planned per-user task books the completion when it runs.
     *
     * @return void
     */
    public function test_planned_task_books_the_completion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_duration_criterion($course, DAYSECS);
        $this->enrol_user_direct($course, $user, time() - DAYSECS * 3, time() - DAYSECS * 3);

        $this->discover();
        $this->single_queued_task()->execute();
        $this->resetDebugging();

        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));
    }
}
