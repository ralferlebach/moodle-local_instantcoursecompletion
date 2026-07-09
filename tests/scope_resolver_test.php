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
 * Tests for the scope resolver.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Scope resolver tests.
 *
 * @covers \local_instantcoursecompletion\scope_resolver
 */
final class scope_resolver_test extends \advanced_testcase {
    /**
     * SCOPE_ALL puts every real course in scope but never the site course.
     *
     * @return void
     */
    public function test_scope_all_includes_every_course(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');

        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));
        $this->assertFalse(scope_resolver::is_in_scope((int)SITEID));
        $this->assertFalse(scope_resolver::is_in_scope(0));
    }

    /**
     * SCOPE_CATEGORIES includes the selected branch (incl. sub-categories) only.
     *
     * @return void
     */
    public function test_scope_categories_resolves_subtree(): void {
        $this->resetAfterTest(true);

        $cat1   = $this->getDataGenerator()->create_category();
        $cat2   = $this->getDataGenerator()->create_category();
        $subcat = $this->getDataGenerator()->create_category(['parent' => $cat1->id]);

        $coursein  = $this->getDataGenerator()->create_course(['category' => $subcat->id]);
        $courseout = $this->getDataGenerator()->create_course(['category' => $cat2->id]);

        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', (string)$cat1->id, 'local_instantcoursecompletion');
        scope_resolver::purge_cache();

        $this->assertTrue(scope_resolver::is_in_scope((int)$coursein->id));
        $this->assertFalse(scope_resolver::is_in_scope((int)$courseout->id));
    }

    /**
     * Empty category selection resolves to an empty scope.
     *
     * @return void
     */
    public function test_scope_categories_empty_selection_is_empty(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', '', 'local_instantcoursecompletion');
        scope_resolver::purge_cache();

        $this->assertFalse(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * Include / exclude tag filters restrict the category branch further.
     *
     * @return void
     */
    public function test_scope_categories_tag_filters(): void {
        $this->resetAfterTest(true);

        $cat    = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        \core_tag_tag::set_item_tags(
            'core',
            'course',
            (int)$course->id,
            \context_course::instance((int)$course->id),
            ['keep']
        );

        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', (string)$cat->id, 'local_instantcoursecompletion');

        // Include tag present → in scope.
        set_config('includetags', 'keep', 'local_instantcoursecompletion');
        set_config('excludetags', '', 'local_instantcoursecompletion');
        scope_resolver::purge_cache();
        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));

        // Exclude the same tag → out of scope.
        set_config('includetags', '', 'local_instantcoursecompletion');
        set_config('excludetags', 'keep', 'local_instantcoursecompletion');
        scope_resolver::purge_cache();
        $this->assertFalse(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * SCOPE_ADELE downgrades to SCOPE_ALL when local_adele is not installed.
     *
     * @return void
     */
    public function test_scope_adele_falls_back_without_plugin(): void {
        $this->resetAfterTest(true);

        if (scope_resolver::adele_available()) {
            $this->markTestSkipped('local_adele is installed in this environment.');
        }

        set_config('scopemode', scope_resolver::SCOPE_ADELE, 'local_instantcoursecompletion');
        $this->assertSame(scope_resolver::SCOPE_ALL, scope_resolver::get_mode());
    }
}
