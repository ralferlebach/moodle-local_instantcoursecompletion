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
 * Tests for the bounded integer admin setting.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\admin\bounded_int_setting;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Bounded integer setting tests.
 *
 * @covers \local_instantcoursecompletion\admin\bounded_int_setting
 */
final class bounded_int_setting_test extends \advanced_testcase {
    /**
     * Build a setting bounded to 1..100.
     *
     * @return bounded_int_setting
     */
    protected function make_setting(): bounded_int_setting {
        return new bounded_int_setting(
            'local_instantcoursecompletion/testvalue',
            'Test value',
            'Test description',
            50,
            1,
            100
        );
    }

    /**
     * A value inside the range is accepted.
     *
     * @return void
     */
    public function test_validate_accepts_values_in_range(): void {
        $this->resetAfterTest(true);
        $setting = $this->make_setting();

        $this->assertTrue($setting->validate('1'));
        $this->assertTrue($setting->validate('50'));
        $this->assertTrue($setting->validate('100'));
    }

    /**
     * A value below the minimum is rejected.
     *
     * The tasks clamp a non-positive budget to a hard-coded default, so without this the
     * administrator would never learn that the entered value is not the one in force.
     *
     * @return void
     */
    public function test_validate_rejects_values_below_the_minimum(): void {
        $this->resetAfterTest(true);
        $setting = $this->make_setting();

        $this->assertIsString($setting->validate('0'));
        $this->assertIsString($setting->validate('-1'));
    }

    /**
     * A value above the maximum is rejected.
     *
     * @return void
     */
    public function test_validate_rejects_values_above_the_maximum(): void {
        $this->resetAfterTest(true);
        $setting = $this->make_setting();

        $this->assertIsString($setting->validate('101'));
        $this->assertIsString($setting->validate('50000000'));
    }

    /**
     * A non-integer is rejected by the inherited PARAM_INT check.
     *
     * @return void
     */
    public function test_validate_rejects_non_integers(): void {
        $this->resetAfterTest(true);
        $setting = $this->make_setting();

        $this->assertIsString($setting->validate('abc'));
        $this->assertIsString($setting->validate('1.5'));
    }
}
