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

$string['event:completion_booked'] = 'Course completion booked (instant)';
$string['pluginname'] = 'Instant course completion';
$string['privacy:metadata'] = 'The Instant course completion plugin stores no personal data. It books course completions through Moodle\'s core completion API into core-owned tables.';
$string['processingmode:async'] = 'Asynchronous (ad-hoc task, recommended)';
$string['processingmode:sync'] = 'Synchronous (in the request)';
$string['scope:adele'] = 'Use local_adele settings (category branches / tags)';
$string['scope:all'] = 'All courses on this site';
$string['scope:categories'] = 'Selected category branches';
$string['setting:categories'] = 'Category branches';
$string['setting:categories_desc'] = 'Courses in these categories (and their sub-categories) are in scope. Only used when the scope is set to selected category branches.';
$string['setting:enablelogging'] = 'Enable logging';
$string['setting:enablelogging_desc'] = 'Write a trace line whenever a completion is evaluated or booked. Useful for auditing; leave off in production unless needed.';
$string['setting:excludetags'] = 'Excluded course tags';
$string['setting:excludetags_desc'] = 'Comma-separated list of course tags. Courses carrying any of these tags are excluded from scope.';
$string['setting:includetags'] = 'Included course tags';
$string['setting:includetags_desc'] = 'Comma-separated list of course tags. When set, only courses carrying at least one of these tags are in scope.';
$string['setting:processingmode'] = 'Processing mode';
$string['setting:processingmode_desc'] = 'Asynchronous queues a deduplicated ad-hoc task (lowest request-path cost, recommended). Synchronous books the completion immediately in the request — use only for small scopes where minimal latency matters more than request cost.';
$string['setting:reconcile'] = 'Enable safety-net reconcile task';
$string['setting:reconcile_desc'] = 'Periodically re-checks in-scope courses for completions that event triggers cannot detect (e.g. date/duration criteria). Off by default.';
$string['setting:scopemode'] = 'Observer scope';
$string['setting:scopemode_desc'] = 'Which courses the completion observers act on. "Use local_adele settings" is available only when local_adele is installed.';
$string['task:bookcompletion'] = 'Book course completion (instant)';
$string['task:reconcile'] = 'Reconcile course completions (safety net)';
