# Changelog — local_instantcoursecompletion

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

## [0.2.2] - 2026-07-09

### Added
- Phase 2 Schritt 2: `reconcile_task::execute()` fully implemented.
  - `eligible_course_ids()`: for SCOPE_ALL, queries `course_completion_criteria`
    joined with `course` (enablecompletion=1) directly, since
    `scope_resolver::get_scope_course_ids()` returns an empty array in that mode.
    For SCOPE_CATEGORIES/SCOPE_ADELE, uses the cached resolver then filters by
    completion criteria.
  - `process_course(int $courseid)`: queries active enrolled users without a
    timecompleted record via a LEFT JOIN of `user_enrolments`, `enrol`, and
    `course_completions`; calls `completion_booker::book()` for each; per-user
    exceptions are caught and logged (DEBUG_DEVELOPER) so a single failure
    does not abort the pass.
  - Task is gated by the `reconcile_enabled` config key (off by default) and
    scheduled at minute 17 of every sixth hour (`17 */6 * * *`).
- `reconcile_task_test` with three tests:
  - `test_execute_exits_early_when_disabled` — default-off guard.
  - `test_execute_books_pending_completions` — criteria pre-satisfied → booked.
  - `test_execute_skips_users_with_criteria_not_met` — criteria not satisfied
    → no booking.
  All tests insert enrolment records directly into `{enrol}` /
  `{user_enrolments}` to avoid firing `user_enrolment_created` (which would
  activate other installed plugins' observers under PHPUnit).

## [0.2.1] - 2026-07-09

### Added
- Phase 2: `completion_booker::book()` now performs full course-level aggregation
  and calls `completion_completion::mark_complete()` when all configured criteria
  are satisfied. Pass 1 reviews each criterion; Pass 2 applies ALL/ANY aggregation
  per criteria type and overall. New log outcomes: `criteria-not-met`, `booked`.
- Three new integration tests in `completion_booker_test` (guard 2, Phase-2 ×2).
- Session-document naming convention: `session-NNN.md` (with dash).
- Blueprint v3.0: §0.1, CI-Matrix table (Kap. 12), Phase-2 status, supported range.

### Fixed
- `$plugin->supported` corrected to two-element range `[405, 502]`
  (Moodle requires exactly `[min, max]`; a four-element list caused a
  `coding_exception` during PHPUnit environment initialisation).
- Variable names in `completion_booker.php` changed to remove underscores
  (`$cached_completions` → `$cachedcompletions`, `$type_satisfied` →
  `$typesatisfied`, `$all_met` → `$allmet`) — Moodle PHPCS rule
  `NamingConventions.ValidVariableName`.

## [0.2.0] - 2026-07-09

### Changed
- Extended `$plugin->supported` to `[405, 500, 501, 502]` (later corrected in 0.2.1).
- CI dev matrix: added Moodle 5.1 and 5.2; PHP 8.1 excluded for all 5.x.
- CI release matrix: added include-rows for 5.1 and 5.2.

### Documentation
- Session convention: one Claude chat session = one session document
  (`docs/sessions/session-NNN.md`). Prior sub-documents merged into
  `docs/sessions/session-001.md`.
- `docs/prompt-templates/sessionstart.txt`: reflects new convention and matrix.
- Blueprint v2.0 → v3.0 (prep).

## [0.1.1] - 2026-07-08

### Fixed
- Language files reordered strictly alphabetically (16 lang warnings cleared).
- Observer PHPDoc parameters typed to concrete event classes (moodlecheck).
- Unit tests no longer trigger enrolment events; observer tests drive
  `handle_completion_trigger()` directly.

### Changed
- `handle_trigger()` → public `handle_completion_trigger()`; added `reset_seen()`.
- Removed redundant `phpunit.xml`.

### Added
- `docs/materials/` (Blueprint, compact), `docs/prompt-templates/`.

### Verified
- Full CI pipeline green: Moodle 4.5/5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL.

## [0.1.0]

### Added
- Initial plugin stub: event observers, scope resolver (all/categories/adele),
  completion booker (Phase-1 guard-only stub), async book_completion_task,
  reconcile_task stub, completion_booked event, null privacy provider, admin
  settings, English and German lang strings, PHPUnit tests, Behat settings feature,
  GitHub Actions CI (4.5/5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL).
