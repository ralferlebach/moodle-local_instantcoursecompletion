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
 * Privacy provider for local_instantcoursecompletion.
 *
 * The plugin owns no tables. With logging enabled it emits completion_booked events
 * that the logging subsystem stores on its behalf, so that subsystem is declared as
 * a link. The stored records belong to core_log, which exports and deletes them.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data this plugin causes to be stored.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_subsystem_link('core_log', [], 'privacy:metadata:log');
        return $collection;
    }

    /**
     * Return the contexts holding personal data owned by this plugin.
     *
     * @param int $userid The user to search.
     * @return contextlist Always empty; the log subsystem owns the records.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * Add the users holding personal data owned by this plugin in the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
    }

    /**
     * Export personal data owned by this plugin.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
    }

    /**
     * Delete personal data owned by this plugin for all users in one context.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
    }

    /**
     * Delete personal data owned by this plugin for one user.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
    }

    /**
     * Delete personal data owned by this plugin for several users in one context.
     *
     * @param approved_userlist $userlist The approved users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
    }
}
