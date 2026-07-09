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
     * Load completionlib and reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest(true);
    }

    /**
     * Create a completion-enabled course and a user.
     *
     * @return array Two elements: course record, user record.
     */
    protected function course_and_user(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        return [$course, $user];
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

        return $cm;
    }

    /**
     * Invalid arguments and the site course are rejected before any DB work.
     *
     * @return void
     */
    public function test_book_rejects_invalid_input(): void {
        $this->assertFalse(completion_booker::book(0, 1));
        $this->assertFalse(completion_booker::book(1, 0));
        $this->assertFalse(completion_booker::book((int)SITEID, 1));
    }

    /**
     * A course without completion enabled is skipped.
     *
     * @return void
     */
    public function test_book_returns_false_when_completion_disabled(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A completion-enabled course with no criteria is a no-op.
     *
     * @return void
     */
    public function test_book_returns_false_without_criteria(): void {
        [$course, $user] = $this->course_and_user();

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A course that is already complete returns true without further work.
     *
     * @return void
     */
    public function test_book_returns_true_when_already_complete(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();

        $DB->insert_record('course_completions', (object)[
            'userid' => (int)$user->id,
            'course' => (int)$course->id,
            'timeenrolled' => time() - 200,
            'timestarted' => time() - 100,
            'timecompleted' => time() - 10,
            'reaggregate' => 0,
        ]);

        $this->assertTrue(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A satisfied activity criterion is recorded and the course is marked complete.
     *
     * @return void
     */
    public function test_book_marks_criterion_completion_and_course(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();
        $cm = $this->add_activity_criterion($course);

        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, (int)$user->id);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));

        // The criterion record must exist too, or core reports would contradict the course state.
        $this->assertTrue($DB->record_exists_select(
            'course_completion_crit_compl',
            'course = :course AND userid = :userid AND timecompleted > 0',
            ['course' => (int)$course->id, 'userid' => (int)$user->id]
        ));
    }

    /**
     * An unsatisfied activity criterion leaves both the criterion and the course open.
     *
     * @return void
     */
    public function test_book_returns_false_when_criteria_not_met(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();
        $this->add_activity_criterion($course);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
        $this->assertFalse($DB->record_exists('course_completion_crit_compl', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]));
    }

    /**
     * A passing course grade satisfies a grade criterion and stores the final grade.
     *
     * @return void
     */
    public function test_book_records_gradefinal_for_grade_criterion(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        [$course, $user] = $this->course_and_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE,
            'gradepass' => 50.0,
        ]);

        $gradeitem = new \grade_item([
            'courseid' => (int)$course->id,
            'itemtype' => 'manual',
            'itemname' => 'Test manual grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ]);
        $gradeitem->insert();
        $gradeitem->update_final_grade((int)$user->id, 75.0);
        grade_regrade_final_grades((int)$course->id);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $gradefinal = $DB->get_field('course_completion_crit_compl', 'gradefinal', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]);
        $this->assertEqualsWithDelta(75.0, (float)$gradefinal, 0.001);
    }

    /**
     * A failing course grade leaves a grade criterion unsatisfied.
     *
     * @return void
     */
    public function test_book_returns_false_when_grade_criterion_not_met(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        [$course, $user] = $this->course_and_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE,
            'gradepass' => 50.0,
        ]);

        $gradeitem = new \grade_item([
            'courseid' => (int)$course->id,
            'itemtype' => 'manual',
            'itemname' => 'Test manual grade item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ]);
        $gradeitem->insert();
        $gradeitem->update_final_grade((int)$user->id, 30.0);
        grade_regrade_final_grades((int)$course->id);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * A date criterion in the past completes the course as of that date, not now.
     *
     * @return void
     */
    public function test_book_uses_criterion_date_as_completion_time(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();

        $timeend = mktime(0, 0, 0, 1, 1, 2020);
        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_DATE,
            'timeend' => $timeend,
        ]);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $timecompleted = $DB->get_field('course_completions', 'timecompleted', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]);
        $this->assertEquals($timeend, (int)$timecompleted);
    }

    /**
     * A date criterion in the future leaves the course open.
     *
     * @return void
     */
    public function test_book_returns_false_when_date_criterion_not_met(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_DATE,
            'timeend' => mktime(0, 0, 0, 1, 1, 2099),
        ]);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse((new \completion_info($course))->is_course_complete((int)$user->id));
    }

    /**
     * A self-completion criterion is never satisfied on the user's behalf.
     *
     * @return void
     */
    public function test_book_never_marks_self_criterion(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse($DB->record_exists('course_completion_crit_compl', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]));
    }

    /**
     * A self-completion criterion the user already satisfied is aggregated into the course.
     *
     * @return void
     */
    public function test_book_aggregates_existing_self_criterion(): void {
        global $DB;
        [$course, $user] = $this->course_and_user();

        $criterionid = $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);

        // Record the criterion the way core does when the user self-completes.
        $criterioncompletion = new \completion_criteria_completion([
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
            'criteriaid' => (int)$criterionid,
        ]);
        $criterioncompletion->mark_complete();

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $this->assertTrue((new \completion_info($course))->is_course_complete((int)$user->id));
    }
}
