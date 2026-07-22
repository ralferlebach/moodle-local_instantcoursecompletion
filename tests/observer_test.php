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
 * Tests for the event observers.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_completion_task;
use local_instantcoursecompletion\task\book_due_completion_batch_task;
use local_instantcoursecompletion\task\notify_dependent_courses_task;

/**
 * Observer tests.
 *
 * @covers \local_instantcoursecompletion\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Load completionlib and reset state.
     *
     * The plugin registers its observers with internal = false, so core defers them
     * until the surrounding database transaction commits. On PostgreSQL and MSSQL,
     * advanced_testcase wraps every test in a transaction it rolls back afterwards,
     * which would discard the deferred observers and let event-driven assertions pass
     * or fail for the wrong reason.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        criteria_index::purge();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');

        // These tests assert on the ad-hoc queue, which only the asynchronous mode fills.
        // The synchronous default (booking in the request) is covered by its own tests,
        // which set the mode explicitly.
        set_config('processingmode', observer::MODE_ASYNC, 'local_instantcoursecompletion');
    }

    /**
     * Ad-hoc booking tasks currently queued.
     *
     * @return \core\task\adhoc_task[]
     */
    protected function queued_tasks(): array {
        return \core\task\manager::get_adhoc_tasks(book_completion_task::class);
    }

    /**
     * Dependent-course notification tasks currently queued.
     *
     * @return \core\task\adhoc_task[]
     */
    protected function queued_notifications(): array {
        return \core\task\manager::get_adhoc_tasks(notify_dependent_courses_task::class);
    }

    /**
     * Insert a criterion of the given type into a course.
     *
     * @param int   $courseid Course ID.
     * @param int   $type     A COMPLETION_CRITERIA_TYPE_* constant.
     * @param array $extra    Additional record fields.
     * @return int The new criterion ID.
     */
    protected function add_criterion(int $courseid, int $type, array $extra = []): int {
        global $DB;

        $id = (int)$DB->insert_record('course_completion_criteria', (object)array_merge([
            'course' => $courseid,
            'criteriatype' => $type,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ], $extra));
        criteria_index::purge($courseid);
        return $id;
    }

    /**
     * An in-scope trigger queues exactly one deduplicated task.
     *
     * @return void
     */
    public function test_handle_completion_trigger_queues_one_task(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);
        observer::handle_completion_trigger((int)$course->id, (int)$user->id);

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);

        $task = reset($tasks);
        $data = $task->get_custom_data();
        $this->assertSame((int)$course->id, (int)$data->courseid);
        $this->assertSame((int)$user->id, (int)$data->userid);

        // The booking runs as a system task, not as the learner: a suspended learner must
        // not cause core to discard it.
        $this->assertNull($task->get_userid());
    }

    /**
     * A later trigger re-queues once the earlier task has left the queue.
     *
     * This is the process-lifetime regression: cron runs many ad-hoc tasks in one PHP
     * process, so a trigger must not be suppressed by state left behind from an earlier
     * task in the same process. Deliberately no reflection reset — that would hide the very
     * problem. The pattern is: prerequisite A completes and books course C (still
     * incomplete), then prerequisite B completes and must re-trigger C.
     *
     * @return void
     */
    public function test_trigger_requeues_after_the_earlier_task_left_the_queue(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // First trigger queues a booking.
        observer::handle_completion_trigger((int)$course->id, (int)$user->id);
        $this->assertCount(1, $this->queued_tasks());

        // The booking runs and leaves the queue.
        \core\task\manager::reset_state();
        $ran = \core\task\manager::get_next_adhoc_task(time() + 1, false, book_completion_task::class);
        $this->assertNotNull($ran);
        \core\task\manager::adhoc_task_complete($ran);
        $this->resetDebugging();

        $this->assertCount(0, $this->queued_tasks());

        // A later trigger for the same pair, still in this process, must queue again.
        observer::handle_completion_trigger((int)$course->id, (int)$user->id);
        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * The site course and invalid IDs never reach the queue.
     *
     * @return void
     */
    public function test_handle_completion_trigger_rejects_invalid_input(): void {
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)SITEID, (int)$user->id);
        observer::handle_completion_trigger(0, (int)$user->id);
        observer::handle_completion_trigger(1, 0);

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * An out-of-scope course never reaches the queue.
     *
     * @return void
     */
    public function test_handle_completion_trigger_respects_scope(): void {
        $category = $this->getDataGenerator()->create_category();
        $inscope = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $outofscope = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', (string)$category->id, 'local_instantcoursecompletion');
        scope_resolver::purge_cache();

        observer::handle_completion_trigger((int)$outofscope->id, (int)$user->id);
        $this->assertCount(0, $this->queued_tasks());

        observer::handle_completion_trigger((int)$inscope->id, (int)$user->id);
        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * Activity completion in a course with only activity criteria queues nothing:
     * core has already aggregated it before the event fired.
     *
     * @return void
     */
    public function test_activity_completion_skipped_when_only_activity_criteria(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, [
            'module' => 'page',
            'moduleinstance' => (int)$cm->id,
        ]);
        criteria_index::purge();

        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);
        $this->resetDebugging();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * Activity completion in a course that also has other criteria queues a task.
     *
     * @return void
     */
    public function test_activity_completion_queues_when_other_criteria_present(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, [
            'module' => 'page',
            'moduleinstance' => (int)$cm->id,
        ]);
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE, [
            'timeend' => time() + DAYSECS,
        ]);
        criteria_index::purge();

        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);
        $this->resetDebugging();

        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * Every callback registered in db/events.php exists on the observer.
     *
     * A registered callback that no longer exists throws on the live site the moment its
     * event fires, and nothing in a functional test suite necessarily fires all of them.
     * This is the cheap check that would have caught the enrolment observers going
     * missing while db/events.php still announced them.
     *
     * @return void
     */
    public function test_every_registered_callback_exists(): void {
        global $CFG;

        $observers = [];
        require($CFG->dirroot . '/local/instantcoursecompletion/db/events.php');
        $this->assertNotEmpty($observers);

        foreach ($observers as $definition) {
            [$class, $method] = explode('::', ltrim($definition['callback'], '\\'));
            $this->assertTrue(
                method_exists($class, $method),
                "Registered callback {$definition['callback']} does not exist"
            );
        }
    }

    /**
     * A new enrolment plans the time-based criteria of that user at once.
     *
     * @return void
     */
    public function test_user_enrolment_created_plans_due_criteria(): void {
        global $DB;

        set_config('schedulingenabled', 1, 'local_instantcoursecompletion');
        set_config('schedulinghorizon', WEEKSECS, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_DATE, [
            'timeend' => time() + DAYSECS,
        ]);
        criteria_index::purge();

        $enrolid = $this->enrol_directly($course, $user);
        $ueid = (int)$DB->get_field('user_enrolments', 'id', [
            'enrolid' => $enrolid,
            'userid' => (int)$user->id,
        ]);

        $event = \core\event\user_enrolment_created::create([
            'objectid' => $ueid,
            'courseid' => (int)$course->id,
            'context' => \context_course::instance((int)$course->id),
            'relateduserid' => (int)$user->id,
            'other' => ['enrol' => 'manual'],
        ]);
        observer::user_enrolment_created($event);
        $this->resetDebugging();

        $batches = \core\task\manager::get_adhoc_tasks(book_due_completion_batch_task::class);
        $this->assertCount(1, $batches);
        $this->assertSame((int)$course->id, (int)reset($batches)->get_custom_data()->courseid);
    }

    /**
     * Enrol a user and give them the student role, without firing the enrolment event.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @return int The enrol instance ID.
     */
    protected function enrol_directly(\stdClass $course, \stdClass $user): int {
        global $DB;

        $enrolrec = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $enrolid = $enrolrec ? (int)$enrolrec->id : $DB->insert_record('enrol', (object)[
            'enrol' => 'manual',
            'courseid' => (int)$course->id,
            'status' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('user_enrolments', (object)[
            'enrolid' => $enrolid,
            'userid' => (int)$user->id,
            'status' => 0,
            'timestart' => 0,
            'timeend' => 0,
            'modifierid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_assign($roleid, (int)$user->id, \context_course::instance((int)$course->id)->id);
        $this->resetDebugging();

        return $enrolid;
    }

    /**
     * Completing a course queues the bounded dependent-course fan-out.
     *
     * The observer no longer walks the dependents itself: a hub course required by many
     * programmes would make that an unbounded fan-out inside one request.
     *
     * @return void
     */
    public function test_course_completed_queues_the_notification_task(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $completion = new \completion_completion([
            'course' => (int)$prerequisite->id,
            'userid' => (int)$user->id,
        ]);
        $completion->mark_complete();
        $this->resetDebugging();

        // Nothing is booked from the observer itself.
        $this->assertCount(0, $this->queued_tasks());

        $notifications = $this->queued_notifications();
        $this->assertCount(1, $notifications);

        $task = reset($notifications);
        $data = $task->get_custom_data();
        $this->assertSame((int)$prerequisite->id, (int)$data->courseid);
        $this->assertSame((int)$user->id, (int)$data->userid);
        $this->assertSame(0, (int)$data->fromcourseid);

        // The fan-out is a system task; no learner is attached as its owner.
        $this->assertNull($task->get_userid());
    }

    /**
     * The notification task books the courses that require the completed one.
     *
     * @return void
     */
    public function test_notification_task_triggers_dependent_courses(): void {
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $dependent = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $this->add_criterion((int)$dependent->id, COMPLETION_CRITERIA_TYPE_COURSE, [
            'courseinstance' => (int)$prerequisite->id,
        ]);
        criteria_index::purge();

        $completion = new \completion_completion([
            'course' => (int)$prerequisite->id,
            'userid' => (int)$user->id,
        ]);
        $completion->mark_complete();
        $this->resetDebugging();

        $notifications = $this->queued_notifications();
        $this->assertCount(1, $notifications);

        reset($notifications)->execute();
        $this->resetDebugging();

        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);
        $this->assertSame((int)$dependent->id, (int)reset($tasks)->get_custom_data()->courseid);
    }

    /**
     * A completed course nothing depends on books no follow-up work.
     *
     * @return void
     */
    public function test_notification_task_without_dependents_books_nothing(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $completion = new \completion_completion([
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]);
        $completion->mark_complete();
        $this->resetDebugging();

        foreach ($this->queued_notifications() as $task) {
            $task->execute();
        }
        $this->resetDebugging();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * The default mode is synchronous: a trigger books in the request, queuing nothing.
     *
     * @return void
     */
    public function test_default_processing_mode_is_synchronous(): void {
        unset_config('processingmode', 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * The asynchronous fallback queues the booking instead of doing it in the request.
     *
     * @return void
     */
    public function test_async_mode_queues_the_booking(): void {
        set_config('processingmode', observer::MODE_ASYNC, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        observer::handle_completion_trigger((int)$course->id, (int)$user->id);

        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * A passing grade books the course completion in the triggering request, no cron.
     *
     * This is the reported case: a course whose only criterion is the course grade, a
     * learner who reaches the pass mark, and completion that must be booked -- and its
     * course_completed event dispatched -- without waiting for a cron tick.
     *
     * @return void
     */
    public function test_grade_completion_is_booked_synchronously(): void {
        set_config('processingmode', observer::MODE_SYNC, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_tracked_user($course, $user);
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_GRADE, ['gradepass' => 50.0]);

        $this->set_course_grade($course, $user, 75.0);
        observer::handle_completion_trigger((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));
        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * Viewing the course after a self-completion aggregates it in the request and clears
     * the reaggregation flag.
     *
     * Self-completion writes the criterion and flags the course for reaggregation without
     * an event, then redirects the learner to the course. This course_viewed is that
     * redirect: it must complete the course now and leave no flag behind that would make
     * every later view repeat the work.
     *
     * @return void
     */
    public function test_course_viewed_books_self_completion_and_clears_reaggregate(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_tracked_user($course, $user);
        $criteriaid = $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_SELF);

        $this->mark_self_criterion($course, $user, $criteriaid);
        $this->assertGreaterThan(0, $this->reaggregate_flag($course, $user));

        $this->view_course($course, $user);

        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));
        $this->assertSame(0, $this->reaggregate_flag($course, $user));
    }

    /**
     * A course view with no pending reaggregation books nothing.
     *
     * @return void
     */
    public function test_course_viewed_ignores_view_without_pending_reaggregation(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_tracked_user($course, $user);
        $this->add_criterion((int)$course->id, COMPLETION_CRITERIA_TYPE_SELF);

        $this->view_course($course, $user);

        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * Enrol a user as a tracked student without firing user_enrolment_created.
     *
     * Other installed plugins observe that event with code that misbehaves under
     * PHPUnit, so the enrolment is inserted directly. The student role is what makes the
     * user tracked (moodle/course:isincompletionreports); an untracked user is booked by
     * nothing here.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user   User record.
     * @return void
     */
    protected function enrol_tracked_user(\stdClass $course, \stdClass $user): void {
        global $DB;

        $now = time();
        $enrolrec = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $enrolid = $enrolrec ? (int)$enrolrec->id : $DB->insert_record('enrol', (object)[
            'enrol' => 'manual',
            'courseid' => (int)$course->id,
            'status' => 0,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $DB->insert_record('user_enrolments', (object)[
            'enrolid' => $enrolid,
            'userid' => (int)$user->id,
            'status' => 0,
            'timestart' => 0,
            'timeend' => 0,
            'modifierid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_assign($roleid, (int)$user->id, \context_course::instance((int)$course->id)->id);

        // Assigning the role fires role_assigned, which other plugins observe with debugging.
        $this->resetDebugging();
    }

    /**
     * Give the user a passing final grade through a real manual grade item.
     *
     * completion_criteria_grade::review() reads the course total, recomputed from its
     * sub-items on every regrade; writing that column directly is discarded.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @param float     $grade  The final grade.
     * @return void
     */
    protected function set_course_grade(\stdClass $course, \stdClass $user, float $grade): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = new \grade_item([
            'courseid' => (int)$course->id,
            'itemtype' => 'manual',
            'itemname' => 'Test manual grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ]);
        $gradeitem->insert();
        $gradeitem->update_final_grade((int)$user->id, $grade);
        grade_regrade_final_grades((int)$course->id);

        // Grading fires user_graded, which the observer acts on; a booking under the
        // synchronous default can complete the course and emit debugging.
        $this->resetDebugging();
    }

    /**
     * Record a self criterion the way course/togglecompletion.php does for the block.
     *
     * mark_complete() writes the criterion completion and flags the course completion
     * for reaggregation via mark_inprogress(), but does not aggregate and fires no event.
     *
     * @param \stdClass $course     The course.
     * @param \stdClass $user       The user.
     * @param int       $criteriaid The self criterion.
     * @return void
     */
    protected function mark_self_criterion(\stdClass $course, \stdClass $user, int $criteriaid): void {
        $criterioncompletion = new \completion_criteria_completion([
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
            'criteriaid' => $criteriaid,
        ]);
        $criterioncompletion->mark_complete();
        $this->resetDebugging();
    }

    /**
     * The current reaggregation flag on a user's course completion.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @return int
     */
    protected function reaggregate_flag(\stdClass $course, \stdClass $user): int {
        global $DB;

        return (int)$DB->get_field('course_completions', 'reaggregate', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]);
    }

    /**
     * Fire course_viewed for a user, as the redirect after self-completion does.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @return void
     */
    protected function view_course(\stdClass $course, \stdClass $user): void {
        $this->setUser($user);
        $event = \core\event\course_viewed::create([
            'context' => \context_course::instance((int)$course->id),
        ]);
        observer::course_viewed($event);
        $this->resetDebugging();
    }
}
