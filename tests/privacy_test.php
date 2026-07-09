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

use core_privacy\local\metadata\collection;
use local_instantcoursecompletion\privacy\provider;

/**
 * Privacy provider tests.
 *
 * @covers \local_instantcoursecompletion\privacy\provider
 */
final class privacy_test extends \advanced_testcase {
    /**
     * The provider declares the logging subsystem and nothing else.
     *
     * @return void
     */
    public function test_get_metadata_links_the_log_subsystem(): void {
        $this->resetAfterTest(true);

        $collection = provider::get_metadata(new collection('local_instantcoursecompletion'));
        $items = $collection->get_collection();

        $this->assertCount(1, $items);
        $this->assertInstanceOf(\core_privacy\local\metadata\types\subsystem_link::class, reset($items));
        $this->assertSame('core_log', reset($items)->get_name());
    }

    /**
     * The plugin owns no contexts of its own.
     *
     * @return void
     */
    public function test_no_contexts_are_owned(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->assertCount(0, provider::get_contexts_for_userid((int)$user->id)->get_contextids());
    }
}
