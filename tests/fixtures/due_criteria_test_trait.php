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
 * Shared fixtures for the tests around time-based completion criteria.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\book_due_completion_task;

/**
 * Course, criterion and enrolment fixtures for due-time tests.
 */
trait due_criteria_test_trait {
    /**
     * Ad-hoc due-booking tasks currently queued.
     *
     * @return \core\task\adhoc_task[]
     */
    protected function queued_tasks(): array {
        return \core\task\manager::get_adhoc_tasks(book_due_completion_task::class);
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
     * Insert an active manual enrolment without firing user_enrolment_created.
     *
     * Other installed plugins observe that event and misbehave under PHPUnit.
     *
     * @param \stdClass $course      Course record.
     * @param \stdClass $user        User record.
     * @param int       $timestart   Enrolment start, 0 for none.
     * @param int       $timecreated Enrolment creation time, 0 for now.
     * @param int       $timeend     Enrolment end, 0 for none.
     * @return void
     */
    protected function enrol_user_direct(
        \stdClass $course,
        \stdClass $user,
        int $timestart = 0,
        int $timecreated = 0,
        int $timeend = 0
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
}
