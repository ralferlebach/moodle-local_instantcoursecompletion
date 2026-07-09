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

/**
 * Discovery task tests.
 *
 * @covers \local_instantcoursecompletion\task\discover_due_criteria_task
 * @covers \local_instantcoursecompletion\task\book_due_completion_task
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

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);

        $task = reset($tasks);
        $data = $task->get_custom_data();
        $this->assertSame((int)$course->id, (int)$data->courseid);
        $this->assertSame((int)$user->id, (int)$data->userid);
        $this->assertSame($duetime, (int)$data->duetime);

        // The task never runs before the criterion falls due.
        $this->assertGreaterThanOrEqual($duetime, (int)$task->get_next_run_time());
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

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);
        $this->assertSame($timestart + DAYSECS * 3, (int)reset($tasks)->get_custom_data()->duetime);
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

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);
        $this->assertSame($timecreated + DAYSECS * 3, (int)reset($tasks)->get_custom_data()->duetime);
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
        $this->add_date_criterion($course, time() + DAYSECS);
        for ($i = 0; $i < 5; $i++) {
            $this->enrol_user_direct($course, $this->getDataGenerator()->create_user());
        }

        (new discover_due_criteria_task())->execute();

        $this->assertCount(2, $this->queued_tasks());
    }

    /**
     * Consecutive runs plan every user exactly once, never re-examining the first page.
     *
     * Progress is measured in records examined. A run that measured it in tasks queued
     * would see zero on its second pass over an already-planned page, conclude that the
     * course was finished, and leave the users behind that page unplanned forever.
     *
     * @return void
     */
    public function test_consecutive_runs_plan_every_user_exactly_once(): void {
        set_config('maxtasksperrun', 2, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS);

        $userids = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $user);
            $userids[] = (int)$user->id;
        }
        sort($userids);

        (new discover_due_criteria_task())->execute();
        $this->assertCount(2, $this->queued_tasks());

        (new discover_due_criteria_task())->execute();
        $this->assertCount(4, $this->queued_tasks());

        (new discover_due_criteria_task())->execute();
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
        $this->add_date_criterion($first, time() + DAYSECS);
        for ($i = 0; $i < 2; $i++) {
            $this->enrol_user_direct($first, $this->getDataGenerator()->create_user());
        }

        $second = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_date_criterion($second, time() + DAYSECS);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($second, $user);

        (new discover_due_criteria_task())->execute();
        (new discover_due_criteria_task())->execute();

        $this->assertCount(3, $this->queued_tasks());
        $this->assertContains((int)$user->id, $this->queued_user_ids());
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
     * The planned task books the completion when it runs.
     *
     * @return void
     */
    public function test_planned_task_books_the_completion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() - DAYSECS);
        $this->enrol_user_direct($course, $user);

        (new discover_due_criteria_task())->execute();

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);

        reset($tasks)->execute();
        $this->resetDebugging();

        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));
    }
}
