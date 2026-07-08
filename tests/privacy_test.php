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
 * Tests for the privacy provider.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

use local_instantcoursecompletion\privacy\provider;

/**
 * Privacy provider tests.
 *
 * @covers \local_instantcoursecompletion\privacy\provider
 */
final class privacy_test extends \advanced_testcase {
    /**
     * The provider is a null provider and its reason resolves to a real string.
     *
     * @return void
     */
    public function test_null_provider_reason(): void {
        $this->resetAfterTest(true);

        $this->assertInstanceOf(\core_privacy\local\metadata\null_provider::class, new provider());

        $reason = provider::get_reason();
        $this->assertIsString($reason);
        // Must resolve to a defined language string (no missing-string placeholder).
        $this->assertStringNotContainsString('[[', get_string($reason, 'local_instantcoursecompletion'));
    }
}
