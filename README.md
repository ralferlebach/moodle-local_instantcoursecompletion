# Instant course completion (`local_instantcoursecompletion`)

Event-driven, scope-limited **immediate** course-completion evaluation for Moodle.

In Moodle, *activity* completion is updated instantly, but *course* completion is
aggregated by the scheduled task `\core\task\completion_regular_task` (cron) and
therefore lags behind — and that task recomputes site-wide. This plugin closes the
gap: it observes the events that can newly satisfy a course's completion criteria
and re-runs Moodle's own completion evaluation **immediately**, for the affected
user only, and **only within a configurable scope** (all courses, selected category
branches, or the courses `local_adele` already considers relevant).

> Design principle (Lesart A): this plugin adds **no new completion logic**. It only
> triggers Moodle's existing completion API earlier and in a scoped way. Course
> completion still requires completion tracking and criteria to be configured in the
> course as usual.

## Requirements

- Moodle **4.5** or higher, including **5.x**. (No support for 4.1–4.4.)
- PHP 8.1+ (PHP 8.2+ on Moodle 5.0).
- No external plugin dependencies. Integration with `local_adele` is **optional**
  and detected at runtime.

## What it does

1. **Observes** completion-relevant events
   (`\core\event\course_module_completion_updated`, `\core\event\user_graded`).
2. **Checks scope** with a cached lookup (the only real work on the request path).
3. **Books** the completion off the request via a deduplicated ad-hoc task
   (default), or synchronously if configured. Booking uses the core completion API
   (`completion_info`, criteria `review()`); the site cron remains the safety net.

## Configuration

*Site administration ▸ Plugins ▸ Local plugins ▸ Instant course completion*

| Setting | Purpose |
| --- | --- |
| Observer scope | `All courses` / `Selected category branches` / `Use local_adele settings` (last option only when `local_adele` is installed). |
| Category branches | Categories (incl. sub-categories) in scope — for the *categories* scope. |
| Included / excluded course tags | Optional tag filters for the *categories* scope. |
| Processing mode | `Asynchronous` (ad-hoc task, recommended) or `Synchronous` (in the request). |
| Safety-net reconcile task | Optional periodic pass for criteria events cannot detect. Off by default. |
| Enable logging | Emit a trace line on evaluation/booking. Off by default. |

## Performance

The synchronous request path performs only a cached scope lookup and, at most,
enqueues one deduplicated ad-hoc task per `(course, user)`; all heavy evaluation
runs in cron. No site-wide computation is added.

## Development

```bash
make check     # phpcs + phpdoc + mustache + phpunit
make fix       # auto-fix code style and PHPDoc
make phpunit   # run the plugin test suite
```

CI runs `moodle-plugin-ci` across Moodle 4.5 / 5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL
(PHPUnit + Behat) on every branch.

## Status

Alpha stub with full infrastructure. The scope resolver, observer plumbing and
ad-hoc task are functional; the final course-level aggregation-and-mark step is a
documented Phase-2 item (version-pinned + integration-tested) — see
`classes/completion_booker.php` and `CHANGELOG.md`.

## License

GNU GPL v3 or later.
