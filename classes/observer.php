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
     * Core marks and aggregates the activity criteria of this course for this user
     * before the event fires, so only the remaining criterion types can still turn
     * the course complete.
     *
     * @param \core\event\course_module_completion_updated $event The triggering event.
     * @return void
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event): void {
        $courseid = (int)$event->courseid;
        if (!criteria_index::has_non_activity_type($courseid)) {
            return;
        }
        self::handle_completion_trigger($courseid, (int)$event->relateduserid);
    }

    /**
     * React to a gradebook grade change.
     *
     * @param \core\event\user_graded $event The triggering event.
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $courseid = (int)$event->courseid;
        if (!criteria_index::has_type($courseid, COMPLETION_CRITERIA_TYPE_GRADE)) {
            return;
        }
        self::handle_completion_trigger($courseid, (int)$event->relateduserid);
    }

    /**
     * React to a course completion by re-evaluating the courses that require it.
     *
     * @param \core\event\course_completed $event The triggering event.
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        $userid = (int)$event->relateduserid;

        foreach (criteria_index::dependent_course_ids((int)$event->courseid) as $dependentid) {
            self::handle_completion_trigger($dependentid, $userid);
        }
    }

    /**
     * Purge both scope caches after a category tree or tag change.
     *
     * @param \core\event\base $event The triggering event.
     * @return void
     */
    public static function invalidate_scope_cache(base $event): void {
        try {
            if (scope_resolver::get_mode() === scope_resolver::SCOPE_ALL) {
                // Nothing is cached in this mode.
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
     * Drop the cached scope membership of one course after it changed.
     *
     * A course can leave or enter the scope by moving to another category; its tags
     * are handled by the tag events, which purge the whole membership cache.
     *
     * @param \core\event\base $event The triggering event.
     * @return void
     */
    public static function invalidate_course_scope(base $event): void {
        try {
            scope_resolver::purge_course((int)$event->courseid);
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: course scope purge failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Purge the cached criterion types after the course completion settings changed.
     *
     * @param \core\event\base $event The triggering event.
     * @return void
     */
    public static function invalidate_criteria_index(base $event): void {
        try {
            criteria_index::purge((int)$event->courseid);
        } catch (\Throwable $e) {
            debugging(
                'local_instantcoursecompletion: criteria index purge failed: ' . $e->getMessage(),
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
     * Handle a completion trigger: scope check, de-duplication, then enqueue.
     *
     * Booking never happens in the request. Completion evaluation reads the criteria,
     * writes completion records, dispatches course_completed and sends a notification;
     * none of that belongs on a learner's page load.
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

            $task = new book_completion_task();
            $task->set_custom_data((object)[
                'courseid' => $courseid,
                'userid' => $userid,
            ]);

            // The user is attached so that the de-duplication lookup can use the indexed
            // userid column; customdata carries no index and would be scanned in full.
            // queue_adhoc_task() rejects users that cannot own a task; the catch below
            // turns that into a skipped booking rather than a failed page load.
            $task->set_userid($userid);

            // This is an ASAP task, the only kind $checkforexisting is documented for.
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
