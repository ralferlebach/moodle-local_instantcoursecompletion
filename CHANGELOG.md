# Changelog — local_instantcoursecompletion

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

## [0.2.2] - 2026-07-09

### Added
- Phase 2 Schritt 2: `reconcile_task::execute()` vollständig implementiert.
  - `eligible_course_ids()`: SCOPE_ALL → direkte DB-Query auf `course_completion_criteria`
    + `course`; SCOPE_CATEGORIES/ADELE → `scope_resolver::get_scope_course_ids()` + Filter.
  - `process_course(int $courseid)`: aktive Einschreibungen ohne `timecompleted` via
    LEFT JOIN; `completion_booker::book()` je Nutzer; Exception-Handling per Nutzer.
  - Tests: direkte `{enrol}`/`{user_enrolments}`-Insertion vermeidet `user_enrolment_created`;
    `resetDebugging()` nach `mark_complete()` unterdrückt `local_adele`-Observer-Debugging.

### Fixed (kein MINOR-Increment)
- `completion_booker::book()`: komplette Neufassung — `get_completions()` und Pass-1/Pass-2
  wurden durch direktes `$criterion->review($completion, false)` ersetzt.
  Hintergrund: `course_completion_criteria_completion` existiert in Moodle 4.5 nicht als
  eigenständige Tabelle. Die stabile API ist `review($completion, false)` (kein Schreiben
  von Criterion-Level-Records) + Aggregation der bool-Ergebnisse + `mark_complete()`.
- Tests `completion_booker_test`, `reconcile_task_test`: Activity-Kriterium +
  `update_state()` statt direkter Criterion-Completion-Tabellen-Insertions; `resetDebugging()`
  nach `mark_complete()` statt `getDebuggingMessages()` (letztere löscht den Puffer nicht).
- Makefile `phpunit`-Target: `util.php --diag` statt PHPUnit-Output-Scan — fängt Exit 135
  (outdated) UND Exit 140 (not initialised) korrekt ab.
- `moodle-ci.yml`: PHPUnit- und Behat-Matrix auf 5.1 + 5.2 erweitert; Behat-init-Schritt
  bedingt (`if [ -f admin/tool/behat/cli/init.php ]`) für Moodle 5.1/5.2-Kompatibilität.
- PHPCS-Variablennamen: Unterstriche entfernt (`$cached_completions` → `$cachedcompletions`,
  `$type_satisfied` → `$typesatisfied`, `$all_met` → `$allmet`).
- `$plugin->supported = [405, 502]` (Range, 2 Elemente) statt Liste — Moodle erwartet
  genau `[min, max]`; Liste verursachte `coding_exception` beim PHPUnit-Init.

## [0.2.1] - 2026-07-09

### Added
- Phase 2 Schritt 1: `completion_booker::book()` mit Kursebenen-Aggregation.
- Integration-Tests: guard-2-Test (already-complete), Phase-2 satisfied/not-met.
- Session-Konvention: `session-NNN.md` mit Bindestrich.
- Blueprint v3.0: §0.1, CI-Matrix Kap. 12, Phase-2-Status, supported range.

### Fixed
- `$plugin->supported` auf `[405, 502]` korrigiert.
- Variablennamen ohne Unterstriche (Moodle PHPCS).
- `docs/sessions/session-001.md` Naming-Fix (Bindestrich).

## [0.2.0] - 2026-07-09

### Changed
- CI-Matrix auf Moodle 4.5 / 5.0 / 5.1 / 5.2 erweitert.
- PHP 8.1 für alle Moodle-5.x-Zweige ausgeschlossen.

### Documentation
- Blueprint v2.0 → v3.0 (vorbereitet).
- Session-Konvention, sessionstart.txt aktualisiert.

## [0.1.1] - 2026-07-08

### Fixed
- Lang-Dateien strikt alphabetisch (16 Warnungen bereinigt).
- Observer-PHPDoc auf konkrete Event-Typen gehoben.
- Unit-Tests ohne Einschreibungs-Events.

### Changed
- `handle_trigger()` → öffentlich `handle_completion_trigger()`; `reset_seen()` ergänzt.
- `phpunit.xml` entfernt (redundant).

### Verified
- CI-Pipeline grün: Moodle 4.5 / 5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL.

## [0.1.0]

### Added
- Initialer Stub: Observer, Scope-Resolver, Completion-Booker (Phase-1), Adhoc-Task,
  reconcile_task-Stub, Settings, Privacy, Lang (de/en), PHPUnit, Behat, CI-Workflows.
