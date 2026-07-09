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
 * Admin report: accelerated course completions booked by local_instantcoursecompletion.
 *
 * Reads completion_booked events from the standard logstore. Requires the
 * "Enable logging" plugin setting to be on so that events are actually fired.
 * Shows at most the 100 most recent events.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$component = 'local_instantcoursecompletion';

$PAGE->set_url(new moodle_url('/local/instantcoursecompletion/report.php'));
$PAGE->set_context(context_system::instance());

require_login();
require_capability('moodle/site:config', context_system::instance());

$title = get_string('report:title', $component);
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);

// Advisory: warn when logging is disabled so events are not recorded.
if (!get_config($component, 'enablelogging')) {
    echo $OUTPUT->notification(get_string('report:loggingdisabled', $component), 'info');
}

// Retrieve an SQL-compatible logstore reader.
$manager = get_log_manager();
$stores = $manager->get_readers('core\log\sql_reader');

if (empty($stores)) {
    echo $OUTPUT->notification(get_string('report:nostorewarning', $component), 'warning');
    echo $OUTPUT->footer();
    exit;
}

/** @var \core\log\sql_reader $store */
$store = reset($stores);

// Query by component name and fully-qualified event class name.
$eventname = '\local_instantcoursecompletion\event\completion_booked';
$select = "component = :component AND eventname = :eventname";
$params = ['component' => $component, 'eventname' => $eventname];

$total = $store->get_events_select_count($select, $params);
$events = $store->get_events_select($select, $params, 'timecreated DESC', 0, 100);

if (empty($events)) {
    echo $OUTPUT->notification(get_string('report:noevents', $component), 'info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('report:totalcount', $component, $total));

$table = new html_table();
$table->head = [
    get_string('report:col:time', $component),
    get_string('report:col:course', $component),
    get_string('report:col:user', $component),
];
$table->attributes['class'] = 'admintable generaltable';
$table->data = [];

foreach ($events as $event) {
    if ($event === null) {
        continue;
    }

    $courseid = (int)$event->courseid;
    $userid = (int)($event->relateduserid ?: $event->userid);

    // Course cell.
    try {
        $course = get_course($courseid);
        $coursecell = html_writer::link(
            new moodle_url('/course/view.php', ['id' => $courseid]),
            format_string($course->fullname)
        );
    } catch (\dml_exception $e) {
        $coursecell = get_string('report:unknowncourse', $component, $courseid);
    }

    // User cell.
    $user = core_user::get_user($userid);
    if ($user) {
        $usercell = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $userid]),
            fullname($user)
        );
    } else {
        $usercell = get_string('report:unknownuser', $component, $userid);
    }

    $table->data[] = [userdate($event->timecreated), $coursecell, $usercell];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
