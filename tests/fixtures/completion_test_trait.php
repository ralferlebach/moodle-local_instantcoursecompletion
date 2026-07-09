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
 * Shared fixtures for the completion tests.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_due_completion_batch_task;

/**
 * Course, criterion, enrolment and completion fixtures.
 */
trait completion_test_trait {
    /**
     * Ad-hoc due-booking tasks currently queued.
     *
     * @return \core\task\adhoc_task[]
     */
    protected function queued_tasks(): array {
        return \core\task\manager::get_adhoc_tasks(book_due_completion_batch_task::class);
    }

    /**
     * The single queued due-booking task.
     *
     * @return \core\task\adhoc_task
     */
    protected function single_queued_task(): \core\task\adhoc_task {
        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);
        return reset($tasks);
    }

    /**
     * Assert that a task runs at the start of the batch window containing its due time.
     *
     * Rounding up means a task never runs before its criterion is satisfied, and never
     * later than the per-user jitter of earlier versions already allowed.
     *
     * @param \core\task\adhoc_task $task    The queued task.
     * @param int                    $duetime The moment the criterion falls due.
     * @return void
     */
    protected function assert_due_at(\core\task\adhoc_task $task, int $duetime): void {
        $this->assertSame(due_scheduler::due_bucket($duetime), (int)$task->get_next_run_time());
    }

    /**
     * The criterion IDs the queued batch tasks were planned for.
     *
     * @return int[] Sorted ascending, de-duplicated.
     */
    protected function queued_criteria_ids(): array {
        $ids = [];
        foreach ($this->queued_tasks() as $task) {
            $ids[(int)$task->get_custom_data()->criteriaid] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /**
     * Switch on due scheduling with predictable bounds.
     *
     * @return void
     */
    protected function enable_due_scheduling(): void {
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');
        set_config('schedulingenabled', 1, 'local_instantcoursecompletion');
        set_config('schedulinghorizon', WEEKSECS, 'local_instantcoursecompletion');
        criteria_index::purge();
    }

    /**
     * The run times of the queued batch tasks.
     *
     * @return int[] Sorted ascending.
     */
    protected function queued_run_times(): array {
        $times = [];
        foreach ($this->queued_tasks() as $task) {
            $times[] = (int)$task->get_next_run_time();
        }
        sort($times);
        return $times;
    }

    /**
     * The queued batch tasks that resume a page rather than start one.
     *
     * @return \core\task\adhoc_task[]
     */
    protected function queued_continuations(): array {
        return array_values(array_filter(
            $this->queued_tasks(),
            static fn($task) => (int)$task->get_custom_data()->lastuserid > 0
        ));
    }

    /**
     * Run every queued batch task once, in queue order.
     *
     * @return void
     */
    protected function run_queued_tasks(): void {
        foreach ($this->queued_tasks() as $task) {
            $task->execute();
        }
        $this->resetDebugging();
    }

    /**
     * Enrol a user without firing user_enrolment_created, and give them a role.
     *
     * The event is avoided because other installed plugins observe it and misbehave
     * under PHPUnit. The role matters: only users holding
     * moodle/course:isincompletionreports are tracked, and untracked users are ignored
     * everywhere in this plugin.
     *
     * @param \stdClass $course      Course record.
     * @param \stdClass $user        User record.
     * @param int       $timestart   Enrolment start, 0 for none.
     * @param int       $timecreated Enrolment creation time, 0 for now.
     * @param int       $timeend     Enrolment end, 0 for none.
     * @param string    $rolename    Role short name, or '' to assign none.
     * @return void
     */
    protected function enrol_user_direct(
        \stdClass $course,
        \stdClass $user,
        int $timestart = 0,
        int $timecreated = 0,
        int $timeend = 0,
        string $rolename = 'student'
    ): void {
        global $DB;

        $timecreated = $timecreated ?: time();
        $enrolrec = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $enrolid = $enrolrec ? (int)$enrolrec->id : $DB->insert_record('enrol', (object)[
            'enrol' => 'manual',
            'courseid' => (int)$course->id,
            'status' => 0,
            'sortorder' => 0,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ]);

        $DB->insert_record('user_enrolments', (object)[
            'enrolid' => $enrolid,
            'userid' => (int)$user->id,
            'status' => 0,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'modifierid' => 0,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ]);

        if ($rolename !== '') {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $rolename], MUST_EXIST);
            role_assign($roleid, (int)$user->id, \context_course::instance((int)$course->id)->id);

            // Assigning a role fires role_assigned, and other installed plugins observe
            // it with code that requires PHPUnit process isolation. Their debugging()
            // call would fail whichever test happens to enrol last.
            $this->resetDebugging();
        }
    }

    /**
     * Add an activity completion criterion backed by a manual-completion page.
     *
     * @param \stdClass $course The course.
     * @return \stdClass The course module record.
     */
    protected function add_activity_criterion(\stdClass $course): \stdClass {
        global $DB;

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
        criteria_index::purge();

        return $cm;
    }

    /**
     * Mark an activity complete for a user.
     *
     * With $isbulkupdate, completion_info::internal_set_data() returns before it marks
     * the activity criteria and aggregates the course, and before it fires
     * course_module_completion_updated. That is the case this plugin exists for, and it
     * is what keeps a test about this plugin from measuring core instead.
     *
     * @param \stdClass $course       The course.
     * @param \stdClass $cm           The course module.
     * @param \stdClass $user         The user.
     * @param bool      $isbulkupdate Whether to suppress the inline core aggregation.
     * @return void
     */
    protected function complete_activity(
        \stdClass $course,
        \stdClass $cm,
        \stdClass $user,
        bool $isbulkupdate = false
    ): void {
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, (int)$user->id, false, $isbulkupdate);
        $this->resetDebugging();
    }

    /**
     * Add a date criterion to a course.
     *
     * @param \stdClass $course  The course.
     * @param int       $timeend When the criterion falls due.
     * @return int The criterion ID.
     */
    protected function add_date_criterion(\stdClass $course, int $timeend): int {
        global $DB;

        $id = (int)$DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_DATE,
            'timeend' => $timeend,
        ]);
        criteria_index::purge();
        return $id;
    }

    /**
     * Add a duration criterion to a course.
     *
     * @param \stdClass $course      The course.
     * @param int       $enrolperiod Seconds since enrolment required.
     * @return int The criterion ID.
     */
    protected function add_duration_criterion(\stdClass $course, int $enrolperiod): int {
        global $DB;

        $id = (int)$DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_DURATION,
            'enrolperiod' => $enrolperiod,
        ]);
        criteria_index::purge();
        return $id;
    }

    /**
     * Narrow the observer scope to a fresh category that holds none of the test courses.
     *
     * @return void
     */
    protected function restrict_scope_to_new_category(): void {
        $category = $this->getDataGenerator()->create_category();

        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', (string)$category->id, 'local_instantcoursecompletion');
        scope_resolver::purge_cache();
    }

    /**
     * Record a criterion completion the way core does when the criterion is satisfied.
     *
     * @param \stdClass $course     The course.
     * @param \stdClass $user       The user.
     * @param int       $criteriaid The criterion.
     * @return void
     */
    protected function mark_criterion_completed(\stdClass $course, \stdClass $user, int $criteriaid): void {
        $criterioncompletion = new \completion_criteria_completion([
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
            'criteriaid' => $criteriaid,
        ]);
        $criterioncompletion->mark_complete();

        // Marking a criterion can complete the course, whose observer emits debugging.
        $this->resetDebugging();
    }

    /**
     * Record a finished course completion for a user, bypassing the completion API.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @return void
     */
    protected function mark_course_completed(\stdClass $course, \stdClass $user): void {
        global $DB;

        $DB->insert_record('course_completions', (object)[
            'userid' => (int)$user->id,
            'course' => (int)$course->id,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => time() - 10,
            'reaggregate' => 0,
        ]);
    }
}
