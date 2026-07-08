# Session 001 — Initial stub with full infrastructure

## Goal
Create `local_instantcoursecompletion` as an initial stub **with complete
infrastructure**, targeting Moodle **4.5 and higher (incl. 5.x)**, reusing the
infrastructure conventions from the `block_catquiz_statistics` reference plugin.

## Fixed decisions
- **Lesart A**: no new completion logic — observer-based, scoped, earlier evaluation
  of Moodle's existing course-completion criteria only.
- **L-Q2**: minimum Moodle 4.5; no 4.1–4.4 backward compatibility.

## Delivered
- Plugin skeleton: `version.php`, `settings.php`, `lib.php`.
- `db/`: `events.php` (completion triggers + cache-invalidation), `caches.php`
  (scope cache), `tasks.php` (reconcile safety net).
- `classes/`: `observer` (thin callbacks), `scope_resolver` (all/categories/adele +
  MUC cache), `completion_booker` (core-API wrapper, Lesart A), `task/*`, `event/*`,
  `privacy/provider` (null provider).
- `lang/en` + `lang/de`.
- Tests: `scope_resolver_test`, `observer_test` (event → scope → ad-hoc task),
  `completion_booker_test` (guards), `privacy_test`, generator, Behat settings feature.
- Infrastructure carried over and adapted from the reference plugin: `makefile`,
  `phpcs.xml`, `phpunit.xml`, `.phpcsignore`, `.gitattributes`, `.gitignore`,
  `tools/fix_phpdoc.php`, `tools/mustache_check.php`, and both GitHub Actions
  workflows — **with all catquiz/adaptivequiz/wunderbyte dependencies removed**
  (this plugin is dependency-free; `local_adele` is optional and runtime-detected).

## Open (Phase 2)
- Course-level aggregation-and-mark in `completion_booker::book()`
  (`completion_completion::mark_complete()`), version-pinned + integration-tested.
- `reconcile_task` implementation for date/duration criteria.

## CI matrix
Moodle 4.5 / 5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL; PHPUnit + Behat.
