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
 * Upgrade steps for local_instantcoursecompletion.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Apply the upgrade steps between two plugin versions.
 *
 * @param int $oldversion The version the site is upgrading from.
 * @return bool
 */
function xmldb_local_instantcoursecompletion_upgrade($oldversion) {
    if ($oldversion < 2026070911) {
        // The synchronous processing mode was removed; drop its orphaned setting.
        unset_config('processingmode', 'local_instantcoursecompletion');

        upgrade_plugin_savepoint(true, 2026070911, 'local', 'instantcoursecompletion');
    }

    return true;
}
