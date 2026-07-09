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
 * Plugin version definition for local_instantcoursecompletion.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'local_instantcoursecompletion';
$plugin->version      = 2026070904;
$plugin->requires     = 2024100700;   // Moodle 4.5.0 — hard minimum.
$plugin->supported    = [405, 502];   // Supported range: Moodle 4.5 (405) through 5.2 (502).
$plugin->maturity     = MATURITY_ALPHA;
$plugin->release      = '0.3.0';

// No hard dependencies. Integration with local_adele is OPTIONAL and detected at
// runtime (see classes/scope_resolver.php); it is deliberately NOT declared here so
// the plugin installs and runs stand-alone.
$plugin->dependencies = [];
