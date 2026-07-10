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
 * English language strings for local_instantcoursecompletion.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_coursecriteriatypes'] = 'Completion criterion types configured per course';
$string['cachedef_scopecategoryids'] = 'Selected category branches expanded to include sub-categories';
$string['cachedef_scopecoursemembership'] = 'Whether a course is within the observer scope';
$string['cachedef_scopetagids'] = 'Selected course tags resolved to tag IDs';
$string['event:completion_booked'] = 'Course completion booked (instant)';
$string['pluginname'] = 'Instant course completion';
$string['privacy:metadata:log'] = 'The Instant course completion plugin emits a completion_booked event when it books a course completion and logging is enabled. The event records the course, the affected user and the time, and is stored by the logging subsystem.';
$string['report:col:course'] = 'Course';
$string['report:col:time'] = 'Time';
$string['report:col:user'] = 'User';
$string['report:loggingdisabled'] = 'Logging is currently disabled. Enable the "Enable logging" setting so that accelerated completions are recorded.';
$string['report:noevents'] = 'No accelerated completions have been recorded yet.';
$string['report:nostorewarning'] = 'No SQL-compatible logstore is available. Enable the Standard logstore to use this report.';
$string['report:rowcount'] = 'Showing the {$a} most recent events.';
$string['report:title'] = 'Accelerated completions';
$string['report:unknowncourse'] = '[Deleted course {$a}]';
$string['report:unknownuser'] = '[Deleted user {$a}]';
$string['scope:adele'] = 'Use local_adele settings (category branches / tags)';
$string['scope:all'] = 'All courses on this site';
$string['scope:categories'] = 'Selected category branches';
$string['setting:batchsize'] = 'Users booked per batch task';
$string['setting:batchsize_desc'] = 'How many users one batch task books before it hands on to a continuation task. Larger batches mean fewer tasks and longer individual runs.';
$string['setting:categories'] = 'Category branches';
$string['setting:categories_desc'] = 'Courses in these categories (and their sub-categories) are in scope. Only used when the scope is set to selected category branches.';
$string['setting:enablelogging'] = 'Enable logging';
$string['setting:enablelogging_desc'] = 'Record a completion_booked event whenever a completion is booked, and write a trace line during cron. Useful for auditing; leave off in production unless needed.';
$string['setting:excludetags'] = 'Excluded course tags';
$string['setting:excludetags_desc'] = 'Course tags, one per line or separated by commas. Courses carrying any of these tags are excluded from scope.';
$string['setting:includetags'] = 'Included course tags';
$string['setting:includetags_desc'] = 'Course tags, one per line or separated by commas. When set, only courses carrying at least one of these tags are in scope.';
$string['setting:maxtasksperrun'] = 'Maximum enrolments examined per run';
$string['setting:maxtasksperrun_desc'] = 'Upper bound on the enrolment records the discovery task examines in one run. A record that already has a task still counts, so a run always moves forward. A run that hits the bound resumes at the next user of the same criterion.';
$string['setting:reconcile'] = 'Enable safety-net reconcile task';
$string['setting:reconcile_desc'] = 'Periodically re-checks in-scope courses for completions that event triggers cannot detect (e.g. date/duration criteria). Each run processes a bounded slice of the site and resumes where the previous run stopped. Off by default.';
$string['setting:reconcilebudget'] = 'Maximum users examined per reconcile run';
$string['setting:reconcilebudget_desc'] = 'Upper bound on the users the reconcile task examines in one run. A run that hits the bound resumes at the next user of the same course.';
$string['setting:schedulingenabled'] = 'Plan time-based criteria in advance';
$string['setting:schedulingenabled_desc'] = 'Discovers date and duration criteria falling due within the horizon and queues one ad-hoc booking task per course, criterion and due window. Without this, such criteria are only picked up by the reconcile task or by the Moodle cron.';
$string['setting:schedulinghorizon'] = 'Scheduling horizon';
$string['setting:schedulinghorizon_desc'] = 'How far ahead due times are planned. This bounds how many rows the ad-hoc task queue can hold. It must be longer than the interval between two runs of the discovery task, which runs hourly.';
$string['setting:scopemode'] = 'Observer scope';
$string['setting:scopemode_desc'] = 'Which courses the completion observers act on. "Use local_adele settings" is available only when local_adele is installed.';
$string['settingoutofrange'] = 'Enter a value between {$a->min} and {$a->max}.';
$string['task:bookcompletion'] = 'Book course completion (instant)';
$string['task:bookduecompletionbatch'] = 'Book course completions (due criterion)';
$string['task:discoverduecriteria'] = 'Discover due completion criteria';
$string['task:notifydependentcourses'] = 'Notify courses requiring a completed course';
$string['task:reconcile'] = 'Reconcile course completions (safety net)';
$string['warning:adelemissing'] = 'The scope is set to "Use local_adele settings", but local_adele is not installed. No course is currently in scope. Choose a different scope.';
$string['warning:horizontooshort'] = 'The scheduling horizon is shorter than the discovery task\'s interval ({$a}). Due times inside that gap will not be planned until the following run. Set the horizon to at least {$a} plus normal cron jitter.';
