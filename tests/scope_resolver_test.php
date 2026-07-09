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
     * Reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Configure the categories scope and drop the cached resolution.
     *
     * @param string $categories Comma-separated category IDs.
     * @param string $includetags Include tag list.
     * @param string $excludetags Exclude tag list.
     * @return void
     */
    protected function configure_categories_scope(string $categories, string $includetags = '', string $excludetags = ''): void {
        set_config('scopemode', scope_resolver::SCOPE_CATEGORIES, 'local_instantcoursecompletion');
        set_config('categories', $categories, 'local_instantcoursecompletion');
        set_config('includetags', $includetags, 'local_instantcoursecompletion');
        set_config('excludetags', $excludetags, 'local_instantcoursecompletion');
        scope_resolver::purge_cache();
    }

    /**
     * Tag a course through the tag API.
     *
     * @param \stdClass $course Course record.
     * @param string[]  $tags   Raw tag names.
     * @return void
     */
    protected function tag_course(\stdClass $course, array $tags): void {
        \core_tag_tag::set_item_tags(
            'core',
            'course',
            (int)$course->id,
            \context_course::instance((int)$course->id),
            $tags
        );
    }

    /**
     * SCOPE_ALL puts every real course in scope but never the site course.
     *
     * @return void
     */
    public function test_scope_all_includes_every_course(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('scopemode', scope_resolver::SCOPE_ALL, 'local_instantcoursecompletion');

        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));
        $this->assertFalse(scope_resolver::is_in_scope((int)SITEID));
        $this->assertFalse(scope_resolver::is_in_scope(0));
    }

    /**
     * A selected branch includes its own courses and those of its sub-categories.
     *
     * @return void
     */
    public function test_scope_categories_resolves_subtree(): void {
        $cat1 = $this->getDataGenerator()->create_category();
        $cat2 = $this->getDataGenerator()->create_category();
        $subcat = $this->getDataGenerator()->create_category(['parent' => $cat1->id]);

        $coursedirect = $this->getDataGenerator()->create_course(['category' => $cat1->id]);
        $coursenested = $this->getDataGenerator()->create_course(['category' => $subcat->id]);
        $courseout = $this->getDataGenerator()->create_course(['category' => $cat2->id]);

        $this->configure_categories_scope((string)$cat1->id);

        $this->assertTrue(scope_resolver::is_in_scope((int)$coursedirect->id));
        $this->assertTrue(scope_resolver::is_in_scope((int)$coursenested->id));
        $this->assertFalse(scope_resolver::is_in_scope((int)$courseout->id));
    }

    /**
     * A selected sub-category includes only its own branch, not its parent.
     *
     * @return void
     */
    public function test_scope_categories_selected_subcategory(): void {
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $grandchild = $this->getDataGenerator()->create_category(['parent' => $child->id]);

        $courseparent = $this->getDataGenerator()->create_course(['category' => $parent->id]);
        $coursechild = $this->getDataGenerator()->create_course(['category' => $child->id]);
        $coursegrandchild = $this->getDataGenerator()->create_course(['category' => $grandchild->id]);

        $this->configure_categories_scope((string)$child->id);

        $this->assertFalse(scope_resolver::is_in_scope((int)$courseparent->id));
        $this->assertTrue(scope_resolver::is_in_scope((int)$coursechild->id));
        $this->assertTrue(scope_resolver::is_in_scope((int)$coursegrandchild->id));
    }

    /**
     * Empty category selection resolves to an empty scope.
     *
     * @return void
     */
    public function test_scope_categories_empty_selection_is_empty(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->configure_categories_scope('');

        $this->assertFalse(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * Include and exclude tag filters restrict the category branch further.
     *
     * @return void
     */
    public function test_scope_categories_tag_filters(): void {
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $this->tag_course($course, ['keep']);

        $this->configure_categories_scope((string)$cat->id, 'keep', '');
        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));

        $this->configure_categories_scope((string)$cat->id, '', 'keep');
        $this->assertFalse(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * Tag matching follows Moodle's normalisation rather than the stored raw name.
     *
     * @return void
     */
    public function test_scope_categories_tag_filter_is_case_insensitive(): void {
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $this->tag_course($course, ['Pflichtkurs']);

        $this->configure_categories_scope((string)$cat->id, 'pflichtkurs', '');
        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));

        $this->configure_categories_scope((string)$cat->id, 'PFLICHTKURS', '');
        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * Tags may be given one per line as well as comma separated.
     *
     * @return void
     */
    public function test_scope_categories_tag_filter_accepts_newlines(): void {
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $this->tag_course($course, ['second']);

        $this->configure_categories_scope((string)$cat->id, "first\nsecond", '');
        $this->assertTrue(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * An include filter naming only unknown tags yields an empty scope.
     *
     * @return void
     */
    public function test_scope_categories_unknown_include_tag_is_empty(): void {
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);

        $this->configure_categories_scope((string)$cat->id, 'doesnotexist', '');
        $this->assertFalse(scope_resolver::is_in_scope((int)$course->id));
    }

    /**
     * A configured adele scope yields an empty scope when local_adele is absent.
     *
     * @return void
     */
    public function test_scope_adele_is_empty_without_plugin(): void {
        if (scope_resolver::adele_available()) {
            $this->markTestSkipped('local_adele is installed in this environment.');
        }

        $course = $this->getDataGenerator()->create_course();
        set_config('scopemode', scope_resolver::SCOPE_ADELE, 'local_instantcoursecompletion');
        scope_resolver::purge_cache();

        $this->assertSame(scope_resolver::SCOPE_ADELE, scope_resolver::get_mode());
        $this->assertFalse(scope_resolver::is_in_scope((int)$course->id));
    }
}
