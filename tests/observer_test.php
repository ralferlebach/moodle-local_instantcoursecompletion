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
        observer::reset_seen();
        criteria_index::purge();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
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
     * @return void
     */
    protected function add_criterion(int $courseid, int $type, array $extra = []): void {
        global $DB;

        $DB->insert_record('course_completion_criteria', (object)array_merge([
            'course' => $courseid,
            'criteriatype' => $type,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ], $extra));
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

        $data = reset($tasks)->get_custom_data();
        $this->assertSame((int)$course->id, (int)$data->courseid);
        $this->assertSame((int)$user->id, (int)$data->userid);
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

        $data = reset($notifications)->get_custom_data();
        $this->assertSame((int)$prerequisite->id, (int)$data->courseid);
        $this->assertSame((int)$user->id, (int)$data->userid);
        $this->assertSame(0, (int)$data->fromcourseid);
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

        observer::reset_seen();
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

        observer::reset_seen();
        foreach ($this->queued_notifications() as $task) {
            $task->execute();
        }
        $this->resetDebugging();

        $this->assertCount(0, $this->queued_tasks());
    }
}
