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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');

/**
 * Completion booker tests.
 *
 * @covers \local_instantcoursecompletion\completion_booker
 */
final class completion_booker_test extends \advanced_testcase {
    use completion_test_trait;

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
        criteria_index::purge();
    }

    /**
     * Create a completion-enabled course and an enrolled, tracked user.
     *
     * @return array Two elements: course record, user record.
     */
    protected function course_and_tracked_user(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $user);
        return [$course, $user];
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
        $this->enrol_user_direct($course, $user);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A completion-enabled course with no criteria is a no-op.
     *
     * @return void
     */
    public function test_book_returns_false_without_criteria(): void {
        [$course, $user] = $this->course_and_tracked_user();

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A course that is already complete returns true without further work.
     *
     * The tracked-user guard sits behind this check: a completion that already exists
     * is reported regardless of the role the user holds today.
     *
     * @return void
     */
    public function test_book_returns_true_when_already_complete(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->mark_course_completed($course, $user);

        $this->assertTrue(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A user without moodle/course:isincompletionreports is never booked.
     *
     * @return void
     */
    public function test_book_skips_untracked_user(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $teacher, 0, 0, 0, 'editingteacher');
        $cm = $this->add_activity_criterion($course);
        $this->complete_activity($course, $cm, $teacher, true);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$teacher->id));
        $this->assertFalse($this->is_complete($course, $teacher));
        $this->assertFalse($DB->record_exists('course_completion_crit_compl', [
            'course' => (int)$course->id,
            'userid' => (int)$teacher->id,
        ]));
    }

    /**
     * An enrolled user with no role at all is not tracked either.
     *
     * @return void
     */
    public function test_book_skips_user_without_role(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_user_direct($course, $user, 0, 0, 0, '');
        $cm = $this->add_activity_criterion($course);
        $this->complete_activity($course, $cm, $user, true);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
    }

    /**
     * A satisfied activity criterion is recorded and the course is marked complete.
     *
     * @return void
     */
    public function test_book_marks_criterion_completion_and_course(): void {
        global $DB;
        [$course, $user] = $this->course_and_tracked_user();
        $cm = $this->add_activity_criterion($course);
        $this->complete_activity($course, $cm, $user, true);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $this->assertTrue($this->is_complete($course, $user));

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
        [$course, $user] = $this->course_and_tracked_user();
        $this->add_activity_criterion($course);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse($this->is_complete($course, $user));
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
        [$course, $user] = $this->course_and_tracked_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE,
            'gradepass' => 50.0,
        ]);
        $this->set_course_grade($course, $user, 75.0);

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
        [$course, $user] = $this->course_and_tracked_user();

        $DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_GRADE,
            'gradepass' => 50.0,
        ]);
        $this->set_course_grade($course, $user, 30.0);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse($this->is_complete($course, $user));
    }

    /**
     * Give the user a final grade in a real manual grade item.
     *
     * completion_criteria_grade::review() reads the course total, which is recomputed
     * from its sub-items on every regrade; writing that field directly is discarded.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user   The user.
     * @param float     $grade  The final grade.
     * @return void
     */
    protected function set_course_grade(\stdClass $course, \stdClass $user, float $grade): void {
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
        $this->resetDebugging();
    }

    /**
     * A date criterion in the past completes the course as of that date, not now.
     *
     * @return void
     */
    public function test_book_uses_criterion_date_as_completion_time(): void {
        global $DB;
        [$course, $user] = $this->course_and_tracked_user();

        $timeend = mktime(0, 0, 0, 1, 1, 2020);
        $this->add_date_criterion($course, $timeend);

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
        [$course, $user] = $this->course_and_tracked_user();
        $this->add_date_criterion($course, mktime(0, 0, 0, 1, 1, 2099));

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse($this->is_complete($course, $user));
    }

    /**
     * A self-completion criterion is never satisfied on the user's behalf.
     *
     * @return void
     */
    public function test_book_never_marks_self_criterion(): void {
        global $DB;
        [$course, $user] = $this->course_and_tracked_user();

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
        [$course, $user] = $this->course_and_tracked_user();

        $criterionid = (int)$DB->insert_record('course_completion_criteria', (object)[
            'course' => (int)$course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF,
            'aggregationmethod' => COMPLETION_AGGREGATION_ALL,
        ]);
        $this->mark_criterion_completed($course, $user, $criterionid);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $this->assertTrue($this->is_complete($course, $user));
    }

    /**
     * An elapsed duration criterion completes as of the enrolment start plus the period.
     *
     * @return void
     */
    public function test_book_completes_duration_criterion_from_timestart(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timestart = time() - DAYSECS * 3;
        $this->add_duration_criterion($course, DAYSECS);
        $this->enrol_user_direct($course, $user, $timestart, time() - DAYSECS * 4);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $timecompleted = $DB->get_field('course_completions', 'timecompleted', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]);
        $this->assertEquals($timestart + DAYSECS, (int)$timecompleted);
    }

    /**
     * Without an enrolment start date, the enrolment creation time is used instead.
     *
     * completion_criteria_duration::review() ignores this fallback and would never
     * complete such users, although the criterion's own cron does apply it.
     *
     * @return void
     */
    public function test_book_completes_duration_criterion_without_timestart(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timecreated = time() - DAYSECS * 3;
        $this->add_duration_criterion($course, DAYSECS);
        $this->enrol_user_direct($course, $user, 0, $timecreated);

        $result = completion_booker::book((int)$course->id, (int)$user->id);
        $this->resetDebugging();

        $this->assertTrue($result);
        $timecompleted = $DB->get_field('course_completions', 'timecompleted', [
            'course' => (int)$course->id,
            'userid' => (int)$user->id,
        ]);
        $this->assertEquals($timecreated + DAYSECS, (int)$timecompleted);
    }

    /**
     * A duration criterion that has not elapsed yet leaves the course open.
     *
     * @return void
     */
    public function test_book_returns_false_when_duration_not_elapsed(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->add_duration_criterion($course, DAYSECS * 30);
        $this->enrol_user_direct($course, $user, time() - HOURSECS, time() - HOURSECS);

        $this->assertFalse(completion_booker::book((int)$course->id, (int)$user->id));
        $this->assertFalse($this->is_complete($course, $user));
    }
}
