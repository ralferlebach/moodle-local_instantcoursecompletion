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

    $ADMIN->add('reports', new admin_externalpage(
        $component . '_report',
        get_string('report:title', $component),
        new moodle_url('/local/instantcoursecompletion/report.php'),
        'moodle/site:config'
    ));

    // Building the category list is expensive, so only do it when the page is rendered.
    if ($ADMIN->fulltree) {
        $purgecallback = 'local_instantcoursecompletion_purge_scope_cache';

        $scopeoptions = [
            scope_resolver::SCOPE_ALL => get_string('scope:all', $component),
            scope_resolver::SCOPE_CATEGORIES => get_string('scope:categories', $component),
        ];
        if (scope_resolver::adele_available()) {
            $scopeoptions[scope_resolver::SCOPE_ADELE] = get_string('scope:adele', $component);
        } else if (get_config($component, 'scopemode') === scope_resolver::SCOPE_ADELE) {
            // The scope was configured against a plugin that is no longer present.
            $settings->add(new admin_setting_description(
                $component . '/adelemissing',
                '',
                $OUTPUT->notification(get_string('warning:adelemissing', $component), 'warning')
            ));
        }

        $setting = new admin_setting_configselect(
            $component . '/scopemode',
            get_string('setting:scopemode', $component),
            get_string('setting:scopemode_desc', $component),
            scope_resolver::SCOPE_ALL,
            $scopeoptions
        );
        $setting->set_updatedcallback($purgecallback);
        $settings->add($setting);

        $setting = new admin_setting_configmultiselect(
            $component . '/categories',
            get_string('setting:categories', $component),
            get_string('setting:categories_desc', $component),
            [],
            core_course_category::make_categories_list()
        );
        $setting->set_updatedcallback($purgecallback);
        $settings->add($setting);
        $settings->hide_if($component . '/categories', $component . '/scopemode', 'neq', scope_resolver::SCOPE_CATEGORIES);

        $setting = new admin_setting_configtextarea(
            $component . '/includetags',
            get_string('setting:includetags', $component),
            get_string('setting:includetags_desc', $component),
            '',
            PARAM_TEXT
        );
        $setting->set_updatedcallback($purgecallback);
        $settings->add($setting);
        $settings->hide_if($component . '/includetags', $component . '/scopemode', 'neq', scope_resolver::SCOPE_CATEGORIES);

        $setting = new admin_setting_configtextarea(
            $component . '/excludetags',
            get_string('setting:excludetags', $component),
            get_string('setting:excludetags_desc', $component),
            '',
            PARAM_TEXT
        );
        $setting->set_updatedcallback($purgecallback);
        $settings->add($setting);
        $settings->hide_if($component . '/excludetags', $component . '/scopemode', 'neq', scope_resolver::SCOPE_CATEGORIES);

        $settings->add(new admin_setting_configcheckbox(
            $component . '/schedulingenabled',
            get_string('setting:schedulingenabled', $component),
            get_string('setting:schedulingenabled_desc', $component),
            1
        ));

        $settings->add(new admin_setting_configduration(
            $component . '/schedulinghorizon',
            get_string('setting:schedulinghorizon', $component),
            get_string('setting:schedulinghorizon_desc', $component),
            WEEKSECS
        ));
        $settings->hide_if($component . '/schedulinghorizon', $component . '/schedulingenabled', 'notchecked');

        $settings->add(new admin_setting_configtext(
            $component . '/batchsize',
            get_string('setting:batchsize', $component),
            get_string('setting:batchsize_desc', $component),
            500,
            PARAM_INT
        ));
        $settings->hide_if($component . '/batchsize', $component . '/schedulingenabled', 'notchecked');

        $settings->add(new admin_setting_configtext(
            $component . '/maxtasksperrun',
            get_string('setting:maxtasksperrun', $component),
            get_string('setting:maxtasksperrun_desc', $component),
            5000,
            PARAM_INT
        ));
        $settings->hide_if($component . '/maxtasksperrun', $component . '/schedulingenabled', 'notchecked');

        $settings->add(new admin_setting_configcheckbox(
            $component . '/reconcile_enabled',
            get_string('setting:reconcile', $component),
            get_string('setting:reconcile_desc', $component),
            0
        ));

        $settings->add(new admin_setting_configtext(
            $component . '/reconcilebudget',
            get_string('setting:reconcilebudget', $component),
            get_string('setting:reconcilebudget_desc', $component),
            5000,
            PARAM_INT
        ));
        $settings->hide_if($component . '/reconcilebudget', $component . '/reconcile_enabled', 'notchecked');

        $settings->add(new admin_setting_configcheckbox(
            $component . '/enablelogging',
            get_string('setting:enablelogging', $component),
            get_string('setting:enablelogging_desc', $component),
            0
        ));
    }
}
