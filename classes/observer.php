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
 * Event observers for local_instantcoursecompletion.
 *
 * These callbacks run in the web request and must stay lightweight and must never
 * throw: a cheap scope lookup (cached) followed by either enqueuing a deduplicated
 * ad-hoc task (async mode) or a direct booking call (sync mode). All heavy work is
 * done in \local_instantcoursecompletion\task\book_completion_task.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use core\event\base;
use local_instantcoursecompletion\task\book_completion_task;

/**
 * Event observer implementation.
 */
class observer {
    /**
     * Per-request de-duplication registry of already-handled "courseid:userid" pairs.
     *
     * @var array<string, bool>
     */
    protected static $seen = [];

    /**
     * React to an activity-completion state change.
     *
     * @param \core\event\course_module_completion_updated $event The triggering event.
     * @return void
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event): void {
        self::handle_completion_trigger((int)$event->courseid, (int)$event->relateduserid);
    }

    /**
     * React to a gradebook grade change (grade-to-pass based criteria).
     *
     * @param \core\event\user_graded $event The triggering event.
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event): void {
        self::handle_completion_trigger((int)$event->courseid, (int)$event->relateduserid);
    }

    /**
     * Purge the resolved-scope cache after a structural change.
     *
     * Registered for course / category / tag create-update-delete events. This
     * callback intentionally does no computation beyond clearing the cache.
     *
     * @param \core\event\base $event The triggering event.
     * @return void
     */
    public static function invalidate_scope_cache(base $event): void {
        try {
            scope_resolver::purge_cache();
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: scope cache purge failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Handle a completion trigger: scope check, de-duplication, then enqueue or run.
     *
     * Public so it can be driven directly by unit tests without going through the
     * full activity-completion and enrolment machinery.
     *
     * @param int $courseid Affected course ID.
     * @param int $userid   Affected user ID.
     * @return void
     */
    public static function handle_completion_trigger(int $courseid, int $userid): void {
        try {
            if ($courseid <= 0 || $userid <= 0 || $courseid == SITEID) {
                return;
            }

            // Cheap, cached scope check — the only real work on the request path.
            if (!scope_resolver::is_in_scope($courseid)) {
                return;
            }

            // Collapse repeated triggers for the same (course, user) within this request.
            $key = $courseid . ':' . $userid;
            if (isset(self::$seen[$key])) {
                return;
            }
            self::$seen[$key] = true;

            if (get_config('local_instantcoursecompletion', 'processingmode') === 'sync') {
                // Synchronous mode: book immediately in the request (small scopes only).
                completion_booker::book($courseid, $userid);
                return;
            }

            // Asynchronous mode (default): enqueue a deduplicated ad-hoc task.
            $task = new book_completion_task();
            $task->set_custom_data((object)[
                'courseid' => $courseid,
                'userid'   => $userid,
            ]);
            $task->set_userid($userid);

            // The second argument makes the queue collapse identical pending tasks.
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            // Observers must never fatal — log and continue.
            debugging(
                'local_instantcoursecompletion: trigger handling failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Reset the per-request de-duplication registry.
     *
     * Relevant for long-running CLI processes and for unit tests, where a single
     * PHP process handles many simulated requests.
     *
     * @return void
     */
    public static function reset_seen(): void {
        self::$seen = [];
    }
}
