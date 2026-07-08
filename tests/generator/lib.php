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
 * Test data generator for local_instantcoursecompletion.
 *
 * @package    local_instantcoursecompletion
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Generator for local_instantcoursecompletion test data.
 */
class local_instantcoursecompletion_generator extends component_generator_base {
    /**
     * Create a course with completion enabled and a single manual-completion page.
     *
     * @param array $record Optional overrides forwarded to create_course().
     * @return array{course: \stdClass, cm: \cm_info|\stdClass, page: \stdClass}
     */
    public function create_completion_course(array $record = []): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $record = array_merge(['enablecompletion' => 1], $record);
        $course = $this->datagenerator->create_course($record);

        $page = $this->datagenerator->create_module('page', [
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid);

        return ['course' => $course, 'cm' => $cm, 'page' => $page];
    }
}
