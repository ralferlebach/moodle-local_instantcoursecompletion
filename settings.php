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
 * Admin settings for local_instantcoursecompletion.
 *
 * Defines the observer scope (all courses / selected category branches / delegated
 * to local_adele) plus the processing mode (async ad-hoc task vs. synchronous) and
 * an optional logging toggle.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_instantcoursecompletion\scope_resolver;

if ($hassiteconfig) {
    $component = 'local_instantcoursecompletion';

    $settings = new admin_settingpage(
        'local_instantcoursecompletion_settings',
        get_string('pluginname', $component)
    );
    $ADMIN->add('localplugins', $settings);

    // Scope mode.
    // The "adele" option is only offered when local_adele is installed.
    $scopeoptions = [
        scope_resolver::SCOPE_ALL          => get_string('scope:all', $component),
        scope_resolver::SCOPE_CATEGORIES   => get_string('scope:categories', $component),
    ];
    if (scope_resolver::adele_available()) {
        $scopeoptions[scope_resolver::SCOPE_ADELE] = get_string('scope:adele', $component);
    }

    $settings->add(new admin_setting_configselect(
        $component . '/scopemode',
        get_string('setting:scopemode', $component),
        get_string('setting:scopemode_desc', $component),
        scope_resolver::SCOPE_ALL,
        $scopeoptions
    ));

    // Category branches (only relevant for the "categories" scope).
    $categories = core_course_category::make_categories_list();
    $settings->add(new admin_setting_configmultiselect(
        $component . '/categories',
        get_string('setting:categories', $component),
        get_string('setting:categories_desc', $component),
        [],
        $categories
    ));
    $settings->hide_if($component . '/categories', $component . '/scopemode', 'neq', scope_resolver::SCOPE_CATEGORIES);

    // Include / exclude tags (only relevant for the "categories" scope).
    $settings->add(new admin_setting_configtextarea(
        $component . '/includetags',
        get_string('setting:includetags', $component),
        get_string('setting:includetags_desc', $component),
        '',
        PARAM_TEXT
    ));
    $settings->hide_if($component . '/includetags', $component . '/scopemode', 'neq', scope_resolver::SCOPE_CATEGORIES);

    $settings->add(new admin_setting_configtextarea(
        $component . '/excludetags',
        get_string('setting:excludetags', $component),
        get_string('setting:excludetags_desc', $component),
        '',
        PARAM_TEXT
    ));
    $settings->hide_if($component . '/excludetags', $component . '/scopemode', 'neq', scope_resolver::SCOPE_CATEGORIES);

    // Processing mode.
    $settings->add(new admin_setting_configselect(
        $component . '/processingmode',
        get_string('setting:processingmode', $component),
        get_string('setting:processingmode_desc', $component),
        'async',
        [
            'async' => get_string('processingmode:async', $component),
            'sync'  => get_string('processingmode:sync', $component),
        ]
    ));

    // Optional safety-net reconcile scheduled task.
    $settings->add(new admin_setting_configcheckbox(
        $component . '/reconcile_enabled',
        get_string('setting:reconcile', $component),
        get_string('setting:reconcile_desc', $component),
        0
    ));

    // Logging.
    $settings->add(new admin_setting_configcheckbox(
        $component . '/enablelogging',
        get_string('setting:enablelogging', $component),
        get_string('setting:enablelogging_desc', $component),
        0
    ));

    // Report page: accelerated completions visible in logstore.
    $ADMIN->add('reports', new admin_externalpage(
        $component . '_report',
        get_string('report:title', $component),
        new moodle_url('/local/instantcoursecompletion/report.php'),
        'moodle/site:config'
    ));

    // Any settings change may alter the resolved scope — purge the cache.
    foreach (['scopemode', 'categories', 'includetags', 'excludetags'] as $name) {
        if (isset($settings->settings->{$name})) {
            $settings->settings->{$name}->set_updatedcallback('local_instantcoursecompletion_purge_scope_cache');
        }
    }
}
