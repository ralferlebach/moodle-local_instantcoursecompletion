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
 * Three modes:
 *   - all         : every course on the instance (site course excluded).
 *   - categories  : selected category branches (incl. sub-categories) plus optional
 *                   include / exclude course-tag filters.
 *   - adele       : delegates the filter definition to local_adele's settings
 *                   (catfilter / includetags / excludetags) when that plugin is
 *                   installed, keeping both plugins' notion of "relevant courses"
 *                   in sync.
 *
 * The resolved set of in-scope course IDs is cached in a MUC application cache and
 * invalidated on any structural change (see db/events.php) or settings change
 * (see lib.php). The synchronous request path only ever performs a cache lookup.
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

    /** @var string Scope: selected category branches (+ optional tag filters). */
    public const SCOPE_CATEGORIES = 'categories';

    /** @var string Scope: delegate the filter definition to local_adele. */
    public const SCOPE_ADELE = 'adele';

    /** @var string Frankenstyle component name. */
    protected const COMPONENT = 'local_instantcoursecompletion';

    /**
     * Whether local_adele is installed (enables the "adele" scope option).
     *
     * @return bool
     */
    public static function adele_available(): bool {
        return (bool)\core_component::get_component_directory('local_adele');
    }

    /**
     * Return the effective scope mode from configuration.
     *
     * Falls back to SCOPE_ALL, and downgrades SCOPE_ADELE to SCOPE_ALL if
     * local_adele is not (or no longer) installed.
     *
     * @return string One of the SCOPE_* constants.
     */
    public static function get_mode(): string {
        $mode = get_config(self::COMPONENT, 'scopemode');
        if ($mode === false || $mode === '') {
            return self::SCOPE_ALL;
        }
        if ($mode === self::SCOPE_ADELE && !self::adele_available()) {
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
        $set = self::get_scope_course_ids();
        return isset($set[$courseid]);
    }

    /**
     * Return the resolved set of in-scope course IDs as a map (id => true).
     *
     * For SCOPE_ALL this returns an empty map — callers should use is_in_scope(),
     * which short-circuits SCOPE_ALL without materialising every course ID.
     *
     * @return array<int, bool>
     */
    public static function get_scope_course_ids(): array {
        $mode = self::get_mode();
        if ($mode === self::SCOPE_ALL) {
            return [];
        }

        $cache = \cache::make(self::COMPONENT, 'scopecourseids');
        $key   = self::cache_key($mode);

        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        [$categoryids, $includetags, $excludetags] = self::filter_definition($mode);
        $set = self::build_course_id_set($categoryids, $includetags, $excludetags);

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
     * Build the cache key from the scope-relevant configuration.
     *
     * @param string $mode Effective scope mode.
     * @return string
     */
    protected static function cache_key(string $mode): string {
        [$categoryids, $includetags, $excludetags] = self::filter_definition($mode);
        return sha1(serialize([$mode, $categoryids, $includetags, $excludetags]));
    }

    /**
     * Resolve the filter definition (categories + tags) for the given mode.
     *
     * @param string $mode Effective scope mode.
     * @return array{0: int[], 1: string[], 2: string[]} [categoryids, includetags, excludetags]
     */
    protected static function filter_definition(string $mode): array {
        if ($mode === self::SCOPE_ADELE) {
            $categoryids = self::csv_to_ints(get_config('local_adele', 'catfilter'));
            $includetags = self::csv_to_strings(get_config('local_adele', 'includetags'));
            $excludetags = self::csv_to_strings(get_config('local_adele', 'excludetags'));
            return [$categoryids, $includetags, $excludetags];
        }

        // SCOPE_CATEGORIES (own configuration).
        $categoryids = self::csv_to_ints(get_config(self::COMPONENT, 'categories'));
        $includetags = self::csv_to_strings(get_config(self::COMPONENT, 'includetags'));
        $excludetags = self::csv_to_strings(get_config(self::COMPONENT, 'excludetags'));
        return [$categoryids, $includetags, $excludetags];
    }

    /**
     * Build the set of in-scope course IDs for the given filter definition.
     *
     * @param int[]    $categoryids Selected category IDs (sub-categories resolved here).
     * @param string[] $includetags Course must carry at least one of these tags (if any).
     * @param string[] $excludetags Course must carry none of these tags.
     * @return array<int, bool> Map of course ID => true.
     */
    protected static function build_course_id_set(array $categoryids, array $includetags, array $excludetags): array {
        global $DB;

        $catids = self::resolve_category_subtree($categoryids);
        if (empty($catids)) {
            return [];
        }

        [$catinsql, $params] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
        $where = ['c.id <> :siteid', "c.category $catinsql"];
        $params['siteid'] = SITEID;

        if (!empty($includetags)) {
            [$insql, $inparams] = $DB->get_in_or_equal($includetags, SQL_PARAMS_NAMED, 'itag');
            $where[] = "EXISTS (SELECT 1 FROM {tag_instance} ti
                                  JOIN {tag} t ON t.id = ti.tagid
                                 WHERE ti.itemtype = 'course' AND ti.itemid = c.id AND t.rawname $insql)";
            $params += $inparams;
        }

        if (!empty($excludetags)) {
            [$exsql, $exparams] = $DB->get_in_or_equal($excludetags, SQL_PARAMS_NAMED, 'xtag');
            $where[] = "NOT EXISTS (SELECT 1 FROM {tag_instance} ti2
                                      JOIN {tag} t2 ON t2.id = ti2.tagid
                                     WHERE ti2.itemtype = 'course' AND ti2.itemid = c.id AND t2.rawname $exsql)";
            $params += $exparams;
        }

        $sql = 'SELECT c.id FROM {course} c WHERE ' . implode(' AND ', $where);
        $ids = $DB->get_fieldset_sql($sql, $params);

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Expand the selected categories to include all of their sub-categories.
     *
     * Matches on course_categories.path, which contains the ID of every ancestor
     * (and the category itself), e.g. "/3/17/". A selected parent therefore also
     * matches all of its descendants.
     *
     * @param int[] $categoryids Selected category IDs.
     * @return int[] All category IDs in the selected branches.
     */
    protected static function resolve_category_subtree(array $categoryids): array {
        global $DB;

        $categoryids = array_values(array_filter(array_map('intval', $categoryids)));
        if (empty($categoryids)) {
            return [];
        }

        $likes = [];
        $params = [];
        foreach ($categoryids as $i => $cid) {
            $key = 'p' . $i;
            $likes[] = $DB->sql_like('path', ':' . $key);
            $params[$key] = '%/' . $cid . '/%';
        }

        $sql = 'SELECT id FROM {course_categories} WHERE ' . implode(' OR ', $likes);
        $ids = $DB->get_fieldset_sql($sql, $params);

        // Union with the explicitly selected IDs as a defensive fallback.
        return array_values(array_unique(array_merge(array_map('intval', $ids), $categoryids)));
    }

    /**
     * Parse a comma-separated list into an array of positive integers.
     *
     * @param mixed $value Raw config value.
     * @return int[]
     */
    protected static function csv_to_ints($value): array {
        if ($value === false || $value === null || $value === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', (string)$value));
        $ints = [];
        foreach ($parts as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $ints[] = (int)$part;
            }
        }
        return $ints;
    }

    /**
     * Parse a comma-separated list into an array of non-empty trimmed strings.
     *
     * @param mixed $value Raw config value.
     * @return string[]
     */
    protected static function csv_to_strings($value): array {
        if ($value === false || $value === null || $value === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', (string)$value));
        return array_values(array_filter($parts, static fn($p) => $p !== ''));
    }
}
