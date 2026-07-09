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
 * Enrolment records are inserted directly into {enrol} and {user_enrolments}
 * via $DB to avoid triggering user_enrolment_created, which activates other
 * installed plugins' observers (e.g. local_adele) under PHPUnit.
 *
 * Criteria completions are satisfied via completion_info::update_state() rather
 * than direct inserts into any internal criterion-completion table, which may not
 * exist in all Moodle installations.
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
     * Insert a minimal active enrolment record directly into the DB.
     *
     * Uses the manual enrolment plugin row for the course if it already exists;
     * otherwise creates one. Does NOT fire user_enrolment_created.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user   User record.
     * @return void
     */
    protected function enrol_user_direct(\stdClass $course, \stdClass $user): void {
        global $DB;

        $enrolrec = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        if ($enrolrec) {
            $enrolid = (int)$enrolrec->id;
        } else {
            $enrolid = $DB->insert_record('enrol', (object)[
                'enrol'        => 'manual',
                'courseid'     => (int)$course->id,
                'status'       => 0,
                'sortorder'    => 0,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        $DB->insert_record('user_enrolments', (object)[
            'enrolid'      => $enrolid,
            'userid'       => (int)$user->id,
            'status'       => 0,
            'timestart'    => 0,
            'timeend'      => 0,
            'modifierid'   => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * When reconcile_enabled is off (default), execute() is a no-op.
     *
     * @return void
     */
    public function test_execute_exits_early_when_disabled(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        // Feature off by default — reconcile_enabled is not set here.
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Insert a criterion definition to give the task something to do if it
        // were enabled — reconcile_enabled is intentionally absent here.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_SELF,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        (new reconcile_task())->execute();

        // Task was disabled — course must remain incomplete.
        $freshinfo = new \completion_info($course);
        $this->assertFalse($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When enabled and the activity criterion is satisfied, execute() books the completion.
     *
     * @return void
     */
    public function test_execute_books_pending_completions(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Manual-completion page used as the activity criterion target.
        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        $this->enrol_user_direct($course, $user);

        // Activity criterion definition — writes only to course_completion_criteria.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module'            => 'page',
            'moduleinstance'    => (int)$cm->id,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        // Mark the activity complete via the public API (writes to course_modules_completion).
        $info = new \completion_info($course);
        $info->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);

        (new reconcile_task())->execute();

        // Consume debugging() calls from local_adele's course_completed observer.
        $this->resetDebugging();

        $freshinfo = new \completion_info($course);
        $this->assertTrue($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When enabled but the activity criterion is not yet satisfied, execute() is a no-op.
     *
     * @return void
     */
    public function test_execute_skips_users_with_criteria_not_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        set_config('reconcile_enabled', 1, 'local_instantcoursecompletion');
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        $this->enrol_user_direct($course, $user);

        // Criterion exists but the activity is NOT marked complete.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module'            => 'page',
            'moduleinstance'    => (int)$cm->id,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        (new reconcile_task())->execute();

        $freshinfo = new \completion_info($course);
        $this->assertFalse($freshinfo->is_course_complete((int)$user->id));
    }
}
