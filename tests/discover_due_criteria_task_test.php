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
 * Enrolment records are written directly so that user_enrolment_created stays silent;
 * other installed plugins observe that event and misbehave under PHPUnit.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\discover_due_criteria_task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');
require_once(__DIR__ . '/fixtures/discover_due_criteria_task_zero_runtime.php');

/**
 * Discovery task tests.
 *
 * @covers \local_instantcoursecompletion\task\discover_due_criteria_task
 * @covers \local_instantcoursecompletion\completion_course_repository
 * @covers \local_instantcoursecompletion\due_candidate_repository
 * @covers \local_instantcoursecompletion\task\book_due_completion_batch_task
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
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
        set_config('schedulingenabled', 1, 'local_instantcoursecompletion');
        set_config('schedulinghorizon', WEEKSECS, 'local_instantcoursecompletion');
        set_config('maxtasksperrun', 5000, 'local_instantcoursecompletion');
    }

    /**
     * Nothing happens while scheduling is switched off.
     *
     * @return void
     */
    public function test_execute_exits_early_when_disabled(): void {
        set_config('schedulingenabled', 0, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A run that exhausts its wall-clock budget stops and leaves its cursor to resume.
     *
     * @return void
     */
    public function test_execute_persists_the_cursor_when_it_runs_out_of_time(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $criterionid = $this->add_date_criterion($course, time() + DAYSECS * 3);
        $this->enrol_user_direct($course, $user);

        (new discover_due_criteria_task_zero_runtime())->execute();

        // With no budget the run stops before planning and leaves the cursor on the course
        // and criterion, so the next run resumes exactly there.
        $this->assertCount(0, $this->queued_tasks());

        $cursor = json_decode(get_config('local_instantcoursecompletion', 'schedulecursor'), true);
        $this->assertSame((int)$course->id, (int)$cursor['courseid']);
        $this->assertSame($criterionid, (int)$cursor['criteriaid']);
        $this->assertSame(0, (int)$cursor['lastuserid']);
    }

    /**
     * A date criterion inside the horizon is planned for each enrolled user.
     *
     * @return void
     */
    public function test_date_criterion_inside_horizon_is_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $duetime = time() + DAYSECS * 3;
        $this->add_date_criterion($course, $duetime);
        $this->enrol_user_direct($course, $user);

        (new discover_due_criteria_task())->execute();

        $task = $this->single_queued_task();
        $data = $task->get_custom_data();
        $this->assertSame((int)$course->id, (int)$data->courseid);
        $this->assertGreaterThan(0, (int)$data->criteriaid);
        $this->assertSame(0, (int)$data->lastuserid);

        // The task never runs before the criterion falls due.
        $this->assert_due_at($task, $duetime);
    }

    /**
     * A date criterion beyond the horizon is left for a later run.
     *
     * @return void
     */
    public function test_date_criterion_beyond_horizon_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + WEEKSECS * 4);
        $this->enrol_user_direct($course, $user);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * An overdue date criterion is planned to run immediately.
     *
     * @return void
     */
    public function test_overdue_date_criterion_runs_without_delay(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() - DAYSECS);
        $this->enrol_user_direct($course, $user);

        $now = time();
        (new discover_due_criteria_task())->execute();

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);
        $this->assertLessThanOrEqual($now + 900, (int)reset($tasks)->get_next_run_time());
    }

    /**
     * A user who already completed the course is not planned for.
     *
     * @return void
     */
    public function test_completed_user_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);
        $this->mark_course_completed($course, $user);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A user whose enrolment expired is not planned for.
     *
     * @return void
     */
    public function test_expired_enrolment_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user, time() - DAYSECS * 10, time() - DAYSECS * 10, time() - DAYSECS);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * Running twice does not queue the same booking twice.
     *
     * @return void
     */
    public function test_repeated_runs_do_not_duplicate(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS * 3);
        $this->enrol_user_direct($course, $user);

        (new discover_due_criteria_task())->execute();
        (new discover_due_criteria_task())->execute();

        $this->assertCount(1, $this->queued_tasks());
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

        (new discover_due_criteria_task())->execute();

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

        (new discover_due_criteria_task())->execute();

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
        $this->enrol_user_direct($course, $user, time() - HOURSECS, time() - HOURSECS);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A criterion the user already satisfied is not planned again.
     *
     * @return void
     */
    public function test_satisfied_criterion_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $criterionid = $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->mark_criterion_completed($course, $user, $criterionid);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * Out-of-scope courses are never planned for.
     *
     * @return void
     */
    public function test_out_of_scope_course_is_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->restrict_scope_to_new_category();

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
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
            $timestart = time() - DAYSECS + HOURSECS * ($i + 1);
            $this->enrol_user_direct($course, $this->getDataGenerator()->create_user(), $timestart, $timestart);
        }

        (new discover_due_criteria_task())->execute();

        $this->assertCount(2, $this->queued_tasks());
    }

    /**
     * Consecutive runs page through the users of a duration criterion exactly once.
     *
     * Progress is measured in records examined. A run that measured it in tasks planned
     * would see zero on its second pass over an already-planned page, conclude that the
     * course was finished, and leave the users behind that page unplanned forever.
     *
     * @return void
     */
    public function test_consecutive_runs_page_through_every_user(): void {
        set_config('maxtasksperrun', 2, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_duration_criterion($course, DAYSECS);

        // Five enrolments, each an hour apart, so each falls due in its own window.
        for ($i = 0; $i < 5; $i++) {
            $timestart = time() - DAYSECS + HOURSECS * ($i + 1);
            $this->enrol_user_direct($course, $this->getDataGenerator()->create_user(), $timestart, $timestart);
        }

        (new discover_due_criteria_task())->execute();
        $this->assertCount(2, $this->queued_tasks());

        (new discover_due_criteria_task())->execute();
        $this->assertCount(4, $this->queued_tasks());

        (new discover_due_criteria_task())->execute();
        $this->assertCount(5, $this->queued_tasks());
    }

    /**
     * A date criterion costs one batch task, whatever the size of the cohort.
     *
     * @return void
     */
    public function test_date_criterion_plans_one_task_for_the_whole_cohort(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS);
        for ($i = 0; $i < 5; $i++) {
            $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());
        }

        (new discover_due_criteria_task())->execute();

        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * A date criterion nobody owes any more is not planned again.
     *
     * @return void
     */
    public function test_date_criterion_is_not_replanned_once_booked(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $criterionid = $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);
        $this->mark_criterion_completed($course, $user, $criterionid);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
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
            $timestart = time() - DAYSECS + HOURSECS * ($i + 1);
            $this->enrol_user_direct($first, $this->getDataGenerator()->create_user(), $timestart, $timestart);
        }

        $second = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $secondcriterion = $this->add_date_criterion($second, time() + DAYSECS);
        $this->enrol_user_direct($second, $this->getDataGenerator()->create_user());

        // The first run fills its budget inside the first course.
        (new discover_due_criteria_task())->execute();
        $this->assertNotContains($secondcriterion, $this->queued_criteria_ids());

        (new discover_due_criteria_task())->execute();

        $this->assertContains($secondcriterion, $this->queued_criteria_ids());
    }

    /**
     * A teacher does not hold moodle/course:isincompletionreports and is never planned.
     *
     * @return void
     */
    public function test_untracked_users_are_not_planned(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');

        (new discover_due_criteria_task())->execute();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * A moved enrolment start plans a task for the new window.
     *
     * The window is part of the de-duplication key: a duration criterion falls due at a
     * different moment for every learner, so each window needs its own task. The task
     * left behind in the old window runs before the criterion is satisfied, finds nobody
     * due, and books nothing.
     *
     * @return void
     */
    public function test_moved_due_time_plans_the_new_window(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timestart = time() - DAYSECS;
        $this->add_duration_criterion($course, DAYSECS * 3);
        $this->enrol_user_direct($course, $user, $timestart, $timestart);

        (new discover_due_criteria_task())->execute();
        $this->assert_due_at($this->single_queued_task(), $timestart + DAYSECS * 3);

        // The correction stays comfortably in the past: get_enrolled_sql() treats an
        // enrolment as active only while ue.timestart < round(time(), -2), so a start date
        // set to "now" falls on the wrong side of that boundary about half the time.
        $moved = $timestart + HOURSECS;
        $DB->set_field('user_enrolments', 'timestart', $moved, ['userid' => (int)$user->id]);

        (new discover_due_criteria_task())->execute();

        $this->assertCount(2, $this->queued_tasks());
        $this->assertSame(
            [
                due_scheduler::due_bucket($timestart + DAYSECS * 3),
                due_scheduler::due_bucket($moved + DAYSECS * 3),
            ],
            $this->queued_run_times()
        );

        // The stale window books nobody: the criterion is not satisfied yet.
        $this->run_queued_tasks();
        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * The planned task books the completion when it runs.
     *
     * @return void
     */
    public function test_planned_task_books_every_due_user(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() - DAYSECS);

        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[$i] = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $users[$i]);
        }

        (new discover_due_criteria_task())->execute();
        $this->assertCount(1, $this->queued_tasks());

        $this->run_queued_tasks();

        $info = new \completion_info($course);
        foreach ($users as $user) {
            $this->assertTrue($info->is_course_complete((int)$user->id));
        }
    }

    /**
     * A batch that fills its page hands the rest on to a continuation task.
     *
     * @return void
     */
    public function test_batch_task_queues_a_continuation(): void {
        set_config('batchsize', 2, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() - DAYSECS);

        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[$i] = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $users[$i]);
        }

        (new discover_due_criteria_task())->execute();
        $this->single_queued_task()->execute();
        $this->resetDebugging();

        $info = new \completion_info($course);
        $this->assertTrue($info->is_course_complete((int)$users[0]->id));
        $this->assertTrue($info->is_course_complete((int)$users[1]->id));
        $this->assertFalse($info->is_course_complete((int)$users[2]->id));

        // Executing a task by hand does not consume it, so the continuation is the
        // second one in the queue.
        $continuations = $this->queued_continuations();
        $this->assertCount(1, $continuations);

        $continuation = reset($continuations);
        $this->assertGreaterThan(0, (int)$continuation->get_custom_data()->lastuserid);

        $continuation->execute();
        $this->resetDebugging();

        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$users[2]->id));
    }
}
