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
 * Enrolment records are written directly so that user_enrolment_created stays silent;
 * other installed plugins observe that event and misbehave under PHPUnit.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\reconcile_task;

/**
 * Reconcile task tests.
 *
 * @covers \local_instantcoursecompletion\task\reconcile_task
 */
final class reconcile_task_test extends \advanced_testcase {
    /**
     * Load completionlib and reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest(true);
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
    }

    /**
     * Insert an active manual enrolment without firing user_enrolment_created.
     *
     * @param \stdClass $course    Course record.
     * @param \stdClass $user      User record.
     * @param int       $timestart Enrolment start, 0 for none.
     * @param int       $timeend   Enrolment end, 0 for none.
     * @return void
     */
    protected function enrol_user_direct(\stdClass $course, \stdClass $user, int $timestart = 0, int $timeend = 0): void {
        global $DB;

        $enrolrec = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        if ($enrolrec) {
            $enrolid = (int)$enrolrec->id;
        } else {
            $enrolid = $DB->insert_record('enrol', (object)[
                'enrol' => 'manual',
                'courseid' => (int)$course->id,
                'status' => 0,
                'sortorder' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $DB->insert_record('user_enrolments', (object)[
            'enrolid' => $enrolid,
            'userid' => (int)$user->id,
            'status' => 0,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'modifierid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a course with a satisfied activity criterion for the given user.
     *
     * @param \stdClass $user     User record.
     * @param bool      $complete Whether to mark the activity complete.
     * @return \stdClass The course record.
     */
    protected function course_with_activity_criterion(\stdClass $user, bool $complete): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page',
            'moduleinstance' => (int)$cm->id,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        if ($complete) {
            (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);
        }

        return $course;
    }

    /**
     * The task does nothing while the feature is switched off.
     *
     * @return void
     */
    public function test_execute_exits_early_when_disabled(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->course_with_activity_criterion($user, true);
        $this->enrol_user_direct($course, $user);

        (new reconcile_task())->execute();

        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * A satisfied criterion of an actively enrolled user is booked.
     *
     * @return void
     */
    public function test_execute_books_pending_completions(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->course_with_activity_criterion($user, true);
        $this->enrol_user_direct($course, $user);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * An unsatisfied criterion is left alone.
     *
     * @return void
     */
    public function test_execute_skips_users_with_criteria_not_met(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->course_with_activity_criterion($user, false);
        $this->enrol_user_direct($course, $user);

        (new reconcile_task())->execute();

        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * A user whose enrolment has expired is not considered enrolled.
     *
     * @return void
     */
    public function test_execute_ignores_expired_enrolment(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->course_with_activity_criterion($user, true);
        $this->enrol_user_direct($course, $user, time() - DAYSECS * 10, time() - DAYSECS);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * A user whose enrolment has not started yet is not considered enrolled.
     *
     * @return void
     */
    public function test_execute_ignores_future_enrolment(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->course_with_activity_criterion($user, true);
        $this->enrol_user_direct($course, $user, time() + DAYSECS, 0);

        (new reconcile_task())->execute();
        $this->resetDebugging();

        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * A completed pass resets the cursor so the next run starts from the first course.
     *
     * @return void
     */
    public function test_execute_resets_cursor_after_full_pass(): void {
        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');
        set_config('reconcilecursor', 12345, 'local_instantcoursecompletion');

        (new reconcile_task())->execute();

        $this->assertSame('0', get_config('local_instantcoursecompletion', 'reconcilecursor'));
    }
}
