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
 * Tests for the reconcile scheduled task.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\reconcile_task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');

/**
 * Reconcile task tests.
 *
 * @covers \local_instantcoursecompletion\task\reconcile_task
 */
final class reconcile_task_test extends \advanced_testcase {
    use completion_test_trait;

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
        criteria_index::purge();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
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
     * The task does nothing while the feature is switched off.
     *
     * @return void
     */
    public function test_execute_exits_early_when_disabled(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->add_activity_criterion($course);
        $this->enrol_user_direct($course, $user);
        $this->complete_activity($course, $cm, $user, true);

        (new reconcile_task())->execute();

        $this->assertFalse($this->is_complete($course, $user));
    }

    /**
     * A satisfied criterion of an actively enrolled user is booked.
     *
     * @return void
     */
    public function test_execute_books_pending_completions(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->add_activity_criterion($course);
        $this->enrol_user_direct($course, $user);
        $this->complete_activity($course, $cm, $user, true);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertTrue($this->is_complete($course, $user));
    }

    /**
     * An unsatisfied criterion is left alone.
     *
     * @return void
     */
    public function test_execute_skips_users_with_criteria_not_met(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_activity_criterion($course);
        $this->enrol_user_direct($course, $user);

        (new reconcile_task())->execute();

        $this->assertFalse($this->is_complete($course, $user));
    }

    /**
     * A user whose enrolment has expired is not considered enrolled.
     *
     * @return void
     */
    public function test_execute_ignores_expired_enrolment(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->add_activity_criterion($course);
        $this->enrol_user_direct($course, $user, time() - DAYSECS * 10, time() - DAYSECS * 10, time() - DAYSECS);
        $this->complete_activity($course, $cm, $user, true);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertFalse($this->is_complete($course, $user));
    }

    /**
     * A user whose enrolment has not started yet is not considered enrolled.
     *
     * @return void
     */
    public function test_execute_ignores_future_enrolment(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->add_activity_criterion($course);
        $this->enrol_user_direct($course, $user, time() + DAYSECS, 0);
        $this->complete_activity($course, $cm, $user, true);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertFalse($this->is_complete($course, $user));
    }

    /**
     * A teacher does not hold moodle/course:isincompletionreports and is never booked.
     *
     * @return void
     */
    public function test_execute_ignores_untracked_users(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $cm = $this->add_activity_criterion($course);
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');
        $this->complete_activity($course, $cm, $teacher, true);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertFalse($this->is_complete($course, $teacher));
    }

    /**
     * A run that fills its budget resumes at the next user, not at the first one.
     *
     * The users this task exists for are the ones that do not complete. If progress were
     * measured in bookings, the first budget-worth of them would be re-examined forever
     * and the users behind them would never be reached.
     *
     * @return void
     */
    public function test_execute_advances_past_users_that_never_complete(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');
        set_config('reconcilebudget', 2, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->add_activity_criterion($course);

        $stuck = [];
        for ($i = 0; $i < 3; $i++) {
            $stuck[$i] = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $stuck[$i]);
        }
        $lastuser = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $lastuser);
        $this->complete_activity($course, $cm, $lastuser, true);

        // Two runs of two users each are enough to reach the fourth user.
        (new reconcile_task())->execute();
        $this->assertFalse($this->is_complete($course, $lastuser));

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertTrue($this->is_complete($course, $lastuser));
        $this->assertFalse($this->is_complete($course, $stuck[0]));
    }

    /**
     * A course that fills the budget does not block the courses behind it.
     *
     * @return void
     */
    public function test_execute_reaches_the_next_course(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');
        set_config('reconcilebudget', 2, 'local_instantcoursecompletion');

        $first = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_activity_criterion($first);
        for ($i = 0; $i < 2; $i++) {
            $this->enrol_user_direct($first, $this->getDataGenerator()->create_user());
        }

        $second = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->add_activity_criterion($second);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($second, $user);
        $this->complete_activity($second, $cm, $user, true);

        // The first run exhausts its budget inside the first course.
        (new reconcile_task())->execute();
        $this->assertFalse($this->is_complete($second, $user));

        // The second run finds the first course empty behind the cursor and moves on.
        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertTrue($this->is_complete($second, $user));
    }

    /**
     * A completed pass resets the cursor so the next run starts from the first course.
     *
     * @return void
     */
    public function test_execute_resets_cursor_after_full_pass(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        (new reconcile_task())->execute();

        $cursor = json_decode(get_config('local_instantcoursecompletion', 'reconcilecursor'), true);
        $this->assertSame(0, (int)$cursor['courseid']);
        $this->assertSame(0, (int)$cursor['lastuserid']);
    }

    /**
     * The reconcile path loads a course and its criteria once, not once per user.
     *
     * process_course() opens one completion_booker per course and books every user
     * against it. Booking each user through the completion_booker::book() facade instead
     * would reload the course record and the criteria set for each of them. This test
     * pins that difference: booking a cohort through one per-course booker must read
     * strictly less than booking the same cohort one facade call at a time. If a later
     * change reintroduces a per-user course load, the two read counts converge and this
     * fails.
     *
     * @return void
     */
    public function test_reconcile_amortises_course_load_across_users(): void {
        global $DB;

        $count = 25;

        // The path process_course() takes: one booker for the whole cohort.
        $batched = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $batchedcm = $this->add_activity_criterion($batched);
        $batchedusers = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($batched, $user);
            $this->complete_activity($batched, $batchedcm, $user, true);
            $batchedusers[] = $user;
        }

        // An identical course booked the pre-F2 way: one facade call per user.
        $peruser = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $perusercm = $this->add_activity_criterion($peruser);
        $peruserusers = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($peruser, $user);
            $this->complete_activity($peruser, $perusercm, $user, true);
            $peruserusers[] = $user;
        }

        $before = $DB->perf_get_reads();
        $booker = completion_booker::for_course((int)$batched->id);
        foreach ($batchedusers as $user) {
            $booker->book_user((int)$user->id);
        }
        $this->resetDebugging();
        $batchedreads = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        foreach ($peruserusers as $user) {
            completion_booker::book((int)$peruser->id, (int)$user->id);
        }
        $this->resetDebugging();
        $peruserreads = $DB->perf_get_reads() - $before;

        $this->assertLessThan($peruserreads, $batchedreads);

        // The cheaper path must also actually book everyone, or it is cheap for the wrong reason.
        foreach ($batchedusers as $user) {
            $this->assertTrue($this->is_complete($batched, $user));
        }
    }
}
