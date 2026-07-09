# Instant course completion (`local_instantcoursecompletion`)

Event-driven, scope-limited **immediate** course-completion evaluation for Moodle.

In Moodle, *activity* completion is updated instantly, but *course* completion is
aggregated by the scheduled task `\core\task\completion_regular_task` (cron) and
therefore lags behind. This plugin closes the gap: it observes the events that can
newly satisfy a course's completion criteria and re-runs Moodle's own completion
evaluation **immediately**, for the affected user only, and **only within a
configurable scope** (all courses, selected category branches, or the courses
`local_adele` already considers relevant).

> Design principle (Lesart A): this plugin adds **no new completion logic**. It only
> triggers Moodle's existing completion API earlier and in a scoped way. Course
> completion still requires completion tracking and criteria to be configured in the
> course as usual.

## Requirements

- Moodle **4.5** or higher, including **5.x** up to 5.2. (No support for 4.1–4.4.)
- PHP 8.2+ (PHP 8.3+ required by Moodle 5.1+).
- No external plugin dependencies. Integration with `local_adele` is **optional**
  and detected at runtime.

## What it does

1. **Observes** completion-relevant events
   (`\core\event\course_module_completion_updated`, `\core\event\user_graded`).
2. **Checks scope** with a cached lookup — the only real work on the request path.
3. **Books** the completion off the request via a deduplicated ad-hoc task
   (default), or synchronously if configured. Booking uses the core completion API
   (`completion_info`, criteria `review()`); the scheduled cron task remains the
   safety net for any completions this plugin misses.

## Configuration

*Site administration ▸ Plugins ▸ Local plugins ▸ Instant course completion*

| Setting | Purpose |
| --- | --- |
| Observer scope | `All courses` / `Selected category branches` / `Use local_adele settings` (last option only when `local_adele` is installed). |
| Category branches | Categories (incl. sub-categories) in scope — for the *categories* scope. |
| Included / excluded course tags | Optional tag filters for the *categories* scope. |
| Processing mode | `Asynchronous` (ad-hoc task, recommended) or `Synchronous` (in the request). |
| Safety-net reconcile task | Optional periodic pass for criteria events cannot detect. Off by default. |
| Enable logging | Emit a trace line and fire a `completion_booked` logstore event on each booking. Off by default. |

## Admin report

When logging is enabled, every booked completion is written to the standard
logstore as a `completion_booked` event. The report at
*Site administration ▸ Reports ▸ Accelerated completions* lists the 100 most
recent events with course and user links.

## Performance

The synchronous request path performs only a cached scope lookup and, at most,
enqueues one deduplicated ad-hoc task per `(course, user)`. All heavy evaluation
runs in cron. No site-wide computation is added to the request path.

## Supported criterion types

Booking is attempted for all criterion types supported by Moodle's
`completion_criteria::review()`, including Activity, Grade, and Date criteria.
The plugin delegates the evaluation entirely to the core API and does not
re-implement any completion logic.

## CI matrix

| Moodle | PHP | DB |
| --- | --- | --- |
| 4.5 | 8.2, 8.3 | MariaDB 10.11, PostgreSQL 15 |
| 5.0 | 8.3, 8.4 | MariaDB 10.11, PostgreSQL 15 |
| 5.1 | 8.3, 8.4 | MariaDB 11.4, PostgreSQL 17 |
| 5.2 | 8.4, 8.5 | MariaDB 11.4, PostgreSQL 17 |

PHPUnit (19 tests) + Behat (2 scenarios) run on every push.

## Development

```bash
make check     # phpcs + phpdoc + mustache + phpunit
make fix       # auto-fix code style and PHPDoc
make phpunit   # run the plugin test suite
```

## Status

**Beta** — fully functional for production use with supervision. Phase 1 (observer
plumbing, scope resolution, ad-hoc task) and Phase 2 (criterion evaluation,
course-level aggregation, reconcile safety net) are complete and tested. Feedback
welcome via the GitHub issue tracker.

## License

GNU GPL v3 or later.
