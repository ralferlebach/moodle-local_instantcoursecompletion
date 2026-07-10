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
 * Tests for the due-candidate repository.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/completion_test_trait.php');

/**
 * Due-candidate repository tests.
 *
 * The pending-user queries are exercised through the discovery and batch task tests; the
 * enrolment-time rule, which those never observe directly, is pinned here.
 *
 * @covers \local_instantcoursecompletion\due_candidate_repository
 */
final class due_candidate_repository_test extends \advanced_testcase {
    use completion_test_trait;

    /**
     * Reset the database before each test.
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
     * Without an enrolment start date, the enrolment creation time is used.
     *
     * @return void
     */
    public function test_get_enrolment_time_falls_back_to_timecreated(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $timecreated = time() - DAYSECS * 5;
        $this->enrol_user_direct($course, $user, 0, $timecreated);

        $this->assertSame($timecreated, due_candidate_repository::get_enrolment_time((int)$course->id, (int)$user->id));
    }

    /**
     * A user with no enrolment at all has no enrolment time.
     *
     * @return void
     */
    public function test_get_enrolment_time_without_enrolment(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(due_candidate_repository::get_enrolment_time((int)$course->id, (int)$user->id));
    }
}
