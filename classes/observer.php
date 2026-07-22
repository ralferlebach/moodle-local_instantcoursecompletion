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
use local_instantcoursecompletion\task\notify_dependent_courses_task;

/**
 * Event observer implementation.
 */
class observer {
    /** @var string Book completions in the triggering request, with no cron delay. */
    public const MODE_SYNC = 'sync';

    /** @var string Book completions in an ad-hoc task on the next cron run. */
    public const MODE_ASYNC = 'async';

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
     * React to a course completion by queuing a bounded re-evaluation of the courses
     * that require it.
     *
     * A hub course required by many programmes can have far more dependents than fit
     * in one request; the fan-out itself lives in notify_dependent_courses_task, which
     * pages through them and continues across further tasks if needed.
     *
     * @param \core\event\course_completed $event The triggering event.
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $task = new notify_dependent_courses_task();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'fromcourseid' => 0,
        ]);

        try {
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            self::report_failure('could not queue dependent-course notification for'
                . " course={$courseid} user={$userid}", $e);
        }
    }

    /**
     * Plan due bookings for a newly enrolled user.
     *
     * @param \core\event\user_enrolment_created $event The triggering event.
     * @return void
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        self::schedule_due_bookings((int)$event->courseid, (int)$event->relateduserid);
    }

    /**
     * Re-plan due bookings after an enrolment changed.
     *
     * A changed start date moves the due time of a duration criterion. The batch planned
     * for the old due window stays queued and ends without effect, because it
     * re-evaluates the criterion rather than trusting the window it was planned for.
     *
     * @param \core\event\user_enrolment_updated $event The triggering event.
     * @return void
     */
    public static function user_enrolment_updated(\core\event\user_enrolment_updated $event): void {
        self::schedule_due_bookings((int)$event->courseid, (int)$event->relateduserid);
    }

    /**
     * Aggregate a self-completion the moment the learner returns to the course.
     *
     * Self and manual (role) criteria are recorded by the user or teacher action
     * directly, without an event this plugin could observe; core leaves the
     * course_completions row flagged for reaggregation but does not aggregate it until
     * cron. The teacher (role) case has no in-request signal and stays with the reconcile
     * task. The self case does have one: course/togglecompletion.php redirects the learner
     * straight back to the course, so the course_viewed of that redirect is the first
     * request after the mark and the place to aggregate it -- now, without cron.
     *
     * The gate is deliberately cheap, because course_viewed is the busiest event on the
     * site: a course without a self criterion costs one cached lookup and returns; a
     * self-completion course with nothing pending costs one further indexed read.
     *
     * @param \core\event\course_viewed $event The triggering event.
     * @return void
     */
    public static function course_viewed(\core\event\course_viewed $event): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $courseid = (int)$event->courseid;
        $userid = (int)$event->userid;
        try {
            if ($courseid <= 0 || $userid <= 0 || $courseid == SITEID) {
                return;
            }

            // Only self-completion courses need this path; everything else stops here.
            if (!criteria_index::has_type($courseid, COMPLETION_CRITERIA_TYPE_SELF)) {
                return;
            }

            if (!scope_resolver::is_in_scope($courseid)) {
                return;
            }

            // Do the work only when an aggregation is actually pending. mark_inprogress()
            // sets reaggregate when the self criterion is recorded, and the booking's
            // aggregate_completions() clears it again, so a course viewed with nothing
            // pending never books and a repeat view after booking stops at this read.
            if (!self::has_pending_reaggregation($courseid, $userid)) {
                return;
            }

            completion_booker::book($courseid, $userid);
        } catch (\Throwable $e) {
            self::report_failure('self-completion aggregation failed for'
                . " course={$courseid} user={$userid}", $e);
        }
    }

    /**
     * Plan the time-based bookings of one user, swallowing any failure.
     *
     * @param int $courseid Affected course ID.
     * @param int $userid   Affected user ID.
     * @return void
     */
    protected static function schedule_due_bookings(int $courseid, int $userid): void {
        try {
            due_scheduler::schedule_user($courseid, $userid);
        } catch (\Throwable $e) {
            self::report_failure('due scheduling failed', $e);
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
            self::report_failure('scope cache purge failed', $e);
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
            self::report_failure('course scope purge failed', $e);
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
            self::report_failure('criteria index purge failed', $e);
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
     * Handle a completion trigger: scope check, then book in the request or queue a task.
     *
     * By default the booking is synchronous, so the completion and its course_completed
     * event happen in the triggering request without waiting for cron. Under the async
     * fallback the work is handed to an ad-hoc task instead, which trades the immediacy
     * for a cheaper request on very large scopes. Either way the completion evaluation
     * reads the criteria, writes the completion records, dispatches course_completed and
     * sends the notification.
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

            if (self::processing_mode() === self::MODE_ASYNC) {
                self::queue_booking($courseid, $userid);
                return;
            }

            // Synchronous is the default: the completion is booked in the request that
            // triggered it, so aggregate_completions() and the course_completed event run
            // now rather than on the next cron tick. That is the point of the plugin --
            // downstream reactions to course_completed (a learning path advancing, a
            // certificate issuing) are themselves synchronous, and deferring the booking to
            // an ad-hoc task defers all of it to whenever cron next runs, which on a site
            // with cron disabled or slow is indefinitely.
            completion_booker::book($courseid, $userid);
        } catch (\Throwable $e) {
            self::report_failure('trigger handling failed for'
                . " course={$courseid} user={$userid}", $e);
        }
    }

    /**
     * Queue the asynchronous booking of one course-user pair.
     *
     * The fallback mode for very large scopes, where the completion evaluation is not
     * wanted in the triggering request. The task runs in the system context, not as the
     * learner: a learner suspended between the trigger and the run must not cause core to
     * discard the booking, and the automated booking is not attributed to them. Identical
     * pending (course, user) tasks are de-duplicated by the queue; once a booking has run
     * and left the queue, the same trigger queues a fresh one, which is what lets a later
     * prerequisite re-evaluate the course.
     *
     * @param int $courseid Affected course ID.
     * @param int $userid   Affected user ID.
     * @return void
     */
    protected static function queue_booking(int $courseid, int $userid): void {
        $task = new book_completion_task();
        $task->set_custom_data((object)[
            'courseid' => $courseid,
            'userid' => $userid,
        ]);

        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * The configured completion-processing mode.
     *
     * Synchronous unless async is explicitly configured, so an unset value -- a fresh
     * install, or a site upgraded from a version that had removed the setting -- books in
     * the request rather than silently depending on cron.
     *
     * @return string One of MODE_SYNC or MODE_ASYNC.
     */
    protected static function processing_mode(): string {
        $mode = get_config('local_instantcoursecompletion', 'processingmode');
        return $mode === self::MODE_ASYNC ? self::MODE_ASYNC : self::MODE_SYNC;
    }

    /**
     * Whether the user's course completion is flagged for reaggregation.
     *
     * The flag is core's own signal that a criterion completion was written without the
     * course being aggregated afterwards. Reading it keeps the busy course_viewed path
     * from evaluating criteria on a course where nothing has changed.
     *
     * @param int $courseid Course ID.
     * @param int $userid   User ID.
     * @return bool
     */
    protected static function has_pending_reaggregation(int $courseid, int $userid): bool {
        global $DB;

        $reaggregate = $DB->get_field('course_completions', 'reaggregate', [
            'course' => $courseid,
            'userid' => $userid,
        ]);

        return !empty($reaggregate);
    }

    /**
     * Record a swallowed failure.
     *
     * The observer must never break the request that fired the event, so failures are
     * caught. They are still surfaced: always to the developer log, and additionally to
     * the task log when running under cron, where no user is watching and an invisible
     * failure would otherwise be lost until the next reconcile.
     *
     * @param string     $context Short description of what failed.
     * @param \Throwable $e       The caught error.
     * @return void
     */
    private static function report_failure(string $context, \Throwable $e): void {
        $message = 'local_instantcoursecompletion: ' . $context . ': ' . $e->getMessage();
        debugging($message, DEBUG_DEVELOPER);
        if (CLI_SCRIPT) {
            mtrace($message);
        }
    }
}
