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
 * Evaluates and books a course completion for a single (course, user) pair.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Single-user course-completion booker.
 */
class completion_booker {
    /**
     * Evaluate the course's completion criteria for one user and aggregate the result.
     *
     * Callers that book more than one user of the same course should open a
     * course_booker themselves, so that the course, its completion_info and its criteria
     * are read once rather than once per user.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return bool True if the course is complete for the user after this call.
     */
    public static function book(int $courseid, int $userid): bool {
        $booker = course_booker::for_course($courseid);

        return $booker !== null && $booker->book($userid);
    }
}
