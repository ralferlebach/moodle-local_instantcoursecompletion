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
 * Two groups of observers:
 *  1. Completion triggers — events after which a course completion might newly
 *     be achievable for the affected user.
 *  2. Scope-cache invalidation — structural changes that can alter which courses
 *     are in scope. These callbacks only purge a cache; they never compute.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // Completion triggers.
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback'  => '\local_instantcoursecompletion\observer::course_module_completion_updated',
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback'  => '\local_instantcoursecompletion\observer::user_graded',
    ],

    // Scope-cache invalidation.
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\course_updated',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\course_category_created',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\course_category_updated',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\course_category_deleted',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\tag_added',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
    [
        'eventname' => '\core\event\tag_removed',
        'callback'  => '\local_instantcoursecompletion\observer::invalidate_scope_cache',
    ],
];
