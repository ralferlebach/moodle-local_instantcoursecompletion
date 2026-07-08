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
 * Optional scheduled safety-net task.
 *
 * Catches completions that event triggers cannot detect (e.g. date / duration
 * criteria) by periodically re-checking in-scope courses. Disabled by default;
 * runs only when local_instantcoursecompletion/reconcile_enabled is set.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\task;

/**
 * Scheduled reconcile task.
 */
class reconcile_task extends \core\task\scheduled_task {
    /**
     * Human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:reconcile', 'local_instantcoursecompletion');
    }

    /**
     * Execute the reconcile pass.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_instantcoursecompletion', 'reconcile_enabled')) {
            // Feature disabled — exit cheaply.
            return;
        }

        // Phase 2 (deferred): iterate in-scope courses with completion enabled and,
        // for tracked users with pending completions, enqueue book_completion_task.
        // Kept intentionally minimal in the stub so it is a no-op unless enabled.
        mtrace('local_instantcoursecompletion reconcile_task: enabled but not yet implemented.');
    }
}
