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
 * Event fired when this plugin books a course completion.
 *
 * Emitted only when logging is enabled (enablelogging setting). Allows sites to
 * audit which completions were accelerated by this plugin via the standard logstore
 * — see Site administration > Reports > Accelerated completions.
 *
 * objectid = courseid (the course whose completion was booked).
 * relateduserid = the user whose course completion was booked.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\event;

/**
 * The completion_booked event.
 *
 * @property-read array $other {
 *      Extra information about the event.
 * }
 */
class completion_booked extends \core\event\base {
    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course';
    }

    /**
     * Return the localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:completion_booked', 'local_instantcoursecompletion');
    }

    /**
     * Return a non-localised description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->relateduserid}' had a course completion booked " .
            "for the course with id '{$this->courseid}' by local_instantcoursecompletion.";
    }

    /**
     * Map objectid to its backup/restore counterpart.
     *
     * objectid holds the course id; courses are not remapped during restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'course', 'restore' => self::NOT_MAPPED];
    }
}
