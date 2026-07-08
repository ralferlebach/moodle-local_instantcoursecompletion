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
 * Scheduled task definitions for local_instantcoursecompletion.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [

    // Optional safety net: periodically re-checks in-scope courses for completions
    // that event triggers cannot catch (e.g. date/duration criteria). Runs only when
    // local_instantcoursecompletion/reconcile_enabled is on; otherwise it exits early.
    [
        'classname' => '\local_instantcoursecompletion\task\reconcile_task',
        'blocking'  => 0,
        'minute'    => '17',
        'hour'      => '*/6',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
