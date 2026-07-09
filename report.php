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
 * Admin report: the most recent course completions booked by this plugin.
 *
 * Reads completion_booked events from the standard logstore, which only contains
 * them while the "Enable logging" setting is on.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

/** @var int Number of events listed; the log table is too large for an unbounded count. */
const LOCAL_INSTANTCOURSECOMPLETION_REPORT_ROWS = 100;

$component = 'local_instantcoursecompletion';

admin_externalpage_setup($component . '_report');

$title = get_string('report:title', $component);
echo $OUTPUT->header();
echo $OUTPUT->heading($title);

if (!get_config($component, 'enablelogging')) {
    echo $OUTPUT->notification(get_string('report:loggingdisabled', $component), 'info');
}

$stores = get_log_manager()->get_readers('core\log\sql_reader');
if (empty($stores)) {
    echo $OUTPUT->notification(get_string('report:nostorewarning', $component), 'warning');
    echo $OUTPUT->footer();
    exit;
}

/** @var \core\log\sql_reader $store */
$store = reset($stores);

$select = 'component = :component AND eventname = :eventname';
$params = [
    'component' => $component,
    'eventname' => '\local_instantcoursecompletion\event\completion_booked',
];
$events = $store->get_events_select(
    $select,
    $params,
    'timecreated DESC',
    0,
    LOCAL_INSTANTCOURSECOMPLETION_REPORT_ROWS
);

if (empty($events)) {
    echo $OUTPUT->notification(get_string('report:noevents', $component), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Resolve the referenced courses and users in two queries rather than two per row.
$courseids = [];
$userids = [];
foreach ($events as $event) {
    $courseids[(int)$event->courseid] = true;
    $userids[(int)($event->relateduserid ?: $event->userid)] = true;
}
$courses = $DB->get_records_list('course', 'id', array_keys($courseids), '', 'id, fullname');
$namefields = implode(', ', \core_user\fields::get_name_fields());
$users = $DB->get_records_list('user', 'id', array_keys($userids), '', 'id, ' . $namefields);

echo html_writer::tag('p', get_string('report:rowcount', $component, LOCAL_INSTANTCOURSECOMPLETION_REPORT_ROWS));

$table = new html_table();
$table->head = [
    get_string('report:col:time', $component),
    get_string('report:col:course', $component),
    get_string('report:col:user', $component),
];
$table->attributes['class'] = 'admintable generaltable';
$table->data = [];

foreach ($events as $event) {
    $courseid = (int)$event->courseid;
    $userid = (int)($event->relateduserid ?: $event->userid);

    if (isset($courses[$courseid])) {
        $coursecell = html_writer::link(
            new moodle_url('/course/view.php', ['id' => $courseid]),
            format_string($courses[$courseid]->fullname)
        );
    } else {
        $coursecell = get_string('report:unknowncourse', $component, $courseid);
    }

    if (isset($users[$userid])) {
        $usercell = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $userid]),
            fullname($users[$userid])
        );
    } else {
        $usercell = get_string('report:unknownuser', $component, $userid);
    }

    $table->data[] = [userdate($event->timecreated), $coursecell, $usercell];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
