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
     * @param int $courseid Course ID to test.
     * @return bool
     */
    public static function is_in_scope(int $courseid): bool {
        if ($courseid <= 0 || $courseid == SITEID) {
            return false;
        }
        if (self::get_mode() === self::SCOPE_ALL) {
            return true;
        }
        return isset(self::get_scope_course_ids()[$courseid]);
    }

    /**
     * Return the resolved set of in-scope course IDs as a map of id to true.
     *
     * Empty for SCOPE_ALL; callers use is_in_scope(), which short-circuits that mode.
     *
     * @return array<int, bool>
     */
    public static function get_scope_course_ids(): array {
        $mode = self::get_mode();
        if ($mode === self::SCOPE_ALL) {
            return [];
        }
        if ($mode === self::SCOPE_ADELE && !self::adele_available()) {
            return [];
        }

        $filter = self::filter_definition($mode);
        $cache = \cache::make(self::COMPONENT, 'scopecourseids');
        $key = sha1(serialize([$mode, $filter]));

        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        $set = self::build_course_id_set($filter[0], $filter[1], $filter[2]);
        $cache->set($key, $set);
        return $set;
    }

    /**
     * Purge the resolved-scope cache.
     *
     * @return void
     */
    public static function purge_cache(): void {
        \cache::make(self::COMPONENT, 'scopecourseids')->purge();
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
     * Build the set of in-scope course IDs for the given filter definition.
     *
     * @param int[]    $categoryids Selected category IDs; sub-categories are resolved here.
     * @param string[] $includetags Course must carry at least one of these tags, if any.
     * @param string[] $excludetags Course must carry none of these tags.
     * @return array<int, bool> Map of course ID to true.
     */
    protected static function build_course_id_set(array $categoryids, array $includetags, array $excludetags): array {
        global $DB;

        $catids = self::resolve_category_subtree($categoryids);
        if (empty($catids)) {
            return [];
        }

        $includeids = self::resolve_tag_ids($includetags);
        if (!empty($includetags) && empty($includeids)) {
            // The filter names only tags that do not exist, so nothing can match it.
            return [];
        }
        $excludeids = self::resolve_tag_ids($excludetags);

        [$catinsql, $params] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
        $params['siteid'] = SITEID;
        $where = ['c.id <> :siteid', "c.category $catinsql"];

        if (!empty($includeids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($includeids, SQL_PARAMS_NAMED, 'itag');
            $where[] = "EXISTS (SELECT 1 FROM {tag_instance} ti
                                 WHERE ti.component = 'core' AND ti.itemtype = 'course'
                                   AND ti.itemid = c.id AND ti.tagid $insql)";
            $params += $inparams;
        }

        if (!empty($excludeids)) {
            [$exsql, $exparams] = $DB->get_in_or_equal($excludeids, SQL_PARAMS_NAMED, 'xtag');
            $where[] = "NOT EXISTS (SELECT 1 FROM {tag_instance} tx
                                     WHERE tx.component = 'core' AND tx.itemtype = 'course'
                                       AND tx.itemid = c.id AND tx.tagid $exsql)";
            $params += $exparams;
        }

        $sql = 'SELECT c.id FROM {course} c WHERE ' . implode(' AND ', $where);
        return array_fill_keys(array_map('intval', $DB->get_fieldset_sql($sql, $params)), true);
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

        $categoryids = array_values(array_unique(array_filter(array_map('intval', $categoryids))));
        if (empty($categoryids)) {
            return [];
        }

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
        $ids = [];
        foreach ($names as $name) {
            $tag = \core_tag_tag::get_by_name($collectionid, $name, 'id');
            if ($tag) {
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
