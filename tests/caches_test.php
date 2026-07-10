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
 * Consistency test between db/caches.php and the language file.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Every declared cache must carry its display string.
 *
 * A definition added to db/caches.php without the matching cachedef_* string emits a
 * runtime debugging notice the moment the admin cache page is opened; this catches it at
 * test time instead. It is the check that would have flagged the missing scopetagids
 * string.
 *
 * @coversNothing
 */
final class caches_test extends \advanced_testcase {
    /**
     * Each cache definition has a cachedef_<name> string in lang/en.
     *
     * @return void
     */
    public function test_every_cache_definition_has_a_language_string(): void {
        global $CFG;

        $base = $CFG->dirroot . '/local/instantcoursecompletion';

        $definitions = [];
        require($base . '/db/caches.php');

        $string = [];
        require($base . '/lang/en/local_instantcoursecompletion.php');

        $this->assertNotEmpty($definitions, 'db/caches.php declares no cache definitions.');

        foreach (array_keys($definitions) as $name) {
            $this->assertArrayHasKey(
                'cachedef_' . $name,
                $string,
                "Cache '{$name}' in db/caches.php has no cachedef_{$name} string in lang/en."
            );
        }
    }
}
