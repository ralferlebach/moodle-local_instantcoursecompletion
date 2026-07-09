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
     * Course and user pairs already handled in the current request.
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
     * React to a gradebook grade change.
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
     * @param \core\event\base $event The triggering event.
     * @return void
     */
    public static function invalidate_scope_cache(base $event): void {
        try {
            if (scope_resolver::get_mode() === scope_resolver::SCOPE_ALL) {
                // No scope set is cached in this mode.
                return;
            }
            if (!self::event_affects_scope($event)) {
                return;
            }
            scope_resolver::purge_cache();
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: scope cache purge failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Whether the event can change which courses are in scope.
     *
     * Tag events fire for every taggable item type; only course tags matter here.
     *
     * @param \core\event\base $event The triggering event.
     * @return bool
     */
    protected static function event_affects_scope(base $event): bool {
        $tagevents = [
            \core\event\tag_added::class,
            \core\event\tag_removed::class,
        ];
        foreach ($tagevents as $tagevent) {
            if ($event instanceof $tagevent) {
                return ($event->other['itemtype'] ?? '') === 'course';
            }
        }
        return true;
    }

    /**
     * Handle a completion trigger: scope check, de-duplication, then enqueue or run.
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

            if (!scope_resolver::is_in_scope($courseid)) {
                return;
            }

            $key = $courseid . ':' . $userid;
            if (isset(self::$seen[$key])) {
                return;
            }
            self::$seen[$key] = true;

            if (get_config('local_instantcoursecompletion', 'processingmode') === 'sync') {
                completion_booker::book($courseid, $userid);
                return;
            }

            // Booking is a system operation; the affected user travels in the custom data.
            $task = new book_completion_task();
            $task->set_custom_data((object)[
                'courseid' => $courseid,
                'userid' => $userid,
            ]);

            // The second argument makes the queue collapse identical pending tasks.
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: trigger handling failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Reset the per-request de-duplication registry.
     *
     * @return void
     */
    public static function reset_seen(): void {
        self::$seen = [];
    }
}
