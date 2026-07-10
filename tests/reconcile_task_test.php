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
     * The per-user cost of the reconcile booking path stays a small bounded constant.
     *
     * process_course() opens one completion_booker per course and books every user
     * against it. The interesting quantity is the *marginal* read cost of one more user:
     * running two identical courses of different cohort sizes cancels every fixed
     * per-run and per-course cost (the course record, the criteria set, the enrolment
     * query, config) and leaves only the per-user completion pipeline. That marginal
     * must stay a small constant. A regression that rescans the cohort, or issues an
     * uncached query per criterion per user, would push it up sharply and fail here.
     *
     * Note: this does not compare against a per-user completion_booker::book() facade
     * loop. Empirically the two read almost identically, because Moodle already serves
     * the course record and the criteria set from request-level caches — the win of the
     * per-course booker is fewer completion_info constructions and criteria rebuilds, not
     * fewer database reads. A read-count inequality between the two paths is therefore
     * not a sound assertion; a bounded per-user marginal is.
     *
     * @return void
     */
    public function test_reconcile_per_user_reads_stay_bounded(): void {
        $readssmall = $this->reconcile_reads_for_cohort(10);
        $readslarge = $this->reconcile_reads_for_cohort(30);

        $marginalperuser = ($readslarge - $readssmall) / 20;

        $this->assertGreaterThan(0, $marginalperuser);
        $this->assertLessThan(80, $marginalperuser);
    }

    /**
     * Reads consumed by process_course() booking a fresh cohort of the given size.
     *
     * Every user has a satisfied activity criterion, so each one exercises the full
     * booking pipeline. The whole cohort is booked in one call, which is exactly what a
     * reconcile run does for one course.
     *
     * @param int $count Number of tracked, completable users to enrol.
     * @return int Database reads consumed by the booking pass.
     */
    private function reconcile_reads_for_cohort(int $count): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->add_activity_criterion($course);
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->enrol_user_direct($course, $user);
            $this->complete_activity($course, $cm, $user, true);
            $users[] = $user;
        }

        $method = new \ReflectionMethod(reconcile_task::class, 'process_course');
        $task = new reconcile_task();

        $before = $DB->perf_get_reads();
        $result = $method->invoke($task, (int)$course->id, 0, $count + 10);
        $this->resetDebugging();
        $reads = $DB->perf_get_reads() - $before;

        // Cheap for the wrong reason is not cheap: the pass must have booked everyone.
        $this->assertSame($count, $result['booked']);
        foreach ($users as $user) {
            $this->assertTrue($this->is_complete($course, $user));
        }

        return $reads;
    }
}
