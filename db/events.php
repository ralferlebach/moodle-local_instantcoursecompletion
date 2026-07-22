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
 * Event observer registration for local_instantcoursecompletion.
 *
 * All observers run after the triggering transaction has been committed: the
 * completion triggers write completion records and queue tasks, and the cache
 * invalidators must not discard state that a rolled-back change would restore.
 *
 * A newly created course needs no invalidation, because scope membership is only
 * ever cached for courses that have already been asked about.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_instantcoursecompletion\observer::course_module_completion_updated',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\local_instantcoursecompletion\observer::user_graded',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\local_instantcoursecompletion\observer::course_completed',
        'internal' => false,
    ],
    [
        // Self-completion is recorded without an observable event; the redirect back to
        // the course fires this, the first request in which it can be aggregated.
        'eventname' => '\core\event\course_viewed',
        'callback' => '\local_instantcoursecompletion\observer::course_viewed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_instantcoursecompletion\observer::user_enrolment_created',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\local_instantcoursecompletion\observer::user_enrolment_updated',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_completion_updated',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_criteria_index',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_criteria_index',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_reset_ended',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_criteria_index',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_course_scope',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_course_scope',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_category_created',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_category_updated',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_category_deleted',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\tag_added',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\tag_removed',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\tag_updated',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\tag_deleted',
        'callback' => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
        'internal' => false,
    ],
];
