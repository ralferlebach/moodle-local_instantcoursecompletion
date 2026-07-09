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
 * Tests for the due-time scheduler.
 *
 * Enrolment records are written directly so that user_enrolment_created stays silent;
 * other installed plugins observe that event and misbehave under PHPUnit. The observer
 * that reacts to it is exercised by calling the scheduler directly.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');

/**
 * Due scheduler tests.
 *
 * @covers \local_instantcoursecompletion\due_scheduler
 */
final class due_scheduler_test extends \advanced_testcase {
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
        criteria_index::purge();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
        set_config('schedulingenabled', 1, 'local_instantcoursecompletion');
        set_config('schedulinghorizon', WEEKSECS, 'local_instantcoursecompletion');
    }

    /**
     * A newly enrolled user gets a duration booking planned at once.
     *
     * @return void
     */
    public function test_schedule_user_plans_duration_criterion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timestart = time() - DAYSECS;
        $this->add_duration_criterion($course, DAYSECS * 3);
        $this->enrol_user_direct($course, $user, $timestart, $timestart);

        $this->assertSame(1, due_scheduler::schedule_user((int)$course->id, (int)$user->id));

        $task = $this->single_queued_task();
        $this->assert_due_at($task, $timestart + DAYSECS * 3);
        $this->assertSame((int)$user->id, (int)$task->get_custom_data()->userid);
    }

    /**
     * A newly enrolled user also gets the course-wide date criterion planned.
     *
     * @return void
     */
    public function test_schedule_user_plans_date_criterion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $duetime = time() + DAYSECS * 2;
        $this->add_date_criterion($course, $duetime);
        $this->enrol_user_direct($course, $user);

        $this->assertSame(1, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
        $this->assert_due_at($this->single_queued_task(), $duetime);
    }

    /**
     * Both time-based criteria of a course are planned independently.
     *
     * @return void
     */
    public function test_schedule_user_plans_every_time_criterion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->add_duration_criterion($course, DAYSECS * 2);
        $this->enrol_user_direct($course, $user, time(), time());

        $this->assertSame(2, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
        $this->assertCount(2, $this->queued_tasks());
    }

    /**
     * A due time beyond the horizon is left to a later discovery run.
     *
     * @return void
     */
    public function test_schedule_user_respects_the_horizon(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_duration_criterion($course, WEEKSECS * 8);
        $this->enrol_user_direct($course, $user, time(), time());

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * Nothing is planned while scheduling is switched off.
     *
     * @return void
     */
    public function test_schedule_user_honours_the_setting(): void {
        set_config('schedulingenabled', 0, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
    }

    /**
     * A course without time-based criteria is answered from the cached index alone.
     *
     * @return void
     */
    public function test_schedule_user_skips_courses_without_time_criteria(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $user);

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
    }

    /**
     * A user who is not actively enrolled is not planned for.
     *
     * @return void
     */
    public function test_schedule_user_requires_an_active_enrolment(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));

        $this->enrol_user_direct($course, $user, time() - DAYSECS * 10, time() - DAYSECS * 10, time() - DAYSECS);
        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
    }

    /**
     * A teacher does not hold moodle/course:isincompletionreports and is never planned.
     *
     * @return void
     */
    public function test_schedule_user_skips_untracked_users(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$teacher->id));
    }

    /**
     * An out-of-scope course is never planned for.
     *
     * @return void
     */
    public function test_schedule_user_respects_scope(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->restrict_scope_to_new_category();

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
    }

    /**
     * A user who already completed the course is not planned for.
     *
     * @return void
     */
    public function test_schedule_user_skips_completed_users(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);
        $this->mark_course_completed($course, $user);

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
    }

    /**
     * A criterion the user already satisfied is not planned again.
     *
     * @return void
     */
    public function test_schedule_user_skips_recorded_criteria(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $criterionid = $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->mark_criterion_completed($course, $user, $criterionid);

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
    }

    /**
     * Calling twice does not queue the same booking twice.
     *
     * The queue itself decides: an identical class, component, custom data and user
     * collapse into the task that is already there.
     *
     * @return void
     */
    public function test_schedule_user_is_idempotent(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        due_scheduler::schedule_user((int)$course->id, (int)$user->id);
        due_scheduler::schedule_user((int)$course->id, (int)$user->id);

        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * A moved enrolment start moves the existing task instead of adding a second one.
     *
     * @return void
     */
    public function test_schedule_user_reschedules_a_moved_due_time(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timestart = time() - DAYSECS;
        $this->add_duration_criterion($course, DAYSECS * 3);
        $this->enrol_user_direct($course, $user, $timestart, $timestart);

        due_scheduler::schedule_user((int)$course->id, (int)$user->id);
        $this->assert_due_at($this->single_queued_task(), $timestart + DAYSECS * 3);

        // The administrator corrects the enrolment start by one day.
        $moved = $timestart + DAYSECS;
        $DB->set_field('user_enrolments', 'timestart', $moved, ['userid' => (int)$user->id]);

        due_scheduler::schedule_user((int)$course->id, (int)$user->id);

        $this->assertCount(1, $this->queued_tasks());
        $this->assert_due_at($this->single_queued_task(), $moved + DAYSECS * 3);
    }

    /**
     * A suspended account cannot own an ad-hoc task and is never planned.
     *
     * @return void
     */
    public function test_schedule_user_skips_suspended_users(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->add_date_criterion($course, time() + DAYSECS);
        $this->enrol_user_direct($course, $user);

        $this->assertSame(0, due_scheduler::schedule_user((int)$course->id, (int)$user->id));
        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * The course lock can be taken and released.
     *
     * @return void
     */
    public function test_course_lock_round_trip(): void {
        $course = $this->getDataGenerator()->create_course();

        $lock = due_scheduler::course_lock((int)$course->id, 0);

        $this->assertInstanceOf(\core\lock\lock::class, $lock);
        $this->assertTrue($lock->release());
    }

    /**
     * Without an enrolment start date, the enrolment creation time is used.
     *
     * @return void
     */
    public function test_time_enrolled_falls_back_to_timecreated(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timecreated = time() - DAYSECS * 5;
        $this->enrol_user_direct($course, $user, 0, $timecreated);

        $this->assertSame($timecreated, due_scheduler::time_enrolled((int)$course->id, (int)$user->id));
    }

    /**
     * A user with no enrolment at all has no enrolment time.
     *
     * @return void
     */
    public function test_time_enrolled_without_enrolment(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(due_scheduler::time_enrolled((int)$course->id, (int)$user->id));
    }
}
