# Changelog — local_instantcoursecompletion

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

## [0.2.0] - 2026-07-09

### Changed
- Bumped plugin release to 0.2.0; version timestamp 2026070901.
- Extended `$plugin->supported` to `[405, 500, 501, 502]` (Moodle 4.5–5.2).
- CI dev matrix (`moodle-ci.yml`): added `MOODLE_501_STABLE` and
  `MOODLE_502_STABLE` to both PHPUnit and Behat jobs; PHP 8.1 excluded for
  all Moodle 5.x branches.
- CI release matrix (`moodle-release.yml`): added two include-rows each for
  Moodle 5.1 (PHP 8.2 + MariaDB, PHP 8.3 + pgsql) and 5.2 (PHP 8.2 + pgsql,
  PHP 8.3 + MariaDB).

### Documentation
- **Session convention established:** one Claude chat session = one session
  document (`docs/sessions/sessionNNN.md`). Prior sub-documents 001–003
  merged into a single `docs/sessions/session001.md`; old 002 and 003 deleted.
- `docs/prompt-templates/sessionstart.txt`: reflects new session convention,
  version scheme 0.2.x, and Moodle 4.5 / 5.0 / 5.1 / 5.2 matrix.
- `docs/materials/Lastenheft_Pflichtenheft_Blueprint.md` (v3.0): added §0.1
  (version convention), updated P8/CI-matrix to include 5.1 and 5.2, added
  Kap. 11 CI-Matrix table, fixed L-Q2 to "4.5 incl. 5.x bis 5.2".
- `docs/materials/Blueprint_kompakt.md`: fixed L-Q2 (was incorrectly "4.1–4.5"),
  updated to "4.5, incl. 5.x (tested: 4.5, 5.0, 5.1, 5.2)"; added
  session-convention note to status header.

## [0.1.1] - 2026-07-08

### Fixed
- Language files reordered strictly alphabetically with no interspersed comments
  (moodle.Files.LangFilesOrdering) — clears all 16 lang warnings.
- Observer callback PHPDoc: parameters now typed to the concrete event classes
  (`course_module_completion_updated`, `user_graded`) so documented and actual types
  match (fixes local_moodlecheck "incomplete parameters list").
- Unit tests no longer enrol users where enrolment is not needed; observer tests drive
  `observer::handle_completion_trigger()` directly instead of going through activity
  completion. This removes the dependency on other installed plugins' event observers
  (e.g. local_adele), which under PHPUnit raised an unexpected `debugging()` call from
  `require_phpunit_isolation()` when `user_enrolment_created` fired.

### Changed
- `observer::handle_trigger()` is now the public, directly testable
  `observer::handle_completion_trigger()`; added `observer::reset_seen()` to reset the
  per-request de-duplication registry (used by tests and long-running CLI).
- Removed the plugin-level `phpunit.xml` (redundant: Moodle auto-generates the
  `local_instantcoursecompletion_testsuite` in the root config during test init).

### Added
- `docs/materials/Lastenheft_Pflichtenheft_Blueprint.md` (extensive) and
  `docs/materials/Blueprint_kompakt.md`.
- `docs/prompt-templates/` (sessionstart, sessionende, planning prompt).
- `docs/sessions/session-003.md` (session close).

### Verified
- Full CI pipeline green: Moodle 4.5 / 5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL
  (phpcs 0/0, phpdoc, PHPUnit, Behat).

## [0.1.0]

### Added
- Initial plugin stub with full infrastructure: installs, upgrades and uninstalls
  cleanly on Moodle 4.5 and 5.0.
- Event observers (`db/events.php`, `classes/observer.php`) for
  `course_module_completion_updated` and `user_graded`, plus scope-cache
  invalidation observers for course/category/tag changes.
- `scope_resolver`: three scope modes — all courses, selected category branches
  (with sub-category resolution and include/exclude tag filters), and delegation
  to `local_adele` settings when that plugin is present. Resolved course-ID set is
  cached in a MUC application cache (`db/caches.php`) and invalidated on structural
  or settings changes.
- `completion_booker`: guarded wrapper around the core completion API
  (`completion_info`, criteria `review()`). Follows Lesart A — no new completion
  logic, only earlier/scoped evaluation of existing criteria.
- Asynchronous `book_completion_task` (deduplicated ad-hoc task) and an optional,
  off-by-default `reconcile_task` safety net (`db/tasks.php`).
- Optional `completion_booked` log event.
- Admin settings: scope mode, category branches, include/exclude tags, processing
  mode (async/sync), reconcile toggle, logging toggle. The `local_adele` scope
  option appears only when `local_adele` is installed.
- Null privacy provider (the plugin stores no personal data of its own).
- English and German language strings.
- PHPUnit tests: scope resolution (all/categories/tags/adele-fallback), observer
  plumbing (in-scope enqueues one task, out-of-scope enqueues none), booker guards,
  and privacy provider.
- Behat scenarios for the settings page.
- Test data generator (`tests/generator/lib.php`).
- Makefile mirroring the CI check suite; developer tools (`tools/`).
- GitHub Actions CI: `moodle-ci.yml` (dev branches) and `moodle-release.yml`
  (main branch), matrix Moodle 4.5 / 5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL,
  PHPUnit + Behat, no external plugin dependencies.

### Requirements
- Moodle 4.5+ (incl. 5.x). No backward compatibility with 4.1–4.4 (per L-Q2).

### Not yet implemented (planned)
- Phase 2: course-level aggregation-and-mark in `completion_booker::book()`
  (`completion_completion::mark_complete()`), pinned per Moodle version and covered
  by integration tests for each criteria aggregation method (ALL/ANY, per type).
- Phase 2: `reconcile_task` implementation for date/duration criteria.
- Optional admin report listing accelerated completions when logging is enabled.
