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
 * Resolves which courses the completion observers act on.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_instantcoursecompletion;

/**
 * Scope resolver.
 */
class scope_resolver {
    /** @var string Scope: all courses on the instance. */
    public const SCOPE_ALL = 'all';

    /** @var string Scope: selected category branches plus optional tag filters. */
    public const SCOPE_CATEGORIES = 'categories';

    /** @var string Scope: delegate the filter definition to local_adele. */
    public const SCOPE_ADELE = 'adele';

    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /**
     * Whether local_adele is installed.
     *
     * @return bool
     */
    public static function adele_available(): bool {
        return (bool)\core_component::get_component_directory('local_adele');
    }

    /**
     * Return the effective scope mode from configuration.
     *
     * A configured adele scope resolves to an empty scope when local_adele is absent,
     * because widening it to every course would silently exceed the administrator's
     * intent.
     *
     * @return string One of the SCOPE_* constants.
     */
    public static function get_mode(): string {
        $mode = get_config(self::COMPONENT, 'scopemode');
        if ($mode === false || $mode === '') {
            return self::SCOPE_ALL;
        }
        return (string)$mode;
    }

    /**
     * Is the given course within the configured observer scope?
     *
     * The answer is cached per course rather than derived from a materialised list of
     * every in-scope course, so the cached payload stays proportional to the courses
     * actually touched instead of to the size of the site.
     *
     * @param int $courseid Course ID to test.
     * @return bool
     */
    public static function is_in_scope(int $courseid): bool {
        if ($courseid <= 0 || $courseid == SITEID) {
            return false;
        }

        $mode = self::get_mode();
        if ($mode === self::SCOPE_ALL) {
            return true;
        }
        if ($mode === self::SCOPE_ADELE && !self::adele_available()) {
            return false;
        }

        $filter = self::filter_definition($mode);
        $cache = \cache::make(self::COMPONENT, 'scopecoursemembership');
        $key = self::filter_hash($mode, $filter) . '_' . $courseid;

        // Membership is stored as 0 or 1 so that a cached "not in scope" is
        // distinguishable from a cache miss, which also yields false.
        $cached = $cache->get($key);
        if ($cached !== false) {
            return (bool)$cached;
        }

        $inscope = self::evaluate_membership($courseid, $mode, $filter);
        $cache->set($key, $inscope ? 1 : 0);
        return $inscope;
    }

    /**
     * Purge both scope caches.
     *
     * @return void
     */
    public static function purge_cache(): void {
        \cache::make(self::COMPONENT, 'scopecategoryids')->purge();
        \cache::make(self::COMPONENT, 'scopecoursemembership')->purge();
        \cache::make(self::COMPONENT, 'scopetagids')->purge();
    }

    /**
     * Purge the cached membership of a single course.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    public static function purge_course(int $courseid): void {
        if ($courseid <= 0) {
            return;
        }

        $mode = self::get_mode();
        if ($mode === self::SCOPE_ALL) {
            return;
        }

        $key = self::filter_hash($mode, self::filter_definition($mode)) . '_' . $courseid;
        \cache::make(self::COMPONENT, 'scopecoursemembership')->delete($key);
    }

    /**
     * Cache key prefix identifying the current scope configuration.
     *
     * @param string $mode   Effective scope mode.
     * @param array  $filter Filter definition.
     * @return string A hexadecimal hash, safe for a simplekeys cache.
     */
    protected static function filter_hash(string $mode, array $filter): string {
        return sha1(serialize([$mode, $filter]));
    }

    /**
     * Decide membership for one course against the filter definition.
     *
     * @param int    $courseid Course ID.
     * @param string $mode     Effective scope mode, for the tag-ID cache key.
     * @param array  $filter   Filter definition.
     * @return bool
     */
    protected static function evaluate_membership(int $courseid, string $mode, array $filter): bool {
        [$categoryids, $includetags, $excludetags] = $filter;

        $categoryset = self::scope_category_ids($categoryids);
        if (empty($categoryset)) {
            return false;
        }

        try {
            $course = get_course($courseid);
        } catch (\dml_exception $e) {
            return false;
        }

        if (!isset($categoryset[(int)$course->category])) {
            return false;
        }

        [$includeids, $excludeids] = self::scope_tag_ids($mode, $filter, $includetags, $excludetags);

        return self::course_matches_tags($courseid, $includeids, $excludeids, !empty($includetags));
    }

    /**
     * Does the course satisfy the include and exclude tag filters?
     *
     * @param int   $courseid       Course ID.
     * @param int[] $includeids     Course must carry at least one of these tag IDs, if any were named.
     * @param int[] $excludeids     Course must carry none of these tag IDs.
     * @param bool  $includenamed   Whether the include filter named any tags at all.
     * @return bool
     */
    protected static function course_matches_tags(int $courseid, array $includeids, array $excludeids, bool $includenamed): bool {
        if (empty($includeids) && empty($excludeids)) {
            // Either no filter is configured, or the include filter names only tags
            // that do not exist, in which case nothing can match it.
            return !$includenamed;
        }

        $coursetagids = array_map('intval', array_keys(
            \core_tag_tag::get_item_tags_array('core', 'course', $courseid, \core_tag_tag::BOTH_STANDARD_AND_NOT, 0, false)
        ));

        if (!empty($excludeids) && array_intersect($coursetagids, $excludeids)) {
            return false;
        }
        if (!empty($includeids) && !array_intersect($coursetagids, $includeids)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve and cache the tag IDs for the current filter, once per configuration.
     *
     * Without this, a run that checks many courses under a cache miss would resolve
     * the same handful of tag names again for every single course.
     *
     * @param string   $mode        Effective scope mode.
     * @param array    $filter      Filter definition, for the cache key.
     * @param string[] $includetags Include tag names.
     * @param string[] $excludetags Exclude tag names.
     * @return array{0: int[], 1: int[]} Include tag IDs, exclude tag IDs.
     */
    protected static function scope_tag_ids(string $mode, array $filter, array $includetags, array $excludetags): array {
        if (empty($includetags) && empty($excludetags)) {
            return [[], []];
        }

        $cache = \cache::make(self::COMPONENT, 'scopetagids');
        $key = self::filter_hash($mode, $filter);

        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        $ids = [self::resolve_tag_ids($includetags), self::resolve_tag_ids($excludetags)];
        $cache->set($key, $ids);
        return $ids;
    }

    /**
     * The selected categories together with all of their sub-categories.
     *
     * The result is cached: it is bounded by the number of categories on the site,
     * not by the number of courses, and changes only when the category tree does.
     *
     * @param int[] $categoryids Selected category IDs.
     * @return array<int, bool> Map of category ID to true.
     */
    protected static function scope_category_ids(array $categoryids): array {
        $categoryids = array_values(array_unique(array_filter(array_map('intval', $categoryids))));
        if (empty($categoryids)) {
            return [];
        }
        sort($categoryids);

        $cache = \cache::make(self::COMPONENT, 'scopecategoryids');
        $key = sha1(serialize($categoryids));

        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        $set = array_fill_keys(self::resolve_category_subtree($categoryids), true);
        $cache->set($key, $set);
        return $set;
    }

    /**
     * Resolve the filter definition for the given mode.
     *
     * @param string $mode Effective scope mode.
     * @return array{0: int[], 1: string[], 2: string[]} Category IDs, include tags, exclude tags.
     */
    protected static function filter_definition(string $mode): array {
        $source = ($mode === self::SCOPE_ADELE) ? 'local_adele' : self::COMPONENT;
        $categorykey = ($mode === self::SCOPE_ADELE) ? 'catfilter' : 'categories';

        return [
            self::csv_to_ints(get_config($source, $categorykey)),
            self::csv_to_strings(get_config($source, 'includetags')),
            self::csv_to_strings(get_config($source, 'excludetags')),
        ];
    }

    /**
     * Expand the selected categories to include all of their sub-categories.
     *
     * Descendants are matched by a prefix comparison against the selected category's
     * own path, which keeps the LIKE anchored and therefore index-usable.
     *
     * @param int[] $categoryids Selected category IDs.
     * @return int[] All category IDs in the selected branches.
     */
    protected static function resolve_category_subtree(array $categoryids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cid');
        $paths = $DB->get_records_select_menu('course_categories', "id $insql", $params, '', 'id, path');
        if (empty($paths)) {
            return [];
        }

        $likes = [];
        foreach ($paths as $id => $path) {
            $key = 'path' . $id;
            $likes[] = $DB->sql_like('path', ':' . $key, true, true, false);
            $params[$key] = $DB->sql_like_escape($path) . '/%';
        }

        $sql = "SELECT id FROM {course_categories} WHERE id $insql OR " . implode(' OR ', $likes);
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Resolve course tag names to tag IDs using the tag API.
     *
     * Going through the API applies Moodle's tag normalisation and restricts the
     * lookup to the collection the course tag area belongs to.
     *
     * @param string[] $names Raw tag names from configuration.
     * @return int[] IDs of the tags that exist.
     */
    protected static function resolve_tag_ids(array $names): array {
        if (empty($names)) {
            return [];
        }

        $collectionid = \core_tag_area::get_collection('core', 'course');
        // The bulk lookup indexes its result by $record->name, so name must be selected.
        $tags = \core_tag_tag::get_by_name_bulk($collectionid, $names, 'id, name');

        $ids = [];
        foreach ($tags as $tag) {
            if ($tag !== null) {
                $ids[] = (int)$tag->id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Parse a separated list into an array of positive integers.
     *
     * @param mixed $value Raw config value.
     * @return int[]
     */
    protected static function csv_to_ints($value): array {
        $ints = [];
        foreach (self::split_list($value) as $part) {
            if (ctype_digit($part)) {
                $ints[] = (int)$part;
            }
        }
        return $ints;
    }

    /**
     * Parse a separated list into an array of non-empty trimmed strings.
     *
     * @param mixed $value Raw config value.
     * @return string[]
     */
    protected static function csv_to_strings($value): array {
        return self::split_list($value);
    }

    /**
     * Split a config value on commas and line breaks.
     *
     * Textarea settings store one entry per line, per comma, or both.
     *
     * @param mixed $value Raw config value.
     * @return string[]
     */
    protected static function split_list($value): array {
        if ($value === false || $value === null || $value === '') {
            return [];
        }
        $parts = array_map('trim', preg_split('/[\r\n,]+/', (string)$value));
        return array_values(array_filter($parts, static fn($part) => $part !== ''));
    }
}
