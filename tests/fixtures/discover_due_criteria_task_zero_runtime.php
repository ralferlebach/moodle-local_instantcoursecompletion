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
 * Test fixture: a discovery task with no wall-clock budget.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\task\discover_due_criteria_task;

/**
 * A discovery task with no wall-clock budget, so it stops at the first course it scans.
 */
class discover_due_criteria_task_zero_runtime extends discover_due_criteria_task {
    /**
     * No budget: the run stops before planning and leaves its cursor for the next run.
     *
     * @return int
     */
    protected function max_runtime(): int {
        return 0;
    }
}
