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
 * Guard tests use minimal fixtures (no DB criterion setup needed).
 *
 * Integration tests for the Phase-2 aggregation path use an ACTIVITY criterion
 * combined with completion_info::update_state() to satisfy the criterion. This
 * writes only to course_modules_completion (always present in Moodle's schema)
 * and to course_completions when book() succeeds — avoiding any dependency on
 * the internal criterion-level completion table.
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
     * @return void
     */
    public function test_book_returns_true_when_already_complete(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Pre-mark the course complete via direct insert into course_completions
        // (a core table guaranteed to exist in every Moodle installation).
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
     * When an activity criterion is satisfied, book() marks the course complete.
     *
     * Uses COMPLETION_CRITERIA_TYPE_ACTIVITY and completion_info::update_state() so
     * that only course_modules_completion (always present) is written during setup,
     * without coupling to any internal criterion-completion table.
     *
     * @return void
     */
    public function test_book_returns_true_when_all_criteria_satisfied(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Create a page module with manual completion tracking.
        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        // Insert the activity completion criterion definition.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module'            => 'page',
            'moduleinstance'    => (int)$cm->id,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        // Mark the page as complete for the user via the public completion API.
        // review() reads course_modules_completion; no separate criterion-completion
        // table is accessed or created during this call.
        $info = new \completion_info($course);
        $info->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);

        $result = completion_booker::book((int)$course->id, (int)$user->id);

        // Consume any debugging() calls from external plugin observers on course_completed.
        // local_adele's observer calls require_phpunit_isolation() which generates a
        // debugging() call under PHPUnit. resetDebugging() clears the buffer; this keeps the assertion
        // independent of which other plugins are installed.
        $this->resetDebugging();

        $this->assertTrue($result);

        $freshinfo = new \completion_info($course);
        $this->assertTrue($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When a grade criterion is configured and the user has a passing course grade, book() succeeds.
     *
     * Uses COMPLETION_CRITERIA_TYPE_GRADE. completion_criteria_grade::review() reads the
     * finalgrade of the COURSE-TOTAL grade item, which Moodle computes by aggregating all
     * grade sub-items in the course. Writing directly into the course-total's own grade_grade
     * row is unreliable: the next regrade (which can be triggered implicitly on fetch) will
     * recompute it as NULL because there are no real sub-items to aggregate from.
     *
     * The robust approach is to create one real (manual) grade item, set its grade via the
     * public grade_item::update_final_grade() API, and then explicitly call
     * grade_regrade_final_grades() so the course-total item aggregates a genuine sub-item.
     *
     * @return void
     */
    public function test_book_returns_true_when_grade_criterion_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Grade completion criterion: passing grade 50 (out of the course total's 0-100 range).
        $DB->insert_record('course_completion_criteria', (object)[
            'course'       => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE,
            'gradepass'    => 50.0,
        ]);

        // One real manual grade item feeding the course total, set via the public grade API.
        $gradeitem = new \grade_item([
            'courseid'  => (int)$course->id,
            'itemtype'  => 'manual',
            'itemname'  => 'Test manual grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => 100,
            'grademin'  => 0,
        ]);
        $gradeitem->insert();
        $gradeitem->update_final_grade((int)$user->id, 75.0);
        grade_regrade_final_grades((int)$course->id);

        $result = completion_booker::book((int)$course->id, (int)$user->id);

        // Consume debugging() calls from external plugin observers on course_completed.
        $this->resetDebugging();

        $this->assertTrue($result);
        $freshinfo = new \completion_info($course);
        $this->assertTrue($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When a grade criterion exists but the user's course grade is below passing, book() returns false.
     *
     * @return void
     */
    public function test_book_returns_false_when_grade_criterion_not_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course'       => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE,
            'gradepass'    => 50.0,
        ]);

        // Same real-item approach as the met test, with a below-threshold grade.
        $gradeitem = new \grade_item([
            'courseid'  => (int)$course->id,
            'itemtype'  => 'manual',
            'itemname'  => 'Test manual grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => 100,
            'grademin'  => 0,
        ]);
        $gradeitem->insert();
        $gradeitem->update_final_grade((int)$user->id, 30.0);
        grade_regrade_final_grades((int)$course->id);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));

        $freshinfo = new \completion_info($course);
        $this->assertFalse($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When a date criterion is configured with a date in the past, book() succeeds.
     *
     * completion_criteria_date::review() returns true iff $this->date <= time(). Setting
     * the date to 2020-01-01 guarantees the criterion is met without clock dependency.
     *
     * @return void
     */
    public function test_book_returns_true_when_date_criterion_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Date in the past — criterion is satisfied immediately.
        // completion_criteria_date::review() checks $this->timeend (the field written by the
        // course completion form and read back by the criterion class); NOT $this->date.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'       => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_DATE,
            'timeend'      => mktime(0, 0, 0, 1, 1, 2020),
        ]);

        $result = completion_booker::book((int)$course->id, (int)$user->id);

        // Consume debugging() calls from external plugin observers on course_completed.
        $this->resetDebugging();

        $this->assertTrue($result);
        $freshinfo = new \completion_info($course);
        $this->assertTrue($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When a date criterion is configured with a date in the future, book() returns false.
     *
     * @return void
     */
    public function test_book_returns_false_when_date_criterion_not_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        // Date far in the future — criterion is not yet satisfied (timeend field, same as above).
        $DB->insert_record('course_completion_criteria', (object)[
            'course'       => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_DATE,
            'timeend'      => mktime(0, 0, 0, 1, 1, 2099),
        ]);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));

        $freshinfo = new \completion_info($course);
        $this->assertFalse($freshinfo->is_course_complete((int)$user->id));
    }

    /**
     * When an activity criterion exists but the activity is not complete, book() returns false.
     *
     * @return void
     */
    public function test_book_returns_false_when_criteria_not_met(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/completionlib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $page = $this->getDataGenerator()->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        // Criterion definition only — activity is NOT marked complete.
        $DB->insert_record('course_completion_criteria', (object)[
            'course'            => (int)$course->id,
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module'            => 'page',
            'moduleinstance'    => (int)$cm->id,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        $result = completion_booker::book((int)$course->id, (int)$user->id);

        $this->assertFalse($result);

        $freshinfo = new \completion_info($course);
        $this->assertFalse($freshinfo->is_course_complete((int)$user->id));
    }
}
