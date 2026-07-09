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
 * Tests for the completion booker.
 *
 * Guard tests (no DB criterion setup needed) use minimal fixtures.
 * Phase 2 integration tests insert criterion and criterion-completion
 * records directly via $DB to avoid triggering enrolment events that would
 * activate other installed plugins' observers (e.g. local_adele) under PHPUnit.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Completion booker tests.
 *
 * @covers \local_instantcoursecompletion\completion_booker
 */
final class completion_booker_test extends \advanced_testcase {
    /**
     * Invalid arguments and the site course are rejected before any DB work.
     *
     * @return void
     */
    public function test_book_rejects_invalid_input(): void {
        $this->resetAfterTest(true);

        $this->assertFalse(completion_booker::book(0, 1));
        $this->assertFalse(completion_booker::book(1, 0));
        $this->assertFalse(completion_booker::book((int)SITEID, 1));
    }

    /**
     * A course without completion enabled is skipped (guard 1).
     *
     * @return void
     */
    public function test_book_returns_false_when_completion_disabled(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A completion-enabled course with no criteria is a no-op (guard 3).
     *
     * @return void
     */
    public function test_book_returns_false_without_criteria(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * When the course is already complete, book() is idempotent and returns true (guard 2).
     *
     * Inserts a course_completions record directly to avoid triggering enrolment
     * events or mark_complete() (which fires course_completed and could invoke
     * other plugins' observers in the test environment).
     *
     * @return void
     */
    public function test_book_returns_true_when_already_complete(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Pre-mark the course complete directly in the DB (bypasses event cascade).
        $DB->insert_record('course_completions', (object)[
            'userid'        => (int)$user->id,
            'course'        => (int)$course->id,
            'timeenrolled'  => time() - 200,
            'timestarted'   => time() - 100,
            'timecompleted' => time() - 10,
            'reaggregate'   => 0,
        ]);

        $this->assertTrue(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * When all criteria are satisfied, book() marks the course complete and returns true.
     *
     * Uses a self-completion criterion (COMPLETION_CRITERIA_TYPE_SELF) pre-marked as
     * complete via direct DB insertion. No enrolment is performed so that
     * user_enrolment_created does not fire and cannot trigger other plugins' observers
     * (e.g. local_adele) under PHPUnit.
     *
     * @return void
     */
    public function test_book_returns_true_when_all_criteria_satisfied(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Insert a self-completion criterion for the course.
        // Aggregation methods not inserted here — the default is COMPLETION_AGGREGATION_ALL.
        $criteriaid = $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_SELF,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        // Pre-mark the criterion as complete for the user (simulates self-completion).
        // Since is_complete() returns true, review() is skipped; the aggregation step
        // finds the criterion satisfied and calls mark_complete() on the course.
        $DB->insert_record('course_completion_criteria_completion', (object)[
            'criteriaid'    => $criteriaid,
            'userid'        => (int)$user->id,
            'timecompleted' => time(),
            'reaggregate'   => 0,
        ]);

        $result = completion_booker::book((int)$course->id, (int)$user->id);

        $this->assertTrue($result);

        // Verify the course is now actually marked complete (fresh info object).
        $freshinfo = new \completion_info($course);
        $this->assertTrue($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When a criterion is configured but not yet satisfied, book() returns false.
     *
     * @return void
     */
    public function test_book_returns_false_when_criteria_not_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Insert a self-completion criterion but do NOT insert a criterion completion
        // record — the criterion is therefore not satisfied for this user.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_SELF,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        $result = completion_booker::book((int)$course->id, (int)$user->id);

        $this->assertFalse($result);

        // Confirm the course is not marked complete.
        $freshinfo = new \completion_info($course);
        $this->assertFalse($freshinfo->is_course_complete((int)$user->id));
    }
}
